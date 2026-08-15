<?php
/**
 * Haruka Stripe Checkout for WHMCS
 *
 * Webhook endpoint logic. Follows the Stripe webhook guidance (verify the
 * signature against the raw body with the official library, acknowledge
 * fast, tolerate duplicate deliveries) and the WHMCS callback sequence
 * (validate invoice id, reject duplicate transaction ids, log, then apply
 * the payment).
 *
 * For synchronous payment methods (cards, wallets) the invoice is credited
 * on checkout.session.completed. Asynchronous methods (bank debits and
 * similar) complete the session with payment_status=unpaid and credit later
 * via checkout.session.async_payment_succeeded.
 *
 * @see https://docs.stripe.com/webhooks
 * @see https://docs.stripe.com/checkout/fulfillment
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 * @license MIT
 */

namespace HarukaStripeCheckout;

class WebhookController
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Handle one webhook delivery. Emits the HTTP response (status + body)
     * and returns nothing; designed to be the last call in the endpoint.
     *
     * @param string $payload   Raw request body
     * @param string $signature Stripe-Signature header value
     */
    public function handle($payload, $signature)
    {
        if ($this->config->webhookSecret() === '') {
            $this->respond(500, 'Webhook secret is not configured');
            return;
        }

        try {
            Autoloader::ensureStripeSdk();
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $this->config->webhookSecret());
        } catch (\UnexpectedValueException $e) {
            $this->respond(400, 'Invalid payload');
            return;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->respond(400, 'Invalid signature');
            return;
        } catch (\Exception $e) {
            Logger::transaction($this->config, $e->getMessage(), 'Webhook Error');
            $this->respond(500, 'Webhook processing error');
            return;
        }

        try {
            switch ($event->type) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    $this->onSessionPaid($event);
                    break;

                case 'checkout.session.async_payment_failed':
                    Logger::transaction($this->config, $this->eventSummary($event), 'Payment Failed');
                    $this->respond(200, 'Failure logged');
                    break;

                case 'charge.refunded':
                    // Refunds made from the Stripe Dashboard are logged for
                    // visibility; WHMCS-initiated refunds are already recorded
                    // by the refund function.
                    Logger::transaction($this->config, $this->eventSummary($event), 'Refund Notice');
                    $this->respond(200, 'Refund logged');
                    break;

                default:
                    $this->respond(200, 'Ignored event type: ' . $event->type);
            }
        } catch (\Exception $e) {
            Logger::transaction($this->config, $e->getMessage(), 'Webhook Error');
            // Non-2xx so Stripe retries transient failures.
            $this->respond(500, 'Webhook processing error');
        }
    }

    /**
     * Credit the invoice for a paid Checkout Session.
     *
     * @param \Stripe\Event $event
     */
    private function onSessionPaid($event)
    {
        $session = $event->data->object;

        if ($session->payment_status !== 'paid') {
            // Async payment method still pending: the invoice will be
            // credited by checkout.session.async_payment_succeeded.
            $this->respond(200, 'Awaiting asynchronous payment');
            return;
        }

        $invoiceId = (int) ($session->metadata[StripeGateway::META_INVOICE_ID] ?? 0);
        if ($invoiceId <= 0) {
            // Not created by this module (e.g. another integration on the
            // same account): acknowledge so Stripe stops retrying.
            $this->respond(200, 'No invoice_id metadata; ignored');
            return;
        }

        // The PaymentIntent id is the transaction id WHMCS stores; refunds
        // rely on it. Sessions in payment mode always carry one once paid.
        $transactionId = isset($session->payment_intent) && $session->payment_intent
            ? (string) $session->payment_intent
            : (string) $session->id;

        // Amount credited to the invoice, in the invoice's own currency.
        $originalAmount = (string) ($session->metadata[StripeGateway::META_ORIGINAL_AMOUNT] ?? '');
        if ($originalAmount === '' || !is_numeric($originalAmount)) {
            // Fall back to "full balance" semantics of addInvoicePayment.
            $originalAmount = '';
        }

        // Dies with output on an invalid invoice id or duplicate transaction,
        // which acknowledges the event with a 200.
        $invoiceId = checkCbInvoiceID($invoiceId, $this->config->displayName());
        checkCbTransID($transactionId);

        Logger::transaction($this->config, $this->eventSummary($event), 'Successful');

        addInvoicePayment(
            $invoiceId,
            $transactionId,
            $originalAmount,
            0,
            Config::MODULE_NAME
        );

        $this->respond(200, 'Payment recorded for invoice ' . $invoiceId);
    }

    /**
     * Compact event representation for the gateway log.
     *
     * @param \Stripe\Event $event
     * @return array
     */
    private function eventSummary($event)
    {
        $object = $event->data->object;

        return [
            'event_id' => $event->id,
            'event_type' => $event->type,
            'object_id' => isset($object->id) ? $object->id : null,
            'status' => isset($object->status) ? $object->status : null,
            'payment_status' => isset($object->payment_status) ? $object->payment_status : null,
            'amount_total' => isset($object->amount_total) ? $object->amount_total : null,
            'amount' => isset($object->amount) ? $object->amount : null,
            'currency' => isset($object->currency) ? $object->currency : null,
            'payment_intent' => isset($object->payment_intent) ? $object->payment_intent : null,
            'metadata' => isset($object->metadata) ? $object->metadata->toArray() : [],
        ];
    }

    /**
     * @param int    $status
     * @param string $body
     */
    private function respond($status, $body)
    {
        http_response_code($status);
        echo $body;
    }
}
