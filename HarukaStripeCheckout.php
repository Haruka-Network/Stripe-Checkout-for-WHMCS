<?php

use Stripe\StripeClient;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


function HarukaStripeCheckout_config() {
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
        )
    );
}

function HarukaStripeCheckout_link($params){
    try {
        $stripe = new Stripe\StripeClient($params['StripeSkLive']);

        $price = $stripe->prices->create([
            'currency' => $params['currency'],
            'metadata' => [
                'invoice_id' => $params['invoiceid'],
                'original_amount' => $params['amount']
            ],
            'product_data' => ['name' => "invoiceID: ".$params['invoiceid']],
            'unit_amount' => ceil($params['amount'] * 100.00),
          ]);

        $checkout = $stripe->checkout->sessions->create([
			'customer_email' => $params['clientdetails']['email'],
            'line_items' => [
              [
                'price' => $price->id,
                'quantity' => 1,
              ],
            ],
            'metadata' => [
                'invoice_id' => $params['invoiceid'],
                'original_amount' => $params['amount']
            ],
            'mode' => 'payment',
            'success_url' => $params['systemurl'] . 'modules/gateways/harukastripecheckout/result.php?order_id=' . $params['invoiceid'],
          ]);
		  
        #$a = json_encode($checkout);
        #return '<div class="alert alert-danger text-center" role="alert">'.$checkout.'</div>';
    } catch (Exception $e){
        return '<div class="alert alert-danger text-center" role="alert">支付网关错误，请联系客服进行处理</div>';
    }
    if ($checkout->payment_status == 'unpaid') {
        return '<form action="'.$checkout['url'].'" method="get"><input type="submit" class="btn btn-primary" value="'.$params['langpaynow'].'" /></form>';
    }
    return '<div class="alert alert-danger text-center" role="alert">发生错误，请创建工单联系客服处理</div>';
}
