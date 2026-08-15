**English** | [简体中文](README_ZH.md)

# Stripe Checkout for WHMCS

A third-party payment gateway module that accepts payments in WHMCS through the Stripe-hosted Checkout page.

Clicking the pay button on an invoice takes the customer to the Stripe Checkout page, offering every payment method your Stripe account has enabled for Checkout (cards, wallets, bank transfers, ...). The invoice amount is converted into your configured Stripe presentment currency using a selectable exchange-rate source; payments are credited automatically by a signature-verified webhook; partial refunds are supported with configurable fixed and percentage refund fees.

## Features

- **Payments**: Stripe-hosted Checkout page. Repeated clicks on the same invoice and amount resume the same Checkout Session via Stripe idempotency keys (~24h, matching the session lifetime), so duplicate sessions are never created; a changed amount starts a fresh session. No database dependency
- **Exchange rates**: multiple [data sources](https://github.com/DyAxy/NewExchangeRatesTable/tree/main/data) (Neutrino / Wise / Visa / UnionPay / Coinbase), fetched only when the customer clicks pay
- **Crediting**: webhooks are verified with the official SDK, then processed following the WHMCS callback conventions (invoice validation, duplicate transaction protection); asynchronous payment methods such as bank debits are supported via `checkout.session.async_payment_succeeded`
- **Refunds**: partial refunds supported; a fixed fee plus a percentage fee is withheld, and the remainder is refunded on Stripe proportionally to the captured charge
- **Currencies**: correct handling of zero-decimal currencies such as JPY and KRW, validation against Stripe minimum/maximum charge amounts
- **Performance**: invoice pages only render a payment button — Stripe and the rate source are contacted only after the customer clicks, so viewing an invoice no longer creates stray Checkout Sessions

## Directory layout

```
modules/gateways/
├── haruka_stripe_checkout.php            # Gateway entry (MetaData / config / link / refund)
├── callback/
│   └── haruka_stripe_checkout.php        # Stripe webhook endpoint
└── haruka_stripe_checkout/
    ├── pay.php                           # Payment redirect handler (pay button target)
    ├── whmcs.json                        # Module metadata read by the WHMCS admin
    ├── composer.json                     # Optional: standalone Stripe SDK install
    └── lib/                              # Module classes (HarukaStripeCheckout namespace)
```

## Installation

1. Download the latest zip from [Releases](https://github.com/Haruka-Network/Stripe-Checkout-for-WHMCS/releases) and extract it into your WHMCS root (or upload the repository's `modules/` directory, merging with the existing `modules/`).
2. Activate **Haruka Stripe Checkout** under System Settings → Payment Gateways.
3. Fill in the settings:

   | Setting | Description |
   | --- | --- |
   | Stripe Secret Key | `sk_live_...` from the Stripe Dashboard (`sk_test_...` for test mode) |
   | Webhook Signing Secret | The endpoint's `whsec_...` |
   | Presentment Currency | Currency charged on Stripe; must be supported by your Stripe account |
   | Exchange Rate Source | Data source for converting the invoice currency into the presentment currency |
   | Statement Descriptor | Full `statement_descriptor` override shown on the customer's statement (`{invoice}` is replaced with the invoice id), 5–22 characters, not sent when empty. ⚠ Newer Stripe accounts reject this parameter for card payments (older accounts are unaffected) — verify with a test card before enabling it alongside cards |
   | Refund Fixed / Percentage Fee | Fees withheld from refunds |

4. Add a webhook endpoint in the [Stripe Dashboard → Developers → Webhooks](https://dashboard.stripe.com/webhooks):

   - URL: `https://your-domain/modules/gateways/callback/haruka_stripe_checkout.php`
   - Events: `checkout.session.completed` and `checkout.session.async_payment_succeeded` (optionally add `checkout.session.async_payment_failed` and `charge.refunded` for logging)

The module uses the `stripe/stripe-php` SDK bundled with WHMCS — no extra install needed. If your environment lacks the SDK, run `composer install` inside `modules/gateways/haruka_stripe_checkout/`.

## Upgrading from v1.x

- Deploy the new directory layout over the old files (the deployed paths of the old repository-root `haruka_stripe_checkout.php` and `callback/` are unchanged). All setting names are preserved.
- The key fields are now `password` type (stored encrypted): **after upgrading, re-enter the Stripe secret key and the webhook signing secret once and save**.
- The presentment currency is now a dropdown instead of a text field: open the gateway settings once after upgrading, confirm the selected currency and save.
- v1.x created a Checkout Session every time an invoice page was viewed; sessions are now created/resumed only when the customer clicks the pay button. The callback URL is unchanged.
- The module now copies invoice details into the PaymentIntent metadata for proportional partial refunds; payments taken by v1.x lack that data, so refunds of those legacy transactions fall back to the v1.x requested-amount calculation.

## Debugging

- **Billing → Gateway Log**: webhook crediting, asynchronous payment failures, Stripe Dashboard refunds.
- **Configuration → System Logs → Module Log** (with Debug Logging enabled): a summary of every Stripe API call, with secrets redacted.

## Known limitations

- Refunds initiated manually in the Stripe Dashboard are only written to the Gateway Log; they are not recorded as WHMCS transactions (refunds initiated in WHMCS are recorded normally).
- The payment methods shown on the Checkout page are controlled by the Payment methods settings of your Stripe account; the module does not filter them.

## License

[MIT](LICENSE)
