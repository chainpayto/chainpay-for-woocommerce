=== ChainPay for WooCommerce ===
Contributors: chainpay
Tags: woocommerce, cryptocurrency, payment gateway, usdt, usdc, crypto, bitcoin, tron, bsc, polygon
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDT and USDC stablecoin payments on your WooCommerce store via ChainPay. TRON, BSC, Polygon. No KYC required.

== Description ==

**ChainPay for WooCommerce** turns your WooCommerce store into a crypto-ready shop in under 5 minutes. Your customers pay in USDT or USDC on TRON, BSC or Polygon; you get confirmations via webhook and funds settled to your own wallet.

### Highlights

* **Stablecoins only** — USDT / USDC, no price volatility at checkout.
* **Multi-chain** — TRON (lowest fees), BNB Smart Chain, Polygon.
* **Hosted checkout** — your customers are redirected to ChainPay's secure checkout page. No sensitive crypto data touches your server.
* **Instant confirmation** — webhook delivery fires the moment payment is confirmed on-chain.
* **Idempotent & replay-safe** — HMAC-SHA256 signed requests and webhooks.
* **No KYC** — set up with just an email.
* **Bilingual admin** — English / Simplified Chinese.

### How it works

1. Install and activate the plugin (requires WooCommerce 6.0+).
2. Sign up at [chainpay.to](https://chainpay.to) and generate your API Key / Webhook Secret.
3. Paste the keys into **WooCommerce → Settings → Payments → ChainPay**.
4. Your customers now see "Crypto (USDT/USDC)" at checkout.

Full install guide: [https://chainpay.to/integrations/wordpress](https://chainpay.to/integrations/wordpress)

### External services

This plugin connects to ChainPay's cloud API to create, query and receive status updates for payment orders:

* **API endpoint:** `https://api.chainpay.to` (configurable)
* **When:** whenever a customer selects "Crypto (USDT/USDC)" at checkout, and whenever ChainPay delivers a webhook back.
* **What's transmitted:** the WooCommerce order ID, total amount, currency, and your configured preferred chain/token.
* **Terms:** [https://chainpay.to/terms](https://chainpay.to/terms)
* **Privacy:** [https://chainpay.to/privacy](https://chainpay.to/privacy)

No customer PII (name, address, email) is sent to ChainPay.

== Installation ==

1. Upload the `chainpay-for-woocommerce` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce → Settings → Payments → ChainPay** to configure your API Key, API Secret and Webhook Secret.
4. Copy the webhook URL shown on the settings page and paste it into your ChainPay dashboard → Settings → Webhook URL.

== Frequently Asked Questions ==

= Do I need to run a wallet or blockchain node? =

No. ChainPay handles everything: address generation, on-chain confirmation monitoring, and settlement. You just receive the funds.

= Which currencies / chains are supported? =

USDT and USDC on TRON (TRC20), BNB Smart Chain (BEP20) and Polygon. More chains coming.

= How are refunds handled? =

Crypto refunds must be made on-chain. The plugin marks the WooCommerce order as refunded for bookkeeping; you initiate the actual on-chain transfer from your ChainPay dashboard.

= Is my WooCommerce store currency auto-converted to crypto? =

In MVP (0.1.x), ChainPay bills in USD-equivalents. We recommend configuring your store currency as USD. FX conversion is on the roadmap.

= What about price volatility? =

USDT and USDC are USD-pegged stablecoins — no volatility for the buyer at checkout. You're protected.

= Do I need KYC? =

No. ChainPay only needs a valid email to sign up.

== Screenshots ==

1. Settings page inside WooCommerce admin.
2. Checkout page with ChainPay as a payment option.
3. Hosted ChainPay cashier page where the customer scans and pays.
4. Webhook auto-updates the WooCommerce order to "Processing" once payment confirms.

== Changelog ==

= 0.1.0 =
* Initial release. WooCommerce payment gateway + signed webhook + HPOS compatibility.

== Upgrade Notice ==

= 0.1.0 =
First public release.
