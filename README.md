# ChainPay for WooCommerce (developer README)

WooCommerce payment gateway plugin that accepts USDT/USDC via [ChainPay](https://chainpay.to). This file is for developers — the end-user install guide lives in [readme.txt](./readme.txt) (WP.org format).

## Layout

```
chainpay-for-woocommerce/
├── chainpay-for-woocommerce.php   ← plugin bootstrap
├── readme.txt                     ← WP.org listing
├── README.md                      ← this file
├── includes/
│   ├── class-chainpay-api-client.php     ← signed HTTP client (HMAC-SHA256)
│   ├── class-wc-gateway-chainpay.php     ← WC_Payment_Gateway subclass + admin settings
│   └── class-chainpay-webhook-handler.php ← /?wc-api=chainpay_webhook handler
├── assets/
│   └── chainpay-logo.svg                 ← gateway icon (24px wide at checkout)
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
zip -r chainpay-for-woocommerce-0.1.0.zip chainpay-for-woocommerce \
  -x 'chainpay-for-woocommerce/.DS_Store' \
  -x 'chainpay-for-woocommerce/README.md'
```

## Roadmap

- [ ] FX conversion (support store currency ≠ USD)
- [ ] Display payment status on WC order page with polling
- [ ] Block-based checkout support (WC 8.x)
- [ ] Submit to wordpress.org plugin directory (SVN)
- [ ] E2E tests with Playwright
