<?php
/**
 * Haruka Stripe Checkout - WHMCS Payment Gateway Module
 *
 * Accepts payments through the Stripe-hosted Checkout page, converting the
 * invoice amount into a configurable Stripe presentment currency using a
 * selectable exchange-rate source. Payment methods are whatever the Stripe
 * account has enabled for Checkout.
 *
 * Module layout:
 *   modules/gateways/haruka_stripe_checkout.php            this file (thin entry)
 *   modules/gateways/callback/haruka_stripe_checkout.php   Stripe webhook endpoint
 *   modules/gateways/haruka_stripe_checkout/pay.php        redirect handler (Pay button target)
 *   modules/gateways/haruka_stripe_checkout/lib/           module classes
 *
 * All functions are prefixed with the module filename per the WHMCS gateway
 * module conventions.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 * @see https://docs.stripe.com/payments/checkout
 * @license MIT
 */

use HarukaStripeCheckout\Autoloader;
use HarukaStripeCheckout\Config;
use HarukaStripeCheckout\Currency;
use HarukaStripeCheckout\GatewayException;
use HarukaStripeCheckout\Lang;
use HarukaStripeCheckout\StripeGateway;
use HarukaStripeCheckout\View;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Load the module's class library from the support directory.
 *
 * Guarded so an incomplete upload (a common install mistake) degrades into a
 * visible warning in the admin area instead of a fatal error that takes down
 * the whole payment gateway configuration page.
 *
 * @return bool Whether the support directory is present and loaded
 */
function haruka_stripe_checkout_bootstrap()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $autoloader = __DIR__ . '/haruka_stripe_checkout/lib/Autoloader.php';
    if (!is_file($autoloader)) {
        return $ready = false;
    }

    require_once $autoloader;
    Autoloader::register();

    return $ready = true;
}

/** Shown wherever the module cannot run because files are missing. */
define(
    'HARUKA_STRIPE_CHECKOUT_INCOMPLETE',
    'Haruka Stripe Checkout 安装不完整：缺少 modules/gateways/haruka_stripe_checkout/ 支持目录，请完整上传模块文件。'
);

/**
 * Define module related meta data.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function haruka_stripe_checkout_MetaData()
{
    return [
        'DisplayName' => 'Haruka Stripe Checkout',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Define gateway configuration options.
 *
 * Field system names are unchanged from v1.x so existing installations keep
 * their settings. Note the key fields are now "password" type: after
 * upgrading from v1.x, re-enter both secrets once and save.
 *
 * @see https://developers.whmcs.com/payment-gateways/configuration/
 *
 * @return array
 */
function haruka_stripe_checkout_config()
{
    if (!haruka_stripe_checkout_bootstrap()) {
        return [
            'FriendlyName' => [
                'Type' => 'System',
                'Value' => 'Haruka Stripe Checkout',
            ],
            'incompleteInstall' => [
                'FriendlyName' => '⚠ 安装不完整',
                'Type' => 'text',
                'Size' => '80',
                'Description' => HARUKA_STRIPE_CHECKOUT_INCOMPLETE,
            ],
        ];
    }

    $currencyOptions = [];
    $codes = array_keys(Currency::MINIMUM_AMOUNTS);
    sort($codes);
    foreach ($codes as $code) {
        $currencyOptions[$code] = $code;
    }

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Haruka Stripe Checkout',
        ],
        'StripeSkLive' => [
            'FriendlyName' => 'Stripe 密钥 (Secret Key)',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => '填写 Stripe 后台获取的 sk_live_...（测试环境可填 sk_test_...）',
        ],
        'StripeWebhookKey' => [
            'FriendlyName' => 'Webhook 签名密钥',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Webhook 端点的签名密钥 whsec_...，端点地址：/modules/gateways/callback/haruka_stripe_checkout.php，侦听事件 checkout.session.completed 与 checkout.session.async_payment_succeeded',
        ],
        'StripeCurrency' => [
            'FriendlyName' => '发起交易货币',
            'Type' => 'dropdown',
            'Options' => $currencyOptions,
            'Default' => 'USD',
            'Description' => '向 Stripe 发起交易使用的货币。账单金额将按所选汇率源换算成该货币，需为 Stripe 账户支持的交易货币',
        ],
        'ExchangeType' => [
            'FriendlyName' => '汇率数据源',
            'Type' => 'dropdown',
            'Options' => Config::EXCHANGE_SOURCES,
            'Default' => 'neutrino',
            'Description' => '汇率数据对比：https://github.com/DyAxy/NewExchangeRatesTable/tree/main/data',
        ],
        'StatementDescriptor' => [
            'FriendlyName' => '账单描述 (statement_descriptor)',
            'Type' => 'text',
            'Size' => '22',
            'Default' => '',
            'Description' => '完整覆盖账户默认的对账单描述，如 Invoice #{invoice}（{invoice} 会替换为账单号），5–22 个 ASCII 字符且需含字母，留空则不发送。⚠ 较新开通的 Stripe 账户对卡支付会拒绝此参数（较早开通的账户不受影响），启用卡支付时请先用测试卡验证；非卡渠道（支付宝 / 微信等）均可正常使用',
        ],
        'RefundFixed' => [
            'FriendlyName' => '退款扣除固定金额',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0.00',
            'Description' => '账单货币金额',
        ],
        'RefundPercent' => [
            'FriendlyName' => '退款扣除百分比',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0.00',
            'Description' => '%',
        ],
    ];
}

/**
 * Render the payment button on the invoice.
 *
 * No Stripe or exchange-rate calls happen here: the button posts the signed
 * invoice details to pay.php, which prepares the Checkout Session and
 * redirects the customer to the Stripe-hosted page. Invoice pages therefore
 * load fast and keep working when Stripe or the rate source is briefly
 * unreachable, and viewing an invoice no longer creates stray sessions.
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @param array $params Payment gateway module parameters
 * @return string HTML for the invoice payment area
 */
function haruka_stripe_checkout_link($params)
{
    if (!haruka_stripe_checkout_bootstrap()) {
        return '<div class="alert alert-danger text-center" role="alert">'
            . HARUKA_STRIPE_CHECKOUT_INCOMPLETE
            . '</div>';
    }

    $config = new Config($params);

    try {
        $config->assertUsable();
    } catch (GatewayException $e) {
        return View::alert(Lang::trans($e->getLangKey(), $e->getContext()), 'warning');
    }

    $invoiceId = (string) $params['invoiceid'];
    $amount = (string) $params['amount'];
    $currency = strtoupper((string) $params['currency']);

    return View::payForm(
        $config->systemUrl() . 'modules/gateways/haruka_stripe_checkout/pay.php',
        [
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $currency,
            'signature' => $config->signPayload($invoiceId, $amount, $currency),
        ],
        !empty($params['langpaynow']) ? $params['langpaynow'] : Lang::trans('pay_now')
    );
}

/**
 * Process a refund initiated from the WHMCS admin area.
 *
 * A fixed fee and a percentage fee (module settings) are withheld from the
 * requested amount; the remainder is refunded on Stripe proportionally to
 * the captured charge. Supports partial refunds.
 *
 * @see https://developers.whmcs.com/payment-gateways/refunds/
 *
 * @param array $params Payment gateway module parameters
 * @return array Refund status, raw response data, transaction id and fees
 */
function haruka_stripe_checkout_refund($params)
{
    if (!haruka_stripe_checkout_bootstrap()) {
        return [
            'status' => 'error',
            'rawdata' => HARUKA_STRIPE_CHECKOUT_INCOMPLETE,
            'transid' => isset($params['transid']) ? $params['transid'] : '',
            'fees' => 0,
        ];
    }

    $gateway = new StripeGateway(new Config($params));

    return $gateway->refund($params);
}
