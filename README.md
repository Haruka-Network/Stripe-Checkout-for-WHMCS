# Stripe Checkout For WHMCS

支付：调用原版 Checkout 页面来完成用户支付，基于 WHMCS 使用货币
退款：直接拉取订单并发起对应金额的退款

开发 API 版本：2024-06-20，兼容至2024-12-18.acacia

侦听事件：`checkout.session.completed`

回调地址：`/modules/gateways/callback/haruka_stripe_checkout.php`