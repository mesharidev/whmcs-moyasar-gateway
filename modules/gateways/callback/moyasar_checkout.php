<?php
/**
 * Moyasar - Standalone Payment Page
 *
 * @author    Meshari Alomari
 * @copyright Copyright (c) 2026 Meshari Alomari. All rights reserved.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';

$gatewayModuleName = 'moyasar';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module not activated');
}

$publishableKey   = $gatewayParams['publishableKey'];
$enableCreditCard = $gatewayParams['enableCreditCard'];
$enableApplePay   = $gatewayParams['enableApplePay'];
$enableStcPay     = $gatewayParams['enableStcPay'];
$enableSamsungPay = $gatewayParams['enableSamsungPay'];

$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
if (!$invoiceId) {
    die('Invalid invoice');
}

$invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);

// Get invoice details
$invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
if ($invoice['result'] !== 'success') {
    die('Invoice not found');
}

// Ensure the logged-in client owns this invoice
$sessionUserId = $_SESSION['uid'] ?? 0;
if (!$sessionUserId || (int) $invoice['userid'] !== (int) $sessionUserId) {
    $systemUrl = $gatewayParams['systemurl'];
    header('Location: ' . $systemUrl . 'clientarea.php');
    exit;
}

// Redirect if already paid
if ($invoice['status'] === 'Paid') {
    $systemUrl = $gatewayParams['systemurl'];
    header('Location: ' . $systemUrl . 'viewinvoice.php?id=' . $invoiceId);
    exit;
}

$amount      = (float) $invoice['total'];
$currency    = $invoice['currencycode'] ?? 'SAR';
$systemUrl   = $gatewayParams['systemurl'];
$companyName = $gatewayParams['companyname'] ?? 'Payment';

$amountInHalalas = (int) round($amount * 100);
$description     = 'Invoice #' . $invoiceId;
$callbackUrl     = $systemUrl . 'modules/gateways/callback/moyasar.php?invoice_id=' . $invoiceId;
$invoiceUrl      = $systemUrl . 'viewinvoice.php?id=' . $invoiceId;

$methodsArray = [];
if ($enableCreditCard === 'on') $methodsArray[] = 'creditcard';
if ($enableApplePay   === 'on') $methodsArray[] = 'applepay';
if ($enableStcPay     === 'on') $methodsArray[] = 'stcpay';
if ($enableSamsungPay === 'on') $methodsArray[] = 'samsungpay';
if (empty($methodsArray)) $methodsArray = ['creditcard'];

$methodsJson = json_encode($methodsArray);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Invoice #<?= $invoiceId ?> - <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="https://cdn.moyasar.com/mpf/1.14.0/moyasar.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #f4f6f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .payment-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            padding: 36px;
            width: 100%;
            max-width: 480px;
        }
        .payment-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .payment-header h2 {
            font-size: 20px;
            color: #1a1a2e;
            margin-bottom: 6px;
        }
        .payment-header .amount {
            font-size: 32px;
            font-weight: 700;
            color: #2563eb;
        }
        .payment-header .invoice-ref {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }
        .divider {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 0 0 24px;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #6b7280;
            font-size: 14px;
            text-decoration: none;
        }
        .back-link:hover { color: #374151; }
    </style>
</head>
<body>
    <div class="payment-card">
        <div class="payment-header">
            <h2><?= htmlspecialchars($companyName) ?></h2>
            <div class="amount"><?= number_format($amount, 2) ?> <?= htmlspecialchars($currency) ?></div>
            <div class="invoice-ref">Invoice #<?= $invoiceId ?></div>
        </div>
        <hr class="divider">
        <div class="mysr-form"></div>
        <a href="<?= htmlspecialchars($invoiceUrl) ?>" class="back-link">&larr; Back to Invoice</a>
    </div>

    <script src="https://cdn.moyasar.com/mpf/1.14.0/moyasar.js"></script>
    <script>
    Moyasar.init({
        element: '.mysr-form',
        amount: <?= $amountInHalalas ?>,
        currency: '<?= htmlspecialchars($currency) ?>',
        description: '<?= htmlspecialchars($description) ?>',
        publishable_api_key: '<?= htmlspecialchars($publishableKey) ?>',
        callback_url: '<?= htmlspecialchars($callbackUrl) ?>',
        methods: <?= $methodsJson ?>,
        metadata: {
            invoice_id: <?= $invoiceId ?>,
        },
    });
    </script>
</body>
</html>
