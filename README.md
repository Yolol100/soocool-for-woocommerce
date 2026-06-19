# SooCool for WooCommerce

Built by Webactueel.

SooCool for WooCommerce connects WooCommerce orders to the SooCool transport API.


## WordPress.org and external service disclosure

This plugin acts as an interface to the SooCool transport API. It can send WooCommerce order, address, contact, pickup and package data to SooCool when an authorized shop manager tests the connection, manually submits an order, downloads a label, or when automatic submission is explicitly enabled.

Official API hosts:

- Staging: `https://api.staging.soocool.nl`
- Production: `https://api.soocool.nl`

The API key is sent through the `X-API-Key` header and is masked in admin responses and logs. No unrelated third-party JavaScript, CSS, tracking pixels or advertising mechanisms are loaded by the plugin. Site owners remain responsible for disclosing SooCool as a transport/logistics processor in their own privacy policy where applicable.

Privacy and external-service details are documented in `readme.txt` under the external service section.

## Source and build route

This release package contains runtime PHP source, human-readable built admin assets, and production minified admin assets. The plugin loads `.min` admin assets by default and falls back to readable assets when `SCRIPT_DEBUG` is enabled. For public WordPress.org submission, publish the development repository or include the original source assets and build tooling so reviewers can reproduce `assets/build/admin.js`, `assets/build/admin.min.js`, `assets/build/admin.css`, and `assets/build/admin.min.css`.

## Features

- Prevents duplicate SooCool orders by checking `GET /order?orderReference=...` before creating a new order.
- Supports the documented order label endpoint, good-specific label endpoint, order-level bulk labels and stored-good-ID label downloads.
- Shows the SooCool test portal link in test mode without storing portal credentials in plugin files.

- WordPress admin settings under **SooCool**.
- SooCool API connection test via `/ping`.
- Manual WooCommerce order actions to send, refresh, update and cancel an order at SooCool.
- Optional automatic order submission by WooCommerce status.
- Optional pickup and delivery task support; delivery-only is the safe default.
- Fixed 08:00-18:00 SooCool delivery window for every delivery task; pickup windows remain configurable.
- SooCool order ID, reference, sync status and last error stored in WooCommerce order meta.
- Shipping label download from the WooCommerce order screen.
- HPOS compatible through WooCommerce custom order tables declaration.
- Secrets are never hardcoded, never returned through REST responses and are masked in logs.

## Requirements

- PHP 8.1+
- WordPress 6.5+
- WooCommerce 8.0+

## Local release checks

```bash
php -l soocool-for-woocommerce.php
php -l uninstall.php
find src -name "*.php" -print0 | xargs -0 -n1 php -l
node --check assets/build/admin.js
node --check assets/build/admin.min.js
node --check assets/admin/order-actions.js
node --check assets/admin/order-actions.min.js
node --check assets/frontend/checkout-delivery.js
node --check assets/frontend/checkout-delivery.min.js
```

This repository is a release package and does not currently include Composer, npm or CI configuration files. If you are working from a separate development repository that contains those files, run that repository's Composer, npm and build commands before copying generated assets into this package.

## Security notes

Do not commit API keys, portal passwords, `.env` files, production URLs with secrets, or exported logs containing customer data. Use the test environment first.

The webhook receiver requires the stored SooCool webhook token and, by default, HMAC verification headers. Configure SooCool to send `X-SooCool-Webhook-Token`, `X-SooCool-Webhook-Timestamp` and `X-SooCool-Webhook-Signature`. The signature is `hash_hmac('sha256', timestamp + '.' + raw_body, webhook_secret)` and may be sent as the hex digest or `sha256=<hex>`. `X-SooCool-Webhook-Id` is optional but recommended for duplicate-delivery protection. For legacy callback systems, a developer can explicitly disable required signatures with the `soocool_require_webhook_signature` filter and re-enable query-token URLs with the `soocool_allow_query_token_webhook_url` filter.

## Staging checklist

1. Install the plugin on staging.
2. Activate WooCommerce and enable HPOS in WooCommerce settings.
3. Open **SooCool**.
4. Enter the test API base URL and API key.
5. Keep pickup disabled for delivery-only testing. Enable pickup only after SooCool confirms pickup task support, then fill in the pickup address completely.
6. Save settings and run **Test connection**.
7. Create a test WooCommerce order with shipping address.
8. Use the order action **Send to SooCool**.
9. Confirm SooCool order ID appears in the SooCool order box.
10. In the SooCool test portal, confirm that delivery-only orders create one delivery task. If pickup is enabled, confirm the order creates one pickup task and one later delivery task.
11. Confirm the delivery task uses the fixed 08:00-18:00 delivery window.
12. Use **Refresh from SooCool** after the test order exists and confirm local status/tracking/good IDs update when SooCool returns them.
13. Download both A6 and Collated A4 labels only after SooCool accepted the order.
14. Test webhook success/failure, token rejection, HMAC rejection, expired timestamp rejection and duplicate-delivery rejection.
15. Keep automatic sync disabled until manual orders are accepted consistently.

## Quality checks

Run the available syntax checks in this release package before publishing:

```bash
php -l soocool-for-woocommerce.php
php -l uninstall.php
find src -name "*.php" -print0 | xargs -0 -n1 php -l
node --check assets/build/admin.js
node --check assets/build/admin.min.js
node --check assets/admin/order-actions.js
node --check assets/admin/order-actions.min.js
node --check assets/frontend/checkout-delivery.js
node --check assets/frontend/checkout-delivery.min.js
```

Use WooCommerce HPOS in staging and verify the manual order action, optional status hook, `/ping` connection test, delivery-only task creation, optional pickup plus delivery task creation, fixed 08:00-18:00 delivery window and PDF label downloads before enabling production credentials.


## Release note

Release builds should keep `assets/build` and the asset metadata aligned with the plugin version. If build tooling lives in a separate development repository, copy the generated assets and asset manifest into this release package together.


## Release quality gate

A production release should only be marked ready after Plugin Check, PHPCS/WPCS, HPOS tests and a SooCool staging order have passed. Static code review alone is not enough to prove the external API contract.

## Advanced security note: API key storage

By default, the SooCool API key is stored in `wp_options` with autoload disabled. For environments where secrets should not be stored in the database, define the key in `wp-config.php` instead:

```php
define( 'SOOCOOL_API_KEY', 'your-soocool-api-key' );
```

When this constant is present, it is used instead of the saved database value. Do not expose this value in logs, screenshots or exported diagnostics.

## Uninstall behavior

Removing the plugin deletes the `soocool_settings` and `soocool_logs` options. WooCommerce order meta such as SooCool order IDs, references, sync status and last errors is intentionally retained for historical order/audit continuity. Export or copy required diagnostics before uninstalling.

## Advanced security note: API host allow-list

SooCool API base URLs are restricted to the official SooCool hosts by default:

- `api.staging.soocool.nl`
- `api.soocool.nl`

Developers can allow an approved non-standard host in staging with the `soocool_allowed_api_hosts` filter. Do not use this for untrusted or user-provided hosts.

```php
add_filter('soocool_allowed_api_hosts', static function (array $hosts): array {
    $hosts[] = 'approved-staging-api.example.test';
    return $hosts;
});
```

## Final release QA checklist

Before production, record evidence for:

- PHP syntax checks for the plugin files.
- JavaScript syntax checks for bundled admin and checkout assets.
- Plugin Check and PHPCS/WPCS results when those tools are available in the release environment.
- HPOS-enabled order screen test.
- SooCool `/ping` test.
- Pickup-enabled test order in SooCool portal.
- Delivery task uses the fixed 08:00-18:00 delivery window.
- A6 and Collated A4 shipping label downloads.
- Safe API error handling and sanitized logs.


## SooCool API compatibility notes

This release uses the order-level shipping-label endpoint documented by SooCool:

- `GET /order/{orderId}/shipping-label`
- `output=a6` or `output=collated_a4`
- `Accept: application/pdf`
- `X-API-Key` authentication

The stored SooCool `orderId` must be a positive numeric ID returned by the SooCool order API. Invalid or legacy non-numeric values are not used for label downloads.

### OpenAPI schema coverage

The plugin is aligned with the supplied SooCool OpenAPI root specification for servers, authentication, order endpoints, numeric `orderId` handling, shipping labels and `/ping`.
The supplied root specification references external schema files including `models/order.json`, `requests/create-order.json`, `requests/update-order.json` and `responses/generic/*.json`. Those external files are not bundled in this release. Until those files are reviewed, the plugin enforces the confirmed contract minimums locally: an order reference, at least one delivery task, optional pickup before delivery, nested task `timeWindow`/`address`/`contactInfo`, task-level `goods` references, at least one root good with dimensions, weight and transport requirements, numeric SooCool order IDs and `ping: pong` for connection tests.


## API key handling

The admin settings screen never reveals the saved SooCool API key. When a key is stored, the password field shows masked dots. Saving settings without replacing the field preserves the existing key.

### Bulk label download hardening

Bulk WooCommerce label downloads are routed through a short-lived, single-use `admin-post.php` download token. The token stores the selected order IDs server-side for five minutes, is scoped to the current user, is protected by a nonce, and is deleted as soon as the download endpoint is used. This avoids streaming binary PDF output directly from the WooCommerce bulk-action filter while keeping the HPOS and legacy order list flows compatible.
