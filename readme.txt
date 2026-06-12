=== SooCool for WooCommerce ===
Contributors: webactueel
Tags: woocommerce, shipping, logistics, transport, orders
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.49
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce orders with the SooCool transport API.

== Description ==

SooCool for WooCommerce lets authorized WooCommerce shop managers submit WooCommerce orders to the SooCool transport API, create pickup and delivery tasks, and download SooCool shipping labels from the WooCommerce order screen.

The plugin is intended for stores that use SooCool for transport and delivery operations. It does not send order data until the plugin is configured and an authorized shop manager manually submits an order, or automatic submission is explicitly enabled in the plugin settings.

Main features:

* WordPress admin settings screen under SooCool.
* SooCool API connection test using the documented `/ping` endpoint.
* Manual WooCommerce order action to submit an order to SooCool.
* Optional automatic order submission when an order reaches a configured WooCommerce status.
* Optional pickup plus delivery task support for collection workflows; delivery-only is the safe default.
* Fixed 08:00-18:00 delivery time window for delivery tasks, matching the confirmed SooCool connection requirements.
* Configurable pickup address and pickup time window.
* WooCommerce HPOS compatible order metadata handling.
* A6 and Collated A4 SooCool shipping label downloads.
* Bulk SooCool label download from the WooCommerce orders list.
* Sanitized activity logs and masked API key handling.

= External service: SooCool API =

This plugin connects to the SooCool API when an authorized shop manager tests the connection, submits an order, searches for an existing SooCool order by order reference, or downloads a shipping label.

Official API hosts used by the plugin:

* Staging: `https://api.staging.soocool.nl`
* Production: `https://api.soocool.nl`

The plugin sends the configured API key in the `X-API-Key` header. The API key is not intentionally exposed in the WordPress admin UI, REST responses, frontend markup or logs.

Data sent to SooCool can include WooCommerce order reference, billing/shipping name, address, country, email address, phone/mobile number, pickup address, package/goods description, pickup and delivery dates, pickup time window and the fixed 08:00-18:00 delivery time window.

Data is sent only for configured SooCool actions. No tracking, advertising or unrelated external assets are loaded by this plugin.

Please review SooCool's own service terms, data processing terms and privacy information before using the integration in production.

= Source and build notes =

This release package contains the runtime PHP source, readable built admin assets, production minified admin assets and documentation needed to install the plugin. The plugin loads `.min` admin assets by default and falls back to the readable `admin.js` and `admin.css` files when `SCRIPT_DEBUG` is enabled.

For WordPress.org submission, the development source repository and build tooling should be made publicly available or included with the submitted package, so reviewers can reproduce the built assets.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate this plugin.
3. Open the SooCool settings screen in the WordPress admin.
4. Choose the Test environment first.
5. Enter the SooCool API key or define `SOOCOOL_API_KEY` in `wp-config.php`.
6. Leave pickup disabled unless SooCool has confirmed pickup tasks for this account. If pickup is enabled, fill in the pickup address and pickup time window completely.
7. Save settings and run Test connection.
8. Create a WooCommerce test order and manually submit it to SooCool.
9. Confirm the order in the SooCool test portal before enabling automatic submission.

== Frequently Asked Questions ==

= Does the plugin send data automatically? =

Not until the integration is configured and either an authorized shop manager manually submits an order or automatic submission is enabled.

= Where should I store the API key? =

You can store it in the plugin settings. For stricter environments, define it in `wp-config.php`:

`define( 'SOOCOOL_API_KEY', 'your-soocool-api-key' );`

When the constant exists, it takes precedence over the saved database value.

= Does the plugin support WooCommerce HPOS? =

Yes. The plugin declares WooCommerce custom order table compatibility and uses WooCommerce order APIs instead of direct order postmeta queries.

= Which label formats are supported? =

The plugin supports the SooCool order-level shipping label formats `a6` and `collated_a4`. When SooCool returns positive good IDs in order responses, the WooCommerce order metabox can also offer a stored-good-label download through the documented `goodIds` label endpoint.

= How is the webhook secured? =

The webhook receiver requires the stored SooCool webhook token. The generated webhook URL no longer includes the token by default; configure SooCool to send the token via the `X-SooCool-Webhook-Token` header where supported.

For legacy callback systems that cannot send custom headers, a developer can explicitly re-enable query-token webhook URLs with the `soocool_allow_query_token_webhook_url` filter. Only use that fallback when header authentication is not available, because URL tokens may appear in logs, browser history, analytics or screenshots.

== Privacy ==

This plugin sends WooCommerce order, address, contact and package data to the SooCool API only when needed for configured transport operations. The plugin stores SooCool sync metadata on the WooCommerce order and stores sanitized activity logs in WordPress options.

The plugin does not load third-party JavaScript or CSS in the frontend, does not add tracking pixels, and does not intentionally send data to unrelated third-party services.

Site owners are responsible for disclosing the use of SooCool as a transport service in their own privacy policy where applicable.

== Uninstall ==

Removing the plugin deletes the `soocool_settings` and `soocool_logs` options. WooCommerce order meta such as SooCool order IDs, references, sync status and last errors is intentionally retained for historical order and audit continuity.

== Changelog ==

= 0.4.49 =

* Refreshed the admin CSS with scoped `.soocool-*` classes and shared CSS custom properties.
* Fixed stale README webhook and delivery-window documentation to match current plugin behavior.
* Kept the plugin version at 0.4.49 and aligned release metadata for the same-version release package.

= 0.4.30 =
* Fixed release packaging so the zip extracts to the canonical `soocool-for-woocommerce/` plugin folder instead of an internal working folder name.
* Corrected README delivery-window wording to match the fixed 08:00-18:00 delivery behavior.
* Aligned release metadata for 0.4.30.

= 0.4.29 =
* Fixed the WooCommerce bulk "Send to SooCool" action so selections above 50 orders fail with a clear admin notice instead of silently processing only the first 50 orders.

= 0.4.28 =
* Harden label download limits so bulk order-label downloads no longer silently truncate selections above 50 orders.
* Harden good-label downloads so requested `good_ids` are validated before any 50-item limit is applied.
* Bulk good-label downloads now fail clearly when selected orders contain more than 50 SooCool good IDs.

= 0.4.27 =
* Added an early runtime PHP-version guard before booting the service container, preventing fatal errors if the plugin remains active after a server is downgraded below PHP 8.1.

= 0.4.26 =
* Disabled unused unbound admin-post bulk label download requests; label downloads now use order-specific links or verified WooCommerce bulk actions.
* Removed unused notice-suppression bookkeeping.
* Release metadata and translation template coverage aligned for 0.4.26.

= 0.4.25 =
* Harden direct good-label downloads so requested good IDs must belong to the current WooCommerce order.
* Hide metabox label buttons for users without `manage_woocommerce`.

= 0.4.24 =
* Harden direct good-label links so requested good IDs are verified against the selected WooCommerce order.
* Add explicit label-download handler returns and sanitize REST validation messages consistently.

= 0.4.23 =
* Load SooCool admin CSS on WooCommerce HPOS and legacy order screens so order metabox buttons and order-list label links render with the intended compact styling.
* Keep the React/settings JavaScript limited to the SooCool settings page while allowing CSS on the manual test and order screens.

= 0.4.22 =
* Improved the SooCool order meta box label controls and added direct order-label and good-label links to the orders list.
* Split bulk label download into separate order-label and good-label actions and fixed the HPOS/legacy "link expired" redirect flow.

= 0.4.21 =
* Hardened webhook authentication defaults so generated URLs prefer the `X-SooCool-Webhook-Token` header and query-token URLs require an explicit fallback filter.
* Added no-store cache headers to webhook-token reveal/regeneration responses.

= 0.4.20 =
* Final release QA pass and metadata consistency cleanup.
