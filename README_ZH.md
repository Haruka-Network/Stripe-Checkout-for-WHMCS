[English](README.md) | **简体中文**

# Stripe Checkout for WHMCS

通过 Stripe 托管的 Checkout 页面在 WHMCS 中收款的第三方网关模块。

点击账单上的支付按钮后跳转到 Stripe Checkout 收银台，支持你 Stripe 账户中为 Checkout 开启的全部支付方式（卡、钱包、银行转账等）。账单金额会按可选的汇率源换算为你配置的 Stripe 交易货币后发起支付；到账由验签后的 Webhook 自动入账；支持部分退款，并可配置固定与百分比退款手续费。

## 功能特性

- **支付**：Stripe 托管 Checkout 页面；同一账单同一金额重复点击时通过 Stripe 幂等键复用同一个 Checkout Session（约 24 小时，与 Session 有效期一致），不会产生重复订单，金额变化自动创建新 Session。无数据库依赖
- **汇率**：支持 Neutrino / Wise / Visa / 银联 / Coinbase 多个[数据源](https://github.com/DyAxy/NewExchangeRatesTable/tree/main/data)，仅在点击支付时请求
- **入账**：Webhook 使用官方 SDK 验签，按 WHMCS 回调规范校验账单与重复交易后入账；支持银行转账等异步支付方式（`checkout.session.async_payment_succeeded`）
- **退款**：支持部分退款；按「固定金额 + 百分比」扣除手续费后，按实际支付比例换算为 Stripe 货币退款
- **货币**：正确处理 JPY / KRW 等零小数货币，校验 Stripe 最小/最大收款金额
- **性能**：账单页只渲染支付按钮，点击后才请求 Stripe 与汇率源，浏览账单不再产生多余的 Checkout Session

## 目录结构

```
modules/gateways/
├── haruka_stripe_checkout.php            # 网关入口（MetaData / config / link / refund）
├── callback/
│   └── haruka_stripe_checkout.php        # Stripe Webhook 回调端点
└── haruka_stripe_checkout/
    ├── pay.php                           # 支付跳转处理（支付按钮的提交目标）
    ├── whmcs.json                        # WHMCS 后台读取的模块元数据
    ├── composer.json                     # 可选：独立安装 Stripe SDK
    └── lib/                              # 模块类库（HarukaStripeCheckout 命名空间）
```

## 安装

1. 从 [Releases](https://github.com/Haruka-Network/Stripe-Checkout-for-WHMCS/releases) 下载最新的 zip 包解压到 WHMCS 根目录（或直接上传仓库中的 `modules/` 目录，与现有 `modules/` 合并）。
2. WHMCS 后台 → System Settings → Payment Gateways 中激活 **Haruka Stripe Checkout**。
3. 填写配置：

   | 配置项 | 说明 |
   | --- | --- |
   | Stripe 密钥 | Stripe 后台的 `sk_live_...`（测试环境 `sk_test_...`） |
   | Webhook 签名密钥 | Webhook 端点的 `whsec_...` |
   | 发起交易货币 | 向 Stripe 请求使用的货币，需为 Stripe 账户支持的交易货币 |
   | 汇率数据源 | 账单货币 → 交易货币的换算数据源 |
   | 账单描述 | 完整覆盖账户默认的对账单描述（`statement_descriptor`，`{invoice}` 替换为账单号），5–22 字符，留空则不发送。⚠ 仅非卡渠道支持，启用卡支付时设置会被 Stripe 拒绝 |
   | 退款扣除固定金额 / 百分比 | 退款时预先扣除的手续费 |

4. 在 [Stripe Dashboard → Developers → Webhooks](https://dashboard.stripe.com/webhooks) 添加端点：

   - 地址：`https://你的域名/modules/gateways/callback/haruka_stripe_checkout.php`
   - 事件：`checkout.session.completed` 与 `checkout.session.async_payment_succeeded`（可选添加 `checkout.session.async_payment_failed`、`charge.refunded` 以便记录日志）

模块使用 WHMCS 自带的 `stripe/stripe-php` SDK，无需额外安装。若你的环境缺少该 SDK，在 `modules/gateways/haruka_stripe_checkout/` 下执行 `composer install` 即可。

## 从 v1.x 升级

- 直接用新目录结构覆盖旧文件（旧版仓库根目录的 `haruka_stripe_checkout.php` 与 `callback/` 对应部署路径不变），全部配置项名称保持不变。
- 密钥字段类型改为了 password（存储加密）：**升级后需在网关设置中重新填写 Stripe 密钥与 Webhook 签名密钥并保存一次**。
- 「发起交易货币」由文本框改为下拉框：升级后请到网关设置确认所选货币仍正确并保存。
- v1.x 在浏览账单页时就会创建 Checkout Session，现改为点击支付按钮后创建/复用，回调地址不变。
- 新版会把账单信息同时写入 PaymentIntent metadata 用于按比例部分退款；v1.x 收的款没有这份数据，对这些历史交易退款时沿用 v1.x 的按请求金额比例计算。

## 调试

- **Billing → Gateway Log**：Webhook 入账、异步支付失败、Stripe 后台退款等交易日志。
- **Configuration → System Logs → Module Log**（开启 Debug Logging 后）：每次 Stripe API 调用的摘要，密钥自动脱敏。

## 已知限制

- Stripe Dashboard 中手工发起的退款只会记录到 Gateway Log，不会自动写入 WHMCS 交易记录（在 WHMCS 中发起的退款会正常记录）。
- Checkout 页面上显示的支付方式由 Stripe 后台的 Payment methods 设置决定，模块不做筛选。

## License

[MIT](LICENSE)
