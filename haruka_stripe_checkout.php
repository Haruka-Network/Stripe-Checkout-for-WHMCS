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
        'StripeCurrency' => array(
            'FriendlyName' => '发起交易货币',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '默认获取WHMCS的货币，与您设置的发起交易货币进行汇率转换，再使用转换后的价格和货币向Stripe请求',
        ),
        'ExchangeType' => array(
            'FriendlyName' => '获取汇率源',
            'Type' => 'dropdown',
            'Options' => array(
                'neutrino' => '默认源',
                'wise' => 'Wise 源',
                'visa' => 'Visa 源',
                'unionpay' => '银联源',
            ),
            'Description' => '支持多种数据源，比较汇率：https://github.com/DyAxy/NewExchangeRatesTable/tree/main/data',
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
    $exchange = checkout_exchange($params['currency'], strtoupper($params['StripeCurrency']), strtolower($params['ExchangeType']));
    if (!$exchange) {
        return '<div class="alert alert-danger text-center" role="alert">支付汇率错误，请联系客服进行处理</div>';
    }

    // 验证支付金额是否满足最小要求
    $validation = validate_stripe_amount($params['amount'], $params['StripeCurrency'], $exchange);
    if (!$validation['valid']) {
        return '<div class="alert alert-warning text-center" role="alert">' . $validation['error'] . '</div>';
    }

    try {
        $stripe = new Stripe\StripeClient($params['StripeSkLive']);

        $checkout = $stripe->checkout->sessions->create([
            'customer_email' => $params['clientdetails']['email'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $params['StripeCurrency'],
                        'product_data' => ['name' => "invoiceID: " . $params['invoiceid']],
                        'unit_amount' => floor($params['amount'] * $exchange * 100.00),
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

function checkout_exchange($from, $to, $type)
{
    try {
        $url = 'https://raw.githubusercontent.com/DyAxy/NewExchangeRatesTable/main/data/' . $type . '.json';

        $result = file_get_contents($url, false);
        $result = json_decode($result, true);
        return $result['data'][strtoupper($to)] / $result['data'][strtoupper($from)];
    } catch (Exception $e) {
        echo "Exchange error: " . $e;
        return "Exchange error: " . $e;
    }
}

/**
 * 获取 Stripe 支持货币的最小收费金额表格
 * 基于 Stripe 官方文档: https://docs.stripe.com/currencies#minimum-and-maximum-charge-amounts
 * 金额已转换为最小货币单位（分）
 */
function stripe_minimum_amounts()
{
    return [
        'USD' => 50,      // $0.50
        'AED' => 200,     // 2.00 د.إ
        'AUD' => 50,      // $0.50
        'BGN' => 100,     // лв1.00
        'BRL' => 50,      // R$0.50
        'CAD' => 50,      // $0.50
        'CHF' => 50,      // 0.50 Fr
        'CZK' => 1500,    // 15.00Kč
        'DKK' => 250,     // 2.50 kr.
        'EUR' => 50,      // €0.50
        'GBP' => 30,      // £0.30
        'HKD' => 400,     // $4.00
        'HUF' => 17500,   // 175.00 Ft
        'INR' => 50,      // ₹0.50
        'JPY' => 50,      // ¥50 (零小数货币)
        'MXN' => 1000,    // $10
        'MYR' => 200,     // RM 2
        'NOK' => 300,     // 3.00 kr.
        'NZD' => 50,      // $0.50
        'PLN' => 200,     // 2.00 zł
        'RON' => 200,     // lei2.00
        'SEK' => 300,     // 3.00 kr.
        'SGD' => 50,      // $0.50
        'THB' => 1000,    // ฿10
    ];
}

/**
 * 验证支付金额是否满足最小要求
 * @param float $amount 金额
 * @param string $currency 货币代码
 * @param float $exchange 汇率
 * @return array 包含验证结果和错误信息
 */
function validate_stripe_amount($amount, $currency, $exchange)
{
    $minimumAmounts = stripe_minimum_amounts();
    $currencyUpper = strtoupper($currency);

    if (!isset($minimumAmounts[$currencyUpper])) {
        return [
            'valid' => false,
            'error' => "不支持的货币：{$currency}"
        ];
    }

    $convertedAmount = floor($amount * $exchange * 100);
    $minimumRequired = $minimumAmounts[$currencyUpper];

    if ($convertedAmount < $minimumRequired) {
        $minimumDisplay = number_format($minimumRequired / 100, 2);
        $currentDisplay = number_format($convertedAmount / 100, 2);

        return [
            'valid' => false,
            'error' => "支付金额过小。"
        ];
    }

    return ['valid' => true, 'error' => ''];
}
