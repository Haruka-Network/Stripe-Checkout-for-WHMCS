<?php
use Stripe\StripeClient;
use Stripe\Webhook;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayName = 'HarukaStripeAlipay';
$gatewayParams = getGatewayVariables("HarukaStripeCheckout");
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}
if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
    die("错误请求");
}

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
    $event = Webhook::constructEvent(
        $payload, $sig_header, $gatewayParams['StripeWebhookKey']
    );
} catch(\UnexpectedValueException $e) {
    logTransaction($gatewayParams['paymentmethod'], $e, 'StripeCheckout: Invalid payload');
    http_response_code(400);
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    logTransaction($gatewayParams['paymentmethod'], $e, 'StripeCheckout: Invalid signature');
    http_response_code(400);
    exit();
}

try {
    if ($event->type == 'checkout.session.completed') {
        $stripe = new Stripe\StripeClient($gatewayParams['StripeSkLive']);
        $sessionId = $event->data->object->id;
		
        $session = $stripe->checkout->sessions->retrieve($sessionId,[]);
		
        if ($session['payment_status'] == 'paid' && $session['status'] == 'complete') {
            $invoiceId = checkCbInvoiceID($session['metadata']['invoice_id'], $gatewayParams['paymentmethod']);
            $transId = $session['payment_intent'];
			checkCbTransID($transId);
            echo "Pass the checkCbTransID check\n";
            logTransaction($gatewayParams['paymentmethod'], $session, 'StripeCheckout: Callback successful');
            addInvoicePayment(
                $invoiceId,
                $transId,
                $session['metadata']['original_amount'],
                0,
                $params['paymentmethod']
            );
            echo "Success to addInvoicePayment\n";
            http_response_code(200);
        } else {
			echo json_encode($session);
			http_response_code(400);
		}
    }
    
    
} catch (Exception $e) {
    logTransaction($gatewayParams['paymentmethod'], $e, 'error-callback');
    http_response_code(400);
    echo $e;
}
