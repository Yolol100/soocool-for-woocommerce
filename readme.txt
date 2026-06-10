=== SooCool for WooCommerce ===
Contributors: webactueel
Tags: woocommerce, shipping, logistics, transport, orders
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.41
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
* Pickup plus delivery task support for collection workflows.
* Fixed delivery window of `08:00-18:00` for delivery tasks.
* Configurable pickup address and pickup time window.
* WooCommerce HPOS compatible order metadata handling.
* A6 and Collated A4 SooCool shipping label downloads.
* Sanitized activity logs and masked API key handling.

= External service: SooCool API =

This plugin connects to the SooCool API when an authorized shop manager tests the connection, submits an order, updates/cancels a SooCool order, or downloads a shipping label.

Official API hosts used by the plugin:

* Staging: `https://api.staging.soocool.nl`
* Production: `https://api.soocool.nl`

The plugin sends the configured API key in the `X-API-Key` header. The API key is not intentionally exposed in the WordPress admin UI, REST responses, frontend markup or logs.

Data sent to SooCool can include WooCommerce order reference, billing/shipping name, address, country, email address, phone number, pickup address, package/goods description, pickup and delivery dates, pickup time window and the fixed delivery time window.

Data is sent only for configured SooCool actions. No tracking, advertising or unrelated external assets are loaded by this plugin.

Please review SooCool's own service terms, data processing terms and privacy information before using the integration in production.

= Source and build notes =

This release package contains the runtime PHP source, built admin assets and documentation needed to install the plugin. The admin JavaScript and CSS in `assets/build` are human-readable release assets.

For WordPress.org submission, the development source repository and build tooling should be made publicly available or included with the submitted package, so reviewers can reproduce the built assets.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate this plugin.
3. Open the SooCool settings screen in the WordPress admin.
4. Choose the Test environment first.
5. Enter the SooCool API key or define `SOOCOOL_API_KEY` in `wp-config.php`.
6. Fill in the pickup address and pickup time window.
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

The plugin supports the SooCool order-level shipping label formats `a6` and `collated_a4`.

== Privacy ==

This plugin sends WooCommerce order, address, contact and package data to the SooCool API only when needed for configured transport operations. The plugin stores SooCool sync metadata on the WooCommerce order and stores sanitized activity logs in WordPress options.

The plugin does not load third-party JavaScript or CSS in the frontend, does not add tracking pixels, and does not intentionally send data to unrelated third-party services.

Site owners are responsible for disclosing the use of SooCool as a transport service in their own privacy policy where applicable.

== Uninstall ==

Removing the plugin deletes the `soocool_settings` and `soocool_logs` options. WooCommerce order meta such as SooCool order IDs, references, sync status and last errors is intentionally retained for historical order and audit continuity.

== Changelog ==

= 0.3.41 =
* Consolidated duplicate readme changelog and upgrade-notice entries.
* Bumped release metadata to 0.3.41 with no API, WooCommerce, HPOS, order sync, label or settings behavior changes.

= 0.3.40 =
* Show HTTP 200 `/ping` responses as a normal successful connection toast, even when the response body differs from the documented sample.
* Keep the non-sample ping body as internal/debug context only.

= 0.3.39 =
* Moved settings save/API-key status feedback out of inline page notices and into toast notifications only.

= 0.3.38 =
* Reject masked, redacted or corrupt API key values before sending requests to SooCool.
* Add safe API-key diagnostics: source, status, length, first/last four characters, host and header name.
* Show clear admin notices for saved, missing or invalid/masked API key state.

= 0.3.36 =
* Added orderReference preflight lookup before POST /order to prevent duplicate SooCool orders.
* Added client support for good-specific and multiple order label endpoints.
* Added safe SooCool test portal link in test mode without storing portal credentials in the plugin.
* Clarified that pickup flows send both pickup and delivery tasks.
* Normalized outgoing address, contact and goods fields before API submission.
* Kept authenticated HTTP 200 `/ping` responses successful while surfacing contract warnings for non-sample bodies.

= 0.3.32 =
* Set plugin author to Webactueel and packaged the release under the correct `soocool-for-woocommerce` slug.
* Fixed WordPress.org/Plugin Check release hygiene issues, including version metadata, textdomain packaging, production markdown exclusions and i18n template generation.
* Hardened public API error handling, log scrubbing and `/ping` contract validation.
* Added WordPress.org-style `readme.txt`.
* Added explicit external service and privacy disclosure.
* Added source/build route notes for public submission readiness.
* Clarified what customer/order data can be sent to the SooCool API.

= 0.3.31 =
* Improved connection-test UI messaging and API error extraction.
* Preserved HTTP 200 `/ping` as successful while surfacing contract warnings separately.
* Added settings validation for pickup/delivery date offsets and pickup time-window order.

== Upgrade Notice ==

= 0.3.41 =
Release metadata cleanup only. Re-test the SooCool staging API, HPOS order sync, duplicate orderReference handling and label downloads before production use.

= 0.3.40 =
Connection tests now show a simple success message for authenticated HTTP 200 `/ping` responses.

= 0.3.38 =
Re-save the SooCool API key after upgrading if the connection test previously returned a missing X-API-Key 401 error.

= 0.3.36 =
Re-test the SooCool staging API, HPOS order sync, duplicate orderReference handling and label downloads before production use.
