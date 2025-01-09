<?php

use Stripe\StripeClient;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function haruka_stripe_checkout_MetaData()
{
    return array(
        'DisplayName' => 'Haruka Stripe Checkout',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function haruka_stripe_checkout_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Haruka Stripe Checkout',
        ),
        'StripeSkLive' => array(
            'FriendlyName' => 'SK_LIVE 密钥',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '填写从Stripe获取到的密钥（SK_LIVE）',
        ),
        'StripeWebhookKey' => array(
            'FriendlyName' => 'Webhook 密钥',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '填写从Stripe获取到的Webhook密钥签名',
        ),
        'RefundFixed' => array(
            'FriendlyName' => '退款扣除固定金额',
            'Type' => 'text',
            'Size' => 30,
            'Default' => '0.00',
            'Description' => '$'
        ),
        'RefundPercent' => array(
            'FriendlyName' => '退款扣除百分比金额',
            'Type' => 'text',
            'Size' => 30,
            'Default' => '0.00',
            'Description' => '%'
        )
    );
}

function haruka_stripe_checkout_link($params)
{
    try {
        $stripe = new Stripe\StripeClient($params['StripeSkLive']);

        $checkout = $stripe->checkout->sessions->create([
            'customer_email' => $params['clientdetails']['email'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $params['currency'],
                        'product_data' => ['name' => "invoiceID: " . $params['invoiceid']],
                        'unit_amount' => ceil($params['amount'] * 100.00),
                    ],
                    'quantity' => 1
                ],
            ],
            'metadata' => [
                'invoice_id' => $params['invoiceid'],
                'original_amount' => $params['amount']
            ],
            'mode' => 'payment',
            'success_url' => $params['systemurl'] . 'viewinvoice.php?id=' . $params['invoiceid'],
        ]);
        if ($checkout->payment_status == 'unpaid') {
            return '<form action="' . $checkout['url'] . '" method="get"><input type="submit" class="btn btn-primary" value="' . $params['langpaynow'] . '" /></form>';
        }
    } catch (Exception $e) {
        return '<div class="alert alert-danger text-center" role="alert">支付网关错误，请联系客服进行处理</div>';
    }
    return '<div class="alert alert-danger text-center" role="alert">发生错误，请创建工单联系客服处理</div>';
}

function haruka_stripe_checkout_refund($params)
{
    $stripe = new Stripe\StripeClient($params['StripeSkLive']);
    $amount = round(($params['amount'] - $params['RefundFixed']) / ($params['RefundPercent'] / 100 + 1), 2) * 100;
    try {
        $responseData = $stripe->refunds->create([
            'payment_intent' => $params['transid'],
            'amount' => (int)$amount,
            'metadata' => [
                'invoice_id' => $params['invoiceid'],
                'original_amount' => $params['amount'],
            ]
        ]);
        return array(
            'status' => ($responseData->status === 'succeeded') ? 'success' : 'error',
            'rawdata' => $responseData,
            'transid' => $params['transid'],
            'fees' => $params['amount'],
        );
    } catch (Exception $e) {
        return array(
            'status' => 'error',
            'rawdata' => $e->getMessage(),
            'transid' => $params['transid'],
            'fees' => $params['amount'],
        );
    }
}
