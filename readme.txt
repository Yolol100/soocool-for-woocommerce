=== SooCool for WooCommerce ===
Contributors: webactueel
Tags: woocommerce, shipping, logistics, transport, orders
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.3.87
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
* Configurable delivery time window for delivery tasks.
* Configurable pickup address and pickup time window.
* WooCommerce HPOS compatible order metadata handling.
* A6 and Collated A4 SooCool shipping label downloads.
* Sanitized activity logs and masked API key handling.

= External service: SooCool API =

This plugin connects to the SooCool API when an authorized shop manager tests the connection, submits an order, searches for an existing SooCool order by order reference, or downloads a shipping label.

Official API hosts used by the plugin:

* Staging: `https://api.staging.soocool.nl`
* Production: `https://api.soocool.nl`

The plugin sends the configured API key in the `X-API-Key` header. The API key is not intentionally exposed in the WordPress admin UI, REST responses, frontend markup or logs.

Data sent to SooCool can include WooCommerce order reference, billing/shipping name, address, country, email address, phone number, pickup address, package/goods description, pickup and delivery dates, pickup time window and the configured delivery time window.

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

The plugin supports the SooCool order-level shipping label formats `a6` and `collated_a4`.

== Privacy ==

This plugin sends WooCommerce order, address, contact and package data to the SooCool API only when needed for configured transport operations. The plugin stores SooCool sync metadata on the WooCommerce order and stores sanitized activity logs in WordPress options.

The plugin does not load third-party JavaScript or CSS in the frontend, does not add tracking pixels, and does not intentionally send data to unrelated third-party services.

Site owners are responsible for disclosing the use of SooCool as a transport service in their own privacy policy where applicable.

== Uninstall ==

Removing the plugin deletes the `soocool_settings` and `soocool_logs` options. WooCommerce order meta such as SooCool order IDs, references, sync status and last errors is intentionally retained for historical order and audit continuity.

== Changelog ==

= 0.3.87 =
* Fixed duplicate dropdown-arrow rendering by consolidating the SooCool admin SelectControl arrow styling into one scoped mechanism.
* Rechecked PHP syntax, admin JS syntax, CSS parse, security greps and ZIP integrity after the patch.

= 0.3.86 =
* Release version bump to 0.3.86.
* Avoid storing manual API-Test form values in the transient response state because the response screen no longer reuses them and the fields may contain contact/address data.
* Rechecked PHP syntax, admin JS syntax, CSS parse and ZIP integrity after the patch.

= 0.3.85 =
* Restored visible dropdown arrows in the scoped SooCool admin UI without adding `!important` or broad WordPress admin overrides.

= 0.3.84 =
* Release-ready packaging: package now installs under the `soocool-for-woocommerce/` root folder.
* Release metadata aligned across plugin header, `SOOCOOL_VERSION`, assets, readme and POT.
* Clarified the external service description so it only describes active connection, order submission, order lookup and label download flows.
* Rechecked PHP syntax, admin JS syntax, CSS parse, REST/admin security greps, credential exposure, HPOS storage patterns and ZIP integrity.

= 0.3.83 =
* CSS/JS audit: removed unnecessary `!important` flags from scoped admin CSS.
* Consolidated duplicate select, full-width panel and card-layout overrides without changing plugin behavior.
* Rechecked PHP syntax, admin JS syntax, CSS parse, asset enqueue scope and ZIP integrity.

= 0.3.82 =
* Move the API-Test tab between Shipping labels and Activity logs.

= 0.3.81 =
* Cleanup: API-Test taskType is fixed to delivery in the UI, matching the single-task manual test and SooCool requirement for at least one delivery task.

= 0.3.80 =
* Style the API-Test Vorige button like the existing red SooCool primary buttons.

= 0.3.77 =
* Hide the API-Test form after a response and show a Vorige button to return to the form.

= 0.3.76 =
* Migrate legacy 09:00-17:00 test delivery windows to the SooCool-confirmed staging delivery window 08:00-18:00.
* Keep API keys and portal credentials out of plugin code; credentials remain setting-based.

= 0.3.75 =
* Styles the manual API-test page with the main SooCool admin design system.
* Removes the old fictitious WooCommerce test-order button from the React settings screen.

= 0.3.72 =
* Combines the manual API-contract files with the existing fixed plugin instead of blindly replacing working code.
* Keeps package width, depth, height and weight settings active as fallback values while preferring product dimensions/weight when available.
* Keeps manual API-test debug redaction active and uses settings-based default goods values in the manual test form.
* Keeps pickup/delivery date ordering safe when pickup is enabled, and adds mobile contact mapping/filter support for delivery tasks.
* Re-ran PHP syntax checks, targeted compatibility greps and ZIP integrity after the patch.

= 0.3.71 =
* Saves package width, depth, height and weight from the REST settings endpoint so configured package values are used in SooCool order payloads.
* Redacts personal/contact/address fields from manual API-test debug output before storing or rendering the transient result.
* Adds a short per-order sync lock around REST and WooCommerce order-action submissions to reduce duplicate create-order requests from concurrent triggers.
* Re-ran PHP syntax checks and ZIP integrity after the patch.

= 0.3.70 =
* Builds SooCool create-order payloads with nested task timeWindow/address/contactInfo and task-level goods ID references.
* Sends root goods with negative create-request goodId values, dimensions, weight and transportRequirements.
* Shows SooCool errors[] details in test-order and sync responses instead of only a generic rejection.
* Adds package dimension, weight and transport requirement settings; normalizes NL postcodes without spaces.
* Re-ran PHP syntax, JS syntax, payload harnesses, version consistency and ZIP integrity after the patch.

= 0.3.66 =
* Removes an unreachable duplicate return in API URL sanitizing.
* Makes direct option sanitizing fall back to the official SooCool hosts instead of preserving old/corrupt API URL values.
* Re-ran PHP syntax, JS syntax, REST/admin security, version consistency and ZIP integrity checks after the patch.

= 0.3.60 =
* Adds pre-sanitization REST settings validation so invalid pickup or delivery time windows are rejected instead of silently reset.
* Consolidates duplicate readme changelog and upgrade-notice entries.
* Updates release metadata after a full file-by-file audit pass.

= 0.3.59 =
* Hardens the manual API test contract check so Extra JSON must still leave at least one delivery task, and pickup tasks must start before delivery tasks.
* Updates stale documentation text about the delivery window.

= 0.3.58 =
* Prevents automatic retries for non-idempotent write requests such as POST /order to reduce duplicate-order risk on temporary gateway failures.
* Revalidates the final generated test-order payload after overriding the order reference.
* Includes the privacy and source/build disclosure files referenced from the README.
* Removes stale fixed-delivery-window wording from runtime documentation.

= 0.3.57 =
* Avoid WordPress local timestamp double-offset edge cases when building SooCool task dates and manual test defaults.
* Treats every `order_ids` label request as a bulk-label endpoint request, even when one ID is provided.
* Re-checks the generated SooCool test order reference after overriding the payload reference.
* Rechecked PHP syntax, admin JS syntax, ZIP integrity and release metadata after the patch.

= 0.3.55 =

* Fixed multiple-label requests so `orderIds` remains comma-separated as documented by SooCool.
* Rechecked PHP syntax, admin JS syntax, ZIP integrity and release metadata after the patch.

= 0.3.52 =

* Catches unexpected runtime failures in the REST order sync endpoint so admin UI calls return a safe error response instead of a fatal error.
* Catches unexpected runtime failures in the REST connection test endpoint.
* Requires `goods[].goodId` to be a non-zero integer; create-order payloads use negative IDs that are referenced by each task `goods` array.
* Removes query strings such as `orderReference` from API log path context.
* Keeps manual API test `taskType` limited to `delivery` or `pickup` after Extra JSON merging.

= 0.3.49 =

* Uses the configurable delivery time window in the admin UI and REST settings.
* Rejects invalid delivery windows where the end time is not later than the start time.
* Adds a configurable `packaging_type` setting for `goods[].packagingType`.
* Validates the final manual API-test payload after Extra JSON merging.

= 0.3.48 =

* Preserves configured delivery time windows instead of resetting them to 08:00-18:00 on save.
* Formats SooCool `startTime` and `endTime` with the WordPress site timezone offset instead of forcing UTC output.
* Rejects manual and automatic payloads where `endTime` is not later than `startTime`.
* Keeps the automatic WooCommerce order payload aligned with the SooCool `/order` contract: root `orderReference`, `tasks[]` and `goods[]`.

= 0.3.46 =

* Added a separate **SooCool > API-Test** admin page.
* Aligned the manual test payload with the documented SooCool `/order` shape: root `orderReference`, `tasks[]` and `goods[]`.
* Shows success/failure, HTTP status, API errors, sent payload and SooCool response after testing.

= 0.3.42 =

* Added a secured admin test-order flow for creating a fictitious WooCommerce order and sending it to the SooCool test API.
* Blocked test-order creation when Production is selected.
* Added duplicate-prevention to the REST order sync endpoint by checking SooCool order references before create.
* Removed API-key edge fragments from stored logs.

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

= 0.3.87 =
Dropdown-arrow polish update. Recheck the SooCool admin settings dropdowns after update.

= 0.3.86 =
Release polish update. Reinstall/update on staging first, then verify API-Test, WooCommerce order sync and label downloads.

= 0.3.85 =
Dropdown arrow visibility fix for the SooCool admin UI. Recheck key settings dropdowns after update.

= 0.3.83 =
Admin CSS/JS cleanup release. No API or WooCommerce behavior changes expected; visually recheck the SooCool admin tabs and API-Test page after update.

= 0.3.82 =
API-Test appears between Shipping labels and Activity logs in the SooCool tab navigation.

= 0.3.81 =
Safe cleanup release. API-Test remains a delivery-task staging tool; pickup + delivery is tested through normal WooCommerce order sync with pickup enabled.

= 0.3.80 =
The API-Test Vorige button now uses the same primary button styling as the rest of the plugin.

= 0.3.77 =
After an API-Test response, only the response/payload panels and a Vorige button are shown. Use Vorige to return to the editable form.

= 0.3.76 =
Delivery test windows saved by older builds as 09:00-17:00 are migrated to 08:00-18:00 for the SooCool staging agreement.

= 0.3.75 =
API-Test page polish: order reference field is full width and menu wording is shortened. The old fictitious WooCommerce test-order button was removed from the settings screen; use API-Test for staging validation.

= 0.3.72 =
Re-test staging order sync and the API-Test. This release merges the API-contract file improvements while preserving package fallback settings, debug redaction and duplicate-sync protection.

= 0.3.71 =
Re-save SooCool settings and re-test staging order sync. Package dimension/weight settings now persist through the REST settings endpoint.

= 0.3.70 =
Re-save SooCool settings, then re-test the staging order flow. The payload now follows the nested SooCool create-order contract and exposes SooCool field errors directly.

= 0.3.66 =
API base URL sanitizing is stricter for old/corrupt saved options. Re-save settings and test the SooCool staging API before production use.

= 0.3.60 =
Invalid pickup or delivery time windows are now rejected instead of silently reset. Re-save settings and test the SooCool staging API before production use.

= 0.3.59 =
Manual API-test contract validation is stricter. Re-test manual payloads that use Extra JSON before production use.

= 0.3.58 =
Reduces duplicate-order risk by avoiding automatic retries for write requests and revalidates generated test-order payloads. Re-test SooCool staging order sync before production use.

= 0.3.57 =
Fixes label nonce handling, hardens the test order reference check and avoids local timestamp double-offset edge cases. Re-test the SooCool staging API, order sync and label downloads before production use.

= 0.3.41 =
Release metadata cleanup only. Re-test the SooCool staging API, HPOS order sync, duplicate orderReference handling and label downloads before production use.

= 0.3.40 =
Connection tests now show a simple success message for authenticated HTTP 200 `/ping` responses.

= 0.3.38 =
Re-save the SooCool API key after upgrading if the connection test previously returned a missing X-API-Key 401 error.

= 0.3.36 =
Re-test the SooCool staging API, HPOS order sync, duplicate orderReference handling and label downloads before production use.
