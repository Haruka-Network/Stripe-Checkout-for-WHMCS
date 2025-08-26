# Stripe Checkout For WHMCS

支付：调用原版 Checkout 页面来完成用户支付，支持自定义发起货币
退款：根据退款比例来转换为退款的当地货币金额

开发 API 版本：2024-06-20，兼容至2024-12-18.acacia

侦听事件：`checkout.session.completed`

回调地址：`/modules/gateways/callback/haruka_stripe_checkout.php`