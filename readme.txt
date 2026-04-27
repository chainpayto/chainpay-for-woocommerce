=== ChainPay for WooCommerce ===
Contributors: chainpay
Tags: woocommerce, payment gateway, cryptocurrency, usdt, usdc
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.0
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
* **Built-in sandbox + real-chain 0-fee testing** — test your full integration (signature, webhook, state machine) without spending a cent. One-click "Run end-to-end test" button verifies the entire loop.
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

== Test Integration ==

You can validate the full integration (signature, webhook delivery, order state machine) **without any real funds** in three modes:

= sandbox (off-chain, recommended) =

1. In your ChainPay merchant dashboard, switch to the **Test cp_test_** tab and create a `cp_test_xxx` key pair.
2. Paste it into the **API Key / API Secret** fields above.
3. Leave **Real-chain 0-fee test** un-checked.
4. Press **Run end-to-end test** — the plugin will:
   * Create a sandbox order on ChainPay
   * Trigger `simulate-paid` to fire a real `order.paid` webhook
   * Wait up to 36 seconds for that webhook to reach your site
   * Display ✓ / ✕ for each step

If all three steps go green, your store is ready for production — switch the keys to `cp_live_xxx` and you're live.

= live_test (real chain, 0 fees, capped) =

For a final rehearsal on the actual blockchain:

1. In your ChainPay dashboard, click **Request real-chain test** on your `cp_test_xxx` key.
2. Wait for admin approval (usually within 1 business day).
3. Tick the **Real-chain 0-fee test** checkbox here, save settings.
4. Place a real order from the storefront and pay with a small amount of USDT on TRON / BSC / Polygon.

Caps for live_test: ≤ 5 USDT per order, ≤ 20 orders per day, ≤ 500 orders lifetime per merchant.

= live (production) =

Switch back to your `cp_live_xxx` key and untick the test checkbox. **No code changes needed** — same plugin, same flow, just real fees and unlimited volume.

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

1. The hosted ChainPay checkout page — your customer scans the QR code and pays in USDT or USDC. EVM wallets (MetaMask, OKX, Trust Wallet, imToken) auto-fill the amount via EIP-681 deep link, no manual entry needed.

== Changelog ==

= 0.2.0 =
* New: Sandbox + real-chain 0-fee test integration. Auto-detects `cp_live_` vs `cp_test_` API key prefix and shows the current mode (LIVE / SANDBOX / LIVE TEST) in settings.
* New: One-click **Run end-to-end test** button. Creates a sandbox order, simulates payment and verifies webhook delivery to your site within seconds.
* New: Order meta `_chainpay_order_mode` and order notes are tagged `[SANDBOX]` / `[LIVE TEST]` so test orders don't get confused with real ones.
* New: Settings checkbox **Real-chain 0-fee test** to opt into `live_test` mode (requires admin approval; caps apply).

= 0.1.0 =
* Initial release. WooCommerce payment gateway + signed webhook + HPOS compatibility.

== Upgrade Notice ==

= 0.2.0 =
Adds full sandbox + live_test integration testing — try the new "Run end-to-end test" button on the settings page.

= 0.1.0 =
First public release.
