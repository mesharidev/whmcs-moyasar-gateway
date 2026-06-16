<?php
/**
 * Moyasar Payment Gateway for WHMCS
 * API Docs: https://docs.moyasar.com/api/api-introduction
 *
 * @author    Meshari Alomari
 * @copyright Copyright (c) 2026 Meshari Alomari. All rights reserved.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function moyasar_MetaData()
{
    return [
        'DisplayName'                 => 'Moyasar',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

function moyasar_config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Moyasar',
        ],
        'publishableKey' => [
            'FriendlyName' => 'Publishable Key',
            'Type'         => 'text',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Starts with pk_live_ or pk_test_ (used in payment form)',
        ],
        'secretKey' => [
            'FriendlyName' => 'Secret Key',
            'Type'         => 'text',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Starts with sk_live_ or sk_test_ (used for verify & refund)',
        ],
        'enableCreditCard' => [
            'FriendlyName' => 'Credit Card',
            'Type'         => 'yesno',
            'Description'  => 'Enable Visa / Mastercard payments',
        ],
        'enableApplePay' => [
            'FriendlyName' => 'Apple Pay',
            'Type'         => 'yesno',
            'Description'  => 'Enable Apple Pay',
        ],
        'enableStcPay' => [
            'FriendlyName' => 'STC Pay',
            'Type'         => 'yesno',
            'Description'  => 'Enable STC Pay',
        ],
        'enableSamsungPay' => [
            'FriendlyName' => 'Samsung Pay',
            'Type'         => 'yesno',
            'Description'  => 'Enable Samsung Pay',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type'         => 'text',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Verification token from Moyasar Webhook settings',
        ],
        'buttonText' => [
            'FriendlyName' => 'Payment Button Text',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'Pay via Moyasar',
            'Description'  => 'Label shown on the payment button in the invoice page',
        ],
        'testMode' => [
            'FriendlyName' => 'Test Mode',
            'Type'         => 'yesno',
            'Description'  => 'Use test API keys (pk_test_ / sk_test_). Test mode is determined by the keys you enter above — this toggle is for your reference only.',
        ],
    ];
}

function moyasar_link($params)
{
    $invoiceId  = $params['invoiceid'];
    $systemUrl  = $params['systemurl'];
    $buttonText = !empty($params['buttonText']) ? $params['buttonText'] : 'Pay via Moyasar';

    $checkoutUrl = $systemUrl . 'modules/gateways/callback/moyasar_checkout.php?invoice_id=' . $invoiceId;

    return '<a href="' . $checkoutUrl . '" class="btn btn-primary btn-lg">' . htmlspecialchars($buttonText) . '</a>';
}

function moyasar_refund($params)
{
    $secretKey = $params['secretKey'] ?: $params['configoption2'];
    $paymentId = $params['transid'];

    $amount    = (float) $params['amount'];

    // Convert to smallest unit
    $amountInHalalas = (int) round($amount * 100);

    $payload = [];
    if ($amountInHalalas > 0) {
        $payload['amount'] = $amountInHalalas;
    }

    $response = moyasar_apiRequest($secretKey, 'payments/' . $paymentId . '/refund', 'POST', $payload);

    if (isset($response['status']) && in_array($response['status'], ['refunded', 'paid'])) {
        return [
            'status'  => 'success',
            'rawdata' => $response,
            'transid' => $response['id'],
        ];
    }

    return [
        'status'  => 'declined',
        'rawdata' => $response,
        'error'   => $response['message'] ?? 'Refund failed',
    ];
}

// ─── HTTP Helper ──────────────────────────────────────────────────────────────

function moyasar_apiRequest($secretKey, $endpoint, $method = 'GET', array $payload = [])
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

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $result = curl_exec($ch);
    curl_close($ch);

    return json_decode($result, true) ?: [];
}
