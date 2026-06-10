# Changelog

## 0.3.41
- Release hygiene: consolidate duplicate readme changelog and upgrade-notice entries.
- Bump release metadata to 0.3.41 without changing API, WooCommerce, HPOS, order sync, label or settings behavior.

## 0.3.40
- Admin: show non-standard `/ping` HTTP 200 responses as a normal successful connection toast.
- API: keep non-sample ping bodies as an internal/debug contract warning only, without alarming users in the connection-test message.

## 0.3.39
- Admin: move settings save/API-key status feedback out of inline page notices and into toast notifications only.
- Reject masked, redacted or corrupt API key values on read before sending requests to SooCool.
- Keep previously saved valid API keys when the masked field is submitted unchanged, but never use bullet placeholders as `X-API-Key`.
- Add safe API-key diagnostics to SooCool logs: source, status, length, first/last four characters, host and header name without exposing the full key.
- Show admin notices for saved, missing or invalid/masked API key state.

## 0.3.37

- Fixed API key replacement flow in the admin screen so masked dots are not sent as a key and pasted UUID keys are preserved before testing the connection.
- Added safe API key diagnostics to SooCool logs (`api_key_present`, `api_key_source`, `api_key_length`) without exposing the key value.
- Trimmed the stored key before sending the documented `X-API-Key` header to SooCool.

## 0.3.36 - 2026-06-10

- Admin: show a saved SooCool API key as masked dots in the password field instead of an empty field.
- Settings: preserve the saved API key when the masked value is submitted unchanged.
- Settings: normalize pasted API keys, including copied `API Key: ...` text and whitespace around UUID-style keys.
- Logs: add non-secret API key diagnostics (`api_key_present` and `api_key_source`) to failed API responses for easier debugging.
- Release: bumped plugin header, asset metadata and readme stable tag to 0.3.36 for a clean WordPress replacement update.

## 0.3.35 - 2026-06-10

- API: added orderReference preflight lookup before creating orders to prevent duplicate SooCool orders.
- API: added client support for good-specific labels and multiple order labels according to the SooCool documentation.
- Labels: admin-post label download now supports documented good and bulk label endpoint parameters for developer/admin integrations.
- Admin: added a safe test portal link in test mode without storing portal credentials in the plugin.
- Admin: clarified that SooCool pickup flows require both pickup and delivery tasks.
- Data: normalized outgoing address, contact and goods fields before building the SooCool payload.
- Packaging: release ZIP now uses the canonical top-level plugin folder `soocool-for-woocommerce/` so WordPress can replace the existing plugin during upload.
- Release: bumped plugin header, asset metadata, and readme stable tag to 0.3.35 for a clear upgrade path.
- i18n: confirmed translator comment fix for order note placeholders.
- API: treat authenticated `/ping` HTTP 200 responses as a successful connection test, even when SooCool returns a body that differs from the documented sample.
- API: keep `contract_warning=true` for non-sample ping bodies while keeping HTTP 401/403 and other non-2xx responses as failed connection tests.

## 0.3.32

- Add WordPress.org-style `readme.txt` with Stable tag, installation, FAQ, external service disclosure, privacy notes and changelog.
- Add explicit SooCool external service and privacy documentation for order, address, contact and package data sent to the SooCool API.
- Add source/build route notes for public WordPress.org submission readiness.
- Add dedicated privacy and source notes to make release responsibilities clearer for site owners and reviewers.

## 0.3.31

- Improve connection-test UI messaging so documented ping-body warnings and safe API failure messages are shown instead of a generic message.
- Preserve HTTP 200 /ping as successful while surfacing contract warnings separately.
- Improve API error extraction for string, object and nested errors, and log SooCool traceId when provided.
- Add settings validation for pickup/delivery date offsets and pickup time-window order.

## 0.3.30
- Treat a successful `/ping` HTTP 200 response as a valid connection test result even when the response body differs from the documented `{ "ping": "pong" }` shape.
- Return a non-blocking contract warning instead of a failed connection for successful ping calls with unexpected bodies.

## 0.3.29
- Center admin toast notifications within the SooCool plugin panel instead of the full browser viewport.
- No API, WooCommerce, HPOS, order sync, label or settings persistence changes.

## 0.3.28

- Save current API connection settings before running the connection test, so a newly entered API key can be tested immediately.
- Add centered bottom toast notifications for save, connection test and log actions.
- Keep saved API keys server-side after save while showing a masked saved-key state in the admin UI.

## 0.3.27

- UI polish: make the SooCool admin panel use the full available WordPress admin content width with balanced side gutters.
- No API, WooCommerce, HPOS, order sync or shipping label behavior changes.

## 0.3.26

- UI: adjusted dropdown/select left padding from 16px to 10px for better alignment.

## 0.3.25 - Select arrow fine-tuning

- Moved the custom dropdown chevron slightly to the right for better visual balance.
- UI-only change; no API, WooCommerce, HPOS, order sync or label download logic changed.

## 0.3.23 - Select arrow positioning fix
- UI-only fix: hides the native browser select arrow in the SooCool admin and draws a controlled chevron with stable right spacing across all dropdowns.
- No API, WooCommerce, HPOS, order sync, label download or settings storage changes.

## 0.3.22 - Automation and dropdown UI polish
- Align automation toggles and labels more consistently in settings cards.
- Add help text to the automatic sending toggle so paired toggle cards have balanced height.
- Make the default label format dropdown span the full settings card width.
- Move SelectControl arrows further inward for better right-side spacing.

## 0.3.21 - Equal settings card heights
- Polished the admin settings layout so paired cards stretch to equal height on desktop screens.
- Kept the change scoped to card grids only; input and select field spacing is unchanged.

## 0.3.20 - Select alignment polish

- Fine-tuned SelectControl label spacing so dropdown fields align vertically with adjacent text inputs.
- Kept compact spacing scoped to select/dropdown controls only.

## 0.3.19 - Admin select spacing fix

- Tightened WordPress Components SelectControl label-to-field spacing in the SooCool admin screen.
- Added right-side padding for SelectControl inputs so the dropdown arrow no longer sits against the field edge.
- No API, WooCommerce, HPOS, order sync, label download or settings storage changes.

## 0.3.17 - Admin select styling fix
- Hardened WordPress Components select/input-control CSS overrides so labels keep normal casing and focused dropdowns use the SooCool styling instead of the default blue WordPress/browser focus border.
- No API, WooCommerce, HPOS or settings logic changes.

## 0.3.16 - Staging API endpoint alignment

- Updated the default test API base URL to the documented SooCool staging server `https://api.staging.soocool.nl`.
- Updated the API host allowlist and release documentation to match the OpenAPI servers.
- Normalized older stored `https://api-test.soocool.nl` settings to the official staging server at read time.
- Re-verified shipping label, ping and order endpoint contracts against the supplied Redocly text and OpenAPI YAML.

## 0.3.14 - SooCool API label contract hardening

- Enforced positive numeric SooCool `orderId` values for order lookups, updates, cancellation and shipping-label downloads.
- Hardened stored SooCool order meta validation so invalid legacy values are not treated as synced orders.
- Added an explicit public error message for SooCool `412 Precondition Failed` responses, which can occur when label generation cannot proceed.
- Kept order-level label downloads aligned with the SooCool API contract: `GET /order/{orderId}/shipping-label`, `output=a6|collated_a4`, `Accept: application/pdf`, `X-API-Key`.

## 0.3.13 - Release-ready package cleanup
- Removed development-only Composer, npm, PHPCS and PHPStan files from the production ZIP.
- Removed unused reserved API endpoint descriptor classes and an unused sync result value object.
- Removed the empty build `.gitkeep` placeholder from the release package.
- Hardened SooCool API error parsing so nested provider error arrays cannot trigger array-to-string warnings.
- Updated the built admin asset version to match the plugin release version.
- Kept the release ZIP focused on runtime plugin files, built assets, language template, license, security policy, README and changelog.

## 0.3.13 - Production release hardening
- Removed development-only vendor dependencies from the release ZIP.
- Added uninstall cleanup for plugin settings and logs.
- Reduced synchronous SooCool API timeout/retry behavior to limit admin/order-flow blocking.
- Replaced external API error details in admin responses, order notes and label downloads with safer summaries while keeping details in sanitized logs.
- Added optional `SOOCOOL_API_KEY` constant support for keeping API keys out of `wp_options`.

## 0.3.13 - Admin UI and asset verification hardening
- Added a dependency-free `npm run check:assets` verification script for built admin JavaScript, CSS scoping, required UI labels and WordPress asset dependencies.
- Added `npm run verify:assets` as the full frontend verification command for asset integrity, linting and build.
- Improved admin UI accessibility evidence with live loading status, scoped focus-visible styling, reduced-motion handling and scoped activity log table labels.
- Added unit/static coverage for admin asset contract checks and tightened max-score QA notes for admin UI and build assets.

## 0.3.10 - API host hardening and QA checklists
- Restricted SooCool API base URLs to the official test and production hosts by default.
- Added `soocool_allowed_api_hosts` developer filter for controlled non-standard API hosts.
- Added unit coverage for allowed/disallowed API base URLs.
- Added extra staging QA checklists for SooCool API, HPOS, admin UI, build/assets, and security verification.

## 0.3.9 - SooCool API contract tolerance

- Accept string or UUID-like SooCool order IDs as well as numeric order IDs.
- Store SooCool order IDs as sanitized strings to avoid losing provider-specific identifiers.
- Use rawurlencode for /order/{orderId} and shipping-label endpoint paths.
- Add unit coverage for string order IDs and API order path encoding.

## 0.3.8 - Static audit score hardening

- Added WordPress plugin header metadata for WooCommerce dependency and GPL licensing.
- Improved the LICENSE file with a clear GPL-2.0-or-later notice and license URI.
- Added unit coverage for pickup-enabled task creation, fixed 08:00-18:00 delivery windows and configurable pickup windows.
- Added unit coverage to prove successful order metadata cannot mark an order as synced without a valid SooCool order ID.
- Kept production release packaging free from development-only audit documents.

## 0.3.7 - Release audit hardening

- Removed a real-looking test API key from unit-test fixtures and replaced it with a synthetic placeholder value.
- Clarified the release test checklist for the fixed SooCool delivery window and pickup plus delivery task validation.
- Excluded development-only release notes from production ZIP packaging.

## 0.3.6 - Fixed SooCool delivery time window

- Locked delivery task payloads to SooCool's required 08:00-18:00 delivery time window.
- Kept pickup task windows configurable for agreed pickup times.
- Updated the admin UI to show the delivery window as a fixed SooCool requirement instead of editable fields.

## 0.3.5 - Defensive sync status hardening

- Hardened order success metadata so an order can never be marked as synced without a valid SooCool order ID.

## 0.3.4 - Admin UI consistency polish

- Refined admin UI spacing, cards, toggles, notices, tables and button states for a more unified SooCool settings interface.
- Aligned the Logs tab label with the Activity logs screen title.
- Kept CSS scoped to `.soocool-*` and avoided global overrides or `!important`.

## 0.3.3 - Admin UI visual spacing refinement

- Refined scoped admin spacing for cards, fields, notices and actions.
- Improved toggle presentation while keeping native WordPress components.
- Added subtle SooCool brand styling for section titles, focus states and form layout.

## 0.3.2 - Admin UI label refinement

- Renamed admin tabs and field labels to better match the SooCool order, pickup, delivery and label workflows.
- Clarified API-key, environment, pickup task, delivery window, label format and log labels.
- Updated fallback built admin assets so the refined UI is visible before rebuilding assets.

## 0.3.0 - Release hardening pass

- Added stricter REST validation callbacks for enum and bounded integer settings.
- Added settings preview sanitization so REST validation and stored options use the same rules.
- Prevented REST order sync responses from returning the full SooCool response body.
- Hardened admin save payloads so masked API-key placeholders are never posted as real secrets.
- Updated fallback build assets to match the hardened admin behavior.
- Added unit coverage for API-key preservation, delivery offset coercion and temperature fallback.

## 0.2.1 - Admin and validation audit

- Added stricter REST argument sanitization and validation for settings and order sync.
- Prevented connection-test responses and logs from exposing raw API bodies.
- Added payload validation for incomplete pickup and delivery addresses before SooCool submission.
- Added admin loading, saving and error states to reduce duplicate actions.
- Restricted temperature regime values to a small allow-list with a safe fallback.
- Synced fallback build assets with the updated admin UI behavior.

## 0.2.0
- Added HPOS compatibility declaration.
- Added pickup address settings.
- Added duplicate sync protection.
- Added safe API-key preservation for masked settings.
- Added manual built admin fallback assets.
- Improved Composer, PHPCS, PHPStan and GitHub Actions setup.
- Improved REST route validation and log clearing.

## 0.1.0
- Initial staging-first plugin scaffold.
