# ChainPay for WooCommerce (开发者文档)

> **语言**: [English](./README.md) · 简体中文

基于 [ChainPay](https://chainpay.to) 的 WooCommerce 支付网关插件，让你的 WordPress + WooCommerce 站点支持 USDT / USDC 稳定币收款。**本文面向开发者**；面向普通店主的安装说明在 [readme.txt](./readme.txt)（WordPress.org 官方格式）。

## 目录结构

```
chainpay-for-woocommerce/
├── chainpay-for-woocommerce.php   ← 插件入口
├── readme.txt                     ← WordPress.org 插件列表用
├── README.md                      ← 英文开发者说明
├── README.zh-CN.md                ← 本文件
├── includes/
│   ├── class-chainpay-api-client.php      ← HMAC-SHA256 签名 HTTP 客户端
│   ├── class-wc-gateway-chainpay.php      ← 支付网关类(继承 WC_Payment_Gateway) + 后台设置页
│   └── class-chainpay-webhook-handler.php ← /?wc-api=chainpay_webhook 回调处理
├── assets/
│   └── chainpay-logo.svg                  ← 收银台左侧显示的网关图标(24px 宽)
└── languages/
    ├── chainpay-for-woocommerce.pot       ← 翻译模板(用于生成新语言)
    └── chainpay-for-woocommerce-zh_CN.po  ← 简体中文翻译
```

## 整体架构

```
买家              WooCommerce          本插件                      ChainPay API
 │                   │                    │                             │
 ├────── 下单 ──────>│                    │                             │
 │                   │── process_payment ─>                             │
 │                   │                    ├── POST /v1/orders ─────────>│ (带签名)
 │                   │                    │<──── { checkout_url } ──────│
 │<───── 跳转到 checkout_url 收银台 ──────┤                             │
 │                   │                    │                             │
 │── 链上付款 ──────────────────────────────────────────────────────────>│ (链上监听)
 │                   │                    │<────── POST Webhook ────────│ (带签名,原始字节)
 │                   │                    │── 验签 → 订单标记已付款     │
 │                   │<── payment_complete ┤                             │
```

## 关键设计要点

* **`merchant_order_no = WC-<post_id>`**
  用 WooCommerce 订单的 post ID(不可变),**不用** `get_order_number()` —— 因为后者会被"连续订单号"之类的第三方插件改写,导致回调找不到订单。

* **签名算法与后端逐字节一致**
  见 `ChainPay_API_Client::sign_params()` 对应后端 `backend/src/utils/crypto.js::signParams`。
  重点:**只过滤 `null`,保留空字符串 `""`**;任何偏差(比如把空字符串也 trim 掉)都会让签名失败。

* **Webhook 验签用原始 body**
  收到回调后 **绝不** 先 `json_decode` 再 `json_encode` 再验签 —— 字段顺序、Unicode 转义都会变。必须拿 `php://input` 的原始字节串去算 HMAC。

* **HPOS 兼容**
  插件入口声明了 `custom_order_tables` 支持,兼容 WooCommerce 7.1+ 的高性能订单存储(HPOS)。

* **时间戳容差 5 分钟 + 幂等**
  Webhook 头部 `X-ChainPay-Timestamp` 与服务器时间差超过 300 秒会被拒,防重放攻击;同一订单的重复回调走幂等,不会重复发货。

## 本地开发

1. 用任意 WordPress 本地环境(LocalWP、Devilbox、docker-compose、宝塔都行)
2. 把本目录软链或克隆到 `wp-content/plugins/chainpay-for-woocommerce`
3. 装 WooCommerce 插件,激活这两个插件
4. 在 `WooCommerce → 设置 → 付款 → ChainPay` 填入 **测试环境** 的凭据(联系我们拿 dev 后端 API Key)
5. 在 ChainPay 商户后台把"Webhook 地址"设成 `https://你的域名/?wc-api=chainpay_webhook`
6. 下一个测试订单,走完整流程

### 本地调试 Webhook

公网回调本地要用隧道,推荐:

```bash
# cloudflared 零配置(免费)
cloudflared tunnel --url http://localhost:8080

# 或 ngrok
ngrok http 8080
```

把隧道给的 HTTPS URL(例:`https://xxx.trycloudflare.com/?wc-api=chainpay_webhook`)填到 ChainPay 后台。

### 查看日志

插件设置页打开 **"启用调试日志"** 后,所有请求/签名/回调都会记到:

```
wp-content/uploads/wc-logs/chainpay-*.log
```

在 WordPress 后台 `WooCommerce → 状态 → 日志` 也能直接查看。

## 打 Release zip

```bash
# 仓库根目录下
cd packages/wordpress-plugin
zip -r chainpay-for-woocommerce-0.1.0.zip chainpay-for-woocommerce \
  -x 'chainpay-for-woocommerce/.DS_Store' \
  -x 'chainpay-for-woocommerce/README.md' \
  -x 'chainpay-for-woocommerce/README.zh-CN.md'
```

Windows PowerShell 等价写法:

```powershell
cd packages\wordpress-plugin
Compress-Archive -Path chainpay-for-woocommerce -DestinationPath chainpay-for-woocommerce-0.1.0.zip -Force
```

> 说明:release zip 里通常不放两份 README.md(开发者文档),只保留 `readme.txt`(WordPress 后台要读这个文件显示插件信息)。

## 常见问题

### Q: 签名一直失败?

先排查这几点:
1. `apiSecret` 是否有空格或换行(从后台复制时容易带)
2. 服务器时间是否和标准时间同步(`X-Timestamp` 差 5 分钟就拒)
3. 请求 body 有没有被某个中间件(Cloudflare Worker、WAF)改动
4. 用插件设置页里"发送测试 Webhook"按钮快速定位问题

### Q: Webhook 收到了但订单没更新?

检查:
1. `wp-content/uploads/wc-logs/chainpay-*.log` 里有没有 "signature mismatch"
2. 订单的 `merchant_order_no` 是否是 `WC-<数字>` 格式
3. WooCommerce 订单页 meta 里有没有 `_chainpay_order_no`

### Q: HPOS 开启后有问题?

本插件已声明 HPOS 兼容,但如果你用了其他没声明兼容的插件,可能仍会有冲突。在 `WooCommerce → 设置 → 高级 → 订单存储` 切回 "WordPress posts 存储" 作为兜底。

## 发布计划

- [ ] 汇率转换(支持店铺本位币 ≠ USD 的场景)
- [ ] WC 订单页显示付款状态,前端轮询
- [ ] 支持 WooCommerce 8.x 的 Block-based Checkout(区块化结账)
- [ ] 提交到 wordpress.org 官方插件目录(走 SVN 流程)
- [ ] 用 Playwright 做端到端测试

## 贡献与反馈

- Issue / PR 提到本仓库:<https://github.com/chainpayto/chainpay-for-woocommerce>
- 商务合作 / 集成支持:<https://chainpay.to> 在线客服或后台 Telegram 绑定
