<?php
/**
 * Haruka Stripe Checkout for WHMCS
 *
 * Checkout Session creation and refunds, following the Stripe-hosted
 * Checkout flow:
 *
 *   create Checkout Session (mode: payment)
 *     -> redirect the customer to session.url (Stripe-hosted page)
 *     -> checkout.session.completed webhook credits the invoice
 *        (async payment methods credit on checkout.session.async_payment_succeeded)
 *
 * The module keeps no state of its own: repeated clicks are deduplicated by
 * a Stripe idempotency key derived from the request payload, whose ~24h
 * lifetime matches the default Checkout Session expiry, so an identical click
 * resumes the same session while any parameter change starts a new one.
 *
 * @see https://docs.stripe.com/payments/checkout/how-checkout-works
 * @see https://docs.stripe.com/api/checkout/sessions
 * @see https://docs.stripe.com/api/idempotent_requests
 * @license MIT
 */

namespace HarukaStripeCheckout;

class StripeGateway
{
    /** Metadata keys stored on the session and its PaymentIntent. */
    const META_INVOICE_ID = 'invoice_id';
    const META_ORIGINAL_AMOUNT = 'original_amount';
    const META_WHMCS_CURRENCY = 'whmcs_currency';
    const META_EXCHANGE_RATE = 'exchange_rate';

    /** @var Config */
    private $config;

    /** @var \Stripe\StripeClient|null */
    private $client;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return \Stripe\StripeClient
     * @throws GatewayException
     */
    private function client()
    {
        if ($this->client === null) {
            Autoloader::ensureStripeSdk();
            $this->client = new \Stripe\StripeClient($this->config->secretKey());
        }

        return $this->client;
    }

    /**
     * Resolve a click on the Pay button into the next step for the customer.
     *
     * @param int    $invoiceId
     * @param string $whmcsAmount   Amount due in the invoice currency ("xxx.xx")
     * @param string $whmcsCurrency Invoice currency code
     * @param string $customerEmail Pre-fills the email field on the Checkout page
     * @return array{action:string,url:string}
     * @throws GatewayException
     */
    public function preparePayment($invoiceId, $whmcsAmount, $whmcsCurrency, $customerEmail = '')
    {
        $this->config->assertUsable();

        $stripeCurrency = $this->config->stripeCurrency();
        $rate = ExchangeRates::rate($whmcsCurrency, $stripeCurrency, $this->config->exchangeSource());
        $minorAmount = Currency::toMinorUnits((float) $whmcsAmount * $rate, $stripeCurrency);
        Currency::assertChargeable($stripeCurrency, $minorAmount);

        $session = $this->createSession(
            $invoiceId,
            $whmcsAmount,
            $whmcsCurrency,
            $stripeCurrency,
            $minorAmount,
            $rate,
            $customerEmail
        );

        if (!empty($session->url)) {
            return ['action' => 'redirect', 'url' => (string) $session->url];
        }

        throw new GatewayException(
            sprintf('Checkout Session %s has no redirect URL for invoice %d.', $session->id, $invoiceId)
        );
    }

    /**
     * Process a refund initiated from the WHMCS admin area.
     *
     * @param array $params WHMCS refund parameters
     * @return array WHMCS refund response (status/rawdata/transid/fees)
     */
    public function refund(array $params)
    {
        $transactionId = (string) ($params['transid'] ?? '');

        try {
            $calculator = new RefundCalculator(
                (float) ($params['amount'] ?? 0),
                $this->config->refundFixedFee(),
                $this->config->refundPercentFee()
            );

            try {
                $intent = $this->request('PaymentIntent Retrieve', function () use ($transactionId) {
                    return $this->client()->paymentIntents->retrieve($transactionId, [
                        'expand' => ['latest_charge'],
                    ]);
                });
            } catch (GatewayException $expandError) {
                // Accounts pinned to a pre-2022-11-15 API version cannot expand
                // latest_charge; retry plain and skip the refunded-so-far check.
                $intent = $this->request('PaymentIntent Retrieve', function () use ($transactionId) {
                    return $this->client()->paymentIntents->retrieve($transactionId, []);
                });
            }

            $captured = (int) $intent->amount_received;
            if ($captured <= 0) {
                throw new GatewayException('No captured amount available to refund.');
            }

            $alreadyRefunded = 0;
            if (isset($intent->latest_charge) && is_object($intent->latest_charge)) {
                $alreadyRefunded = (int) $intent->latest_charge->amount_refunded;
            }
            $remaining = $captured - $alreadyRefunded;
            if ($remaining <= 0) {
                throw new GatewayException('The charge is already fully refunded on Stripe.');
            }

            $minorAmount = $calculator->stripeMinorAmount(
                $captured,
                (float) ($intent->metadata[self::META_ORIGINAL_AMOUNT] ?? 0)
            );
            // Clamp rounding overshoot; anything left over stays on Stripe.
            $minorAmount = min($minorAmount, $remaining);

            $refund = $this->request('Refund Create', function () use ($transactionId, $minorAmount, $params, $alreadyRefunded) {
                return $this->client()->refunds->create(
                    [
                        'payment_intent' => $transactionId,
                        'amount' => $minorAmount,
                        'metadata' => [
                            self::META_INVOICE_ID => (string) ($params['invoiceid'] ?? ''),
                            'whmcs_refund_amount' => (string) ($params['amount'] ?? ''),
                        ],
                    ],
                    [
                        // Distinct per remaining balance so an identical retry
                        // dedupes, while a second same-sized refund goes through.
                        'idempotency_key' => 'hsc-refund-' . md5($transactionId . '|' . $minorAmount . '|' . $alreadyRefunded),
                    ]
                );
            });

            $succeeded = in_array($refund->status, ['succeeded', 'pending'], true);

            return [
                'status' => $succeeded ? 'success' : 'declined',
                'rawdata' => $refund->toArray(),
                'transid' => $refund->id,
                'fees' => $calculator->fees(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'rawdata' => $e->getMessage(),
                'transid' => $transactionId,
                'fees' => 0,
            ];
        }
    }

    /**
     * @param int    $invoiceId
     * @param string $whmcsAmount
     * @param string $whmcsCurrency
     * @param string $stripeCurrency
     * @param int    $minorAmount
     * @param float  $rate
     * @param string $customerEmail
     * @return \Stripe\Checkout\Session
     */
    private function createSession(
        $invoiceId,
        $whmcsAmount,
        $whmcsCurrency,
        $stripeCurrency,
        $minorAmount,
        $rate,
        $customerEmail
    ) {
        $metadata = [
            self::META_INVOICE_ID => (string) $invoiceId,
            self::META_ORIGINAL_AMOUNT => (string) $whmcsAmount,
            self::META_WHMCS_CURRENCY => $whmcsCurrency,
            self::META_EXCHANGE_RATE => (string) $rate,
        ];
        $invoiceUrl = $this->config->systemUrl() . 'viewinvoice.php?id=' . (int) $invoiceId;

        $payload = [
            'mode' => 'payment',
            'client_reference_id' => (string) $invoiceId,
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($stripeCurrency),
                        'product_data' => ['name' => 'Invoice #' . $invoiceId],
                        'unit_amount' => $minorAmount,
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => $metadata,
            // Copy the metadata onto the PaymentIntent as well: the refund
            // path retrieves the intent by transaction id and needs
            // original_amount there (sessions do not propagate metadata).
            'payment_intent_data' => [
                'description' => 'Invoice #' . $invoiceId,
                'metadata' => $metadata,
            ],
            'success_url' => $invoiceUrl,
            'cancel_url' => $invoiceUrl,
        ];

        // Non-card charges only: Stripe rejects the session when this is set
        // while card payment methods are enabled (see Config).
        $descriptor = $this->config->statementDescriptor($invoiceId);
        if ($descriptor !== '') {
            $payload['payment_intent_data']['statement_descriptor'] = $descriptor;
        }
        if ($customerEmail !== '') {
            $payload['customer_email'] = $customerEmail;
        }

        // Key derived from the full payload: an identical click within ~24h
        // replays the original session instead of creating a duplicate, while
        // any parameter change (amount, exchange rate, email, descriptor)
        // makes a new key — Stripe rejects reusing a key with a different
        // payload, so deriving one from the other keeps them consistent.
        $idempotencyKey = 'hsc-cs-' . md5(json_encode($payload));

        return $this->request('Checkout Session Create', function () use ($payload, $idempotencyKey) {
            return $this->client()->checkout->sessions->create(
                $payload,
                ['idempotency_key' => $idempotencyKey]
            );
        });
    }

    /**
     * Run a Stripe API call with module-log instrumentation.
     *
     * @param string   $action
     * @param callable $call
     * @return mixed
     * @throws GatewayException on Stripe API errors
     */
    private function request($action, callable $call)
    {
        try {
            $result = $call();
            Logger::module($this->config, $action, '', $this->summarise($result));

            return $result;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::module($this->config, $action, '', 'ERROR: ' . $e->getMessage());
            throw new GatewayException($action . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Compact representation of an API resource for the module log.
     *
     * @param mixed $resource
     * @return mixed
     */
    private function summarise($resource)
    {
        if (is_object($resource) && method_exists($resource, 'toArray')) {
            $data = $resource->toArray();
            // The full object is large; keep the useful fields.
            $keys = [
                'id', 'object', 'status', 'payment_status', 'amount_total',
                'amount', 'currency', 'amount_received', 'payment_intent', 'metadata',
            ];
            $summary = array_intersect_key($data, array_flip($keys));

            return $summary ?: $data;
        }

        return $resource;
    }
}
