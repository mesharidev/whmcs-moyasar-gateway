<?php
/**
 * Moyasar Payment Gateway - Callback & Webhook Handler for WHMCS
 *
 * @author    Meshari Alomari
 * @copyright Copyright (c) 2026 Meshari Alomari. All rights reserved.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'moyasar';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module not activated');
}

$secretKey     = $gatewayParams['secretKey']     ?: $gatewayParams['configoption2'];
$webhookSecret = $gatewayParams['webhookSecret'] ?: $gatewayParams['configoption7'];
$action        = $_GET['action'] ?? '';

// ─── Webhook Handler ─────────────────────────────────────────────────────────
if ($action === 'webhook' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody, true);

    if (empty($payload)) {
        http_response_code(400);
        exit;
    }

    // Verify webhook secret token
    if ($webhookSecret) {
        $receivedSecret = $_SERVER['HTTP_MOYASAR_SECRET']
            ?? $_SERVER['HTTP_X_MOYASAR_SECRET']
            ?? ($_GET['secret'] ?? '');

        if ($receivedSecret !== $webhookSecret) {
            logTransaction($gatewayModuleName, $payload, 'Webhook: Invalid Secret');
            http_response_code(401);
            exit;
        }
    }

    $eventType = $payload['type']              ?? '';
    $paymentId = $payload['data']['id']        ?? '';
    $status    = $payload['data']['status']    ?? '';
    $amount    = $payload['data']['amount']    ?? 0;
    $invoiceId = 0;

    // Extract invoice_id from metadata or description fallback
    if (!empty($payload['data']['metadata']['invoice_id'])) {
        $invoiceId = (int) $payload['data']['metadata']['invoice_id'];
    } elseif (!empty($payload['data']['description'])) {
        preg_match('/Invoice #(\d+)/i', $payload['data']['description'], $matches);
        $invoiceId = (int) ($matches[1] ?? 0);
    }

    logTransaction($gatewayModuleName, $payload, 'Webhook: ' . $eventType);

    if ($eventType === 'PAYMENT_PAID' && $invoiceId && $paymentId) {
        $invoiceData = getInvoice($invoiceId);
        if ($invoiceData && $invoiceData['status'] !== 'Paid') {
            checkCbTransID($paymentId);
            addInvoicePayment($invoiceId, $paymentId, $amount / 100, 0, $gatewayModuleName);
        }
    }

    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

// ─── Redirect Callback (after customer pays) ─────────────────────────────────
$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
$paymentId = $_GET['id']     ?? '';
$status    = $_GET['status'] ?? '';

$invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);

if (!$paymentId) {
    logTransaction($gatewayModuleName, $_GET, 'Missing Payment ID');
    callback3DSecureRedirect($invoiceId, false);
    exit;
}

// Verify payment via API
$payment       = moyasar_cb_apiGet($secretKey, 'payments/' . $paymentId);
$paymentStatus = $payment['status'] ?? '';
$paymentAmount = $payment['amount'] ?? 0;

logTransaction($gatewayModuleName, $payment, 'Redirect Callback: ' . $paymentStatus);

if ($paymentStatus === 'paid') {
    checkCbTransID($paymentId);
    addInvoicePayment($invoiceId, $paymentId, $paymentAmount / 100, 0, $gatewayModuleName);
    callback3DSecureRedirect($invoiceId, true);
} else {
    callback3DSecureRedirect($invoiceId, false);
}

// ─── HTTP Helper ──────────────────────────────────────────────────────────────

function moyasar_cb_apiGet($secretKey, $endpoint)
{
    $url = 'https://api.moyasar.com/v1/' . $endpoint;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $secretKey . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    return json_decode($result, true) ?: [];
}
