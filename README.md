# ChainPay for WooCommerce (developer README)

> **Language**: English · [简体中文](./README.zh-CN.md)

WooCommerce payment gateway plugin that accepts USDT/USDC via [ChainPay](https://chainpay.to). This file is for developers — the end-user install guide lives in [readme.txt](./readme.txt) (WP.org format).

## Layout

```
chainpay-for-woocommerce/
├── chainpay-for-woocommerce.php   ← plugin bootstrap, asset enqueue
├── readme.txt                     ← WP.org listing
├── README.md                      ← this file
├── includes/
│   ├── class-chainpay-api-client.php     ← signed HTTP client (HMAC-SHA256, simulate-* helpers)
│   ├── class-wc-gateway-chainpay.php     ← WC_Payment_Gateway subclass + admin settings + mode badge
│   ├── class-chainpay-webhook-handler.php ← /?wc-api=chainpay_webhook handler + last-webhook transient
│   └── class-chainpay-test-runner.php    ← admin-ajax routes for "Run end-to-end test"
├── assets/
│   ├── chainpay-logo.svg                 ← gateway icon (24px wide at checkout)
│   └── js/
│       └── admin-test.js                 ← jQuery progress UI + webhook polling
└── languages/
    ├── chainpay-for-woocommerce.pot      ← strings template (to regenerate)
    └── chainpay-for-woocommerce-zh_CN.po ← Simplified Chinese
```

## Architecture

```
Customer          WooCommerce          This Plugin              ChainPay API
  │                   │                     │                         │
  ├──── checkout ─────>                     │                         │
  │                   │── process_payment ──>                         │
  │                   │                     ├── POST /v1/orders ─────>│ (signed)
  │                   │                     │<──── { checkout_url } ──│
  │<────── redirect to checkout_url ────────┤                         │
  │                   │                     │                         │
  │── pay on-chain ─────────────────────────────────────────────────> │ (chain watcher)
  │                   │                     │<─── POST webhook ───────│ (signed, raw body)
  │                   │                     │── verify sig, mark paid │
  │                   │<── payment_complete ┤                         │
```

Critical design choices:

* **merchant_order_no = `WC-<post_id>`** — uses the WC post ID (immutable), not `get_order_number()` (can be rewritten by sequential-number plugins).
* **Signature exactly matches backend** — see `ChainPay_API_Client::sign_params()` vs `backend/src/utils/crypto.js::signParams`. Any drift (e.g. trimming empty strings) breaks auth.
* **Webhook verifies raw body** — never `json_decode`/`json_encode` before verifying, or field order may change.
* **HPOS compatible** — declares `custom_order_tables` support for WooCommerce 7.1+.
* **Mode auto-detection** — the gateway detects `cp_live_` / `cp_test_` prefix at runtime (`get_key_mode()`) and automatically routes orders to live / sandbox / live_test. No global "test mode" toggle that's easy to leave on by accident.

## Sandbox / Live Test integration

See [`docs/SANDBOX_DESIGN.md`](../../../docs/SANDBOX_DESIGN.md) in the repo root for the full design.

The plugin supports all three modes the platform offers:

| Order mode  | Triggered when                                                        | On-chain | Fees |
|-------------|-----------------------------------------------------------------------|----------|------|
| `live`      | `api_key` starts with `cp_live_`                                      | Yes      | Std  |
| `sandbox`   | `api_key` starts with `cp_test_`, **Real-chain 0-fee test** unchecked | No       | 0    |
| `live_test` | `api_key` starts with `cp_test_`, checkbox checked, admin approved    | Yes      | 0    |

`process_payment()` reads `get_effective_order_mode()` and only adds `realChain: true` to the create-order body when `live_test` is the resolved mode.

### Self-test endpoint flow

`includes/class-chainpay-test-runner.php` exposes three admin-ajax actions (all gated by `manage_woocommerce` + nonce, and **refuse** `cp_live_` keys to prevent abuse):

```
admin-ajax.php?action=chainpay_test_create     → POST /v1/orders          (force sandbox)
admin-ajax.php?action=chainpay_test_simulate   → POST /v1/test/orders/:no/simulate-paid
admin-ajax.php?action=chainpay_test_check      → reads transient set by webhook handler
```

The webhook handler writes `chainpay_last_webhook` transient on each verified webhook; the check endpoint compares its `merchant_order_no` against the self-test order, plus a `received_at >= started_at` guard to ignore stale records.

## Local dev

1. Run `docker-compose -f docker/wp-dev/docker-compose.yml up` (see project root — compose file is optional).
2. Or use any LocalWP / Devilbox / LAMP stack.
3. Symlink this folder into `wp-content/plugins/chainpay-for-woocommerce`.
4. Install WooCommerce plugin + activate both.
5. Configure with **staging** ChainPay credentials (our dev backend).

## Build release zip

```bash
# From repo root
cd packages/wordpress-plugin
zip -r chainpay-for-woocommerce-0.2.0.zip chainpay-for-woocommerce \
  -x 'chainpay-for-woocommerce/.DS_Store' \
  -x 'chainpay-for-woocommerce/README.md' \
  -x 'chainpay-for-woocommerce/README.zh-CN.md'
```

## Roadmap

- [ ] FX conversion (support store currency ≠ USD)
- [ ] Display payment status on WC order page with polling
- [ ] Block-based checkout support (WC 8.x)
- [ ] Submit to wordpress.org plugin directory (SVN)
- [ ] E2E tests with Playwright
