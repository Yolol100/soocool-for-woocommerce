## 0.4.14 - 2026-06-11

- Keep the release version at 0.4.14 while adding production/staging hardening for the Haknes pickup workflow.
- Default new installs to pickup + delivery tasks, because SooCool collects the packages and expects both task types for this connection.
- Force delivery tasks to the SooCool-confirmed 08:00-18:00 delivery window while leaving pickup times configurable for the agreed pickup window.
- When pickup is enabled and the same-day pickup window has already passed, new payloads move pickup to tomorrow and keep delivery at least one day after pickup.
- Avoid mixed shipping/billing delivery addresses: use a complete shipping address when present, otherwise use billing, and report missing fields clearly.
- Respect the manual resubmission setting in the REST sync endpoint; forced resubmission is blocked while the setting is disabled.
- Add script filemtime cache-busting so same-version 0.4.14 admin JS/CSS maintenance builds are loaded after upload.
- Keeps the SooCool payload contract, webhook authentication, HPOS lookup, contactInfo.phone blocking and logging redaction unchanged.

## 0.4.13 - 2026-06-11

- Clean up admin CSS by folding the full-width field overrides into the main SooCool admin layout rules.
- Remove obsolete conflicting desktop grid and compact single-field CSS overrides while keeping settings fields full width.
- Add an early admin JS page/dependency guard so the settings app exits safely when the target root or WordPress dependencies are unavailable.

## 0.4.12 - 2026-06-11
- Bughunt hardening: keep SooCool `traceId` visible in sanitized logs so backend incidents can be reported to SooCool without exposing secrets.
- Bughunt hardening: include the numeric SooCool `orderId` in sanitized webhook not-found logs for safer debugging.
- Kept webhook payload, `contactInfo.phone` blocking, HPOS lookup and full-width admin fields unchanged.

## 0.4.11 - 2026-06-11
- Conservative cleanup release.
- Improved SooCool delivery address validation feedback for incomplete WooCommerce order addresses.
- Removed duplicate blank-line polish in PHP files.
- Kept webhook payload, contactInfo.phone blocking, HPOS lookup and full-width admin fields unchanged.

## 0.4.10 - 2026-06-11

- Make the SooCool admin settings UI full-width: cards, rows, inputs, selects, time and number controls now use the full panel width instead of compact desktop columns.
- Bump the built admin asset version so WordPress serves the updated CSS after plugin upload updates.

## 0.4.9 - 2026-06-11

- Fix fatal manual API-test redaction bug by adding missing debug string/error-list redaction helpers.
- Keep manual API-test output redacted for API keys, webhook tokens and token query strings.
- Remove duplicate internal API-key normalization call.


- Hardened webhook token extraction so generated `?token=...` webhook URLs authenticate correctly when webhook token headers are absent or returned as non-string/empty values by WordPress.
- Built the generated webhook URL with `add_query_arg()` after `rest_url()` for safer plain-permalink/query-string handling.
- Switched the webhook order meta lookup to an explicit `meta_query` for more robust WooCommerce HPOS compatibility.

## 0.4.7 - 2026-06-11

- Made the `webhook` block optional in outgoing create/update order payloads to match the SooCool OpenAPI 1.2.1 contract, where only `orderReference`, `tasks` and `goods` are required. Orders previously could not be created on installs without a derivable public HTTPS callback (HTTP/local/staging sites), because the payload builder hard-required `webhook.webhookUrl`/`webhook.webhookUpdates`.
- The webhook is still attached automatically whenever an HTTPS webhook URL is available, and its shape is still validated when present, so a malformed webhook block is rejected before sending.
- Stores without a webhook can keep status/track & trace in sync with the existing "SooCool: refresh status" order action.

## 0.4.6 - 2026-06-11

- Fixed refresh/status parsing so nested SooCool task fields such as `taskState` and `trackAndTraceLink` are detected in `GET /order/{orderId}` responses.
- Prevent product barcodes from being stored as track & trace codes during webhook/refresh parsing.
- Keep `trackAndTraceLink` as the source for the track & trace URL while treating goods barcodes only as package identifiers.

## 0.4.4 - 2026-06-11
- Added `trackAndTraceLink` extraction for webhook and refresh responses.
- Stored the original customer `orderReference` separately for more robust webhook lookup.
- Removed unused manual payload/Extra JSON processing from the simplified API-Test handler.
- Clarified pickup mobile help text and removed a duplicate dummy phone setter.
- Hardened SooCool contactInfo output by omitting `phone` from create/update payloads and only sending confirmed Dutch mobile numbers as `mobile`.

## 0.4.2 - 2026-06-11
- Fixed SooCool webhookUpdates enum values to use `task_state` and `planned_time`.
- Removed the root-level `webhookUpdates` compatibility alias from outgoing order payloads.
- Keeps the simplified API-Test flow: real WooCommerce order or non-saved testorder.

## 0.4.1 - 2026-06-11

- Fixed SooCool webhook contract payload by sending `webhook.webhookUpdates` inside the `webhook` object.
- Kept root `webhookUpdates.webhookUrl` as compatibility/debug alias.
- Maintains the simplified API-Test flow from 0.4.0: real WooCommerce order or non-saved testorder.

## 0.3.99 - 2026-06-11

- Release validation cleanup: confirmed the SooCool cancel unexpected-error path writes a single WooCommerce order note.
- Bumped release metadata after the final production-readiness pass.

## 0.3.98 - 2026-06-11
- Fixed Dutch mobile detection after normalization so `+316...`, `316...` and `06...` are mapped to `contactInfo.mobile` instead of `contactInfo.phone`.
- Keeps fixed-line numbers separate from mobile numbers for SooCool contactInfo validation.

## 0.3.97 - 2026-06-11
- Added `webhookUpdates.webhookUrl` to order payloads while keeping `webhook.webhookUrl` as compatibility alias.
- Normalized Dutch phone numbers and maps mobile numbers to `contactInfo.mobile` instead of `contactInfo.phone` to match SooCool validation.
- Updated dummy and manual API-test contact defaults to use an international mobile value.

## 0.3.96 - 2026-06-11
- Made API-Test mode cards clickable so recommended, quick dummy and advanced payload modes jump to the right control.
- Reduced closed advanced accordion spacing to remove excessive empty space in the API-Test screen.
- Improved focus/hover states for API-Test mode cards.

- Improved WooCommerce order action labels for safer fulfilment decisions.
- Added confirmation prompts for SooCool update and cancel order actions.
- Reworked the API-Test page into recommended, quick and advanced modes with clearer next-step feedback.
- Improved the SooCool order metabox with scan-friendly status badges, grouped labels and clearer empty states.
- Added admin CSS for the UX hardening changes.

## 0.3.94 - 2026-06-11

- Added a visible "Refresh from SooCool" WooCommerce order action using `GET /order/{orderId}`.
- Stores positive SooCool good IDs from API responses when available.
- Added an order metabox button for downloading stored SooCool good labels via `goodIds`.
- Keeps manual refresh timestamps separate from real webhook receipt timestamps.
- Added admin-facing webhook token guidance for header-based integrations when SooCool supports custom headers.

## 0.3.93 - 2026-06-11

- Store the sent WooCommerce/SooCool order reference as fallback when the API response omits `ourReference`.
- Prevent duplicated `soocool_` status prefixes when webhook payloads already include the plugin status prefix.

## 0.3.92 - 2026-06-11

- Harden the webhook permission check so public webhook requests only read an existing token and never generate/write one.
- Add a WooCommerce order action to update linked orders at SooCool via the existing `PUT /order/{orderId}` client method.
- Add support for bulk good-label downloads through the documented `/shipping-label?goodIds=` endpoint.
- Make webhook track & trace extraction more tolerant of nested tracking payloads.
- Merge the duplicate 0.3.91 changelog entries and bump release metadata to 0.3.92.

## 0.3.91 - 2026-06-11

- API-Test uitgebreid met een dummy WooCommerce testorder die via dezelfde OrderPayloadBuilder loopt als echte WooCommerce orders.
- Dummy testorder gebruikt realistische klant-, adres-, contact-, instructie- en orderregelgegevens zonder een order in de database aan te maken.
- Update API-Test so it can send a real WooCommerce order payload via `wc_get_order()` and the normal `OrderPayloadBuilder`.
- Keep the manual payload test as fallback, but recommend WooCommerce order-ID based testing for SooCool API contract validation.
- Show the API-Test mode in the result output.

## 0.3.89 - 2026-06-11

- Add token-protected SooCool webhook receiver for order status and track & trace updates.
- Add WooCommerce order action to cancel linked orders at SooCool.
- Harden API-Test Extra JSON so core orderReference/tasks/goods cannot be overwritten.
- Redact provider error details before showing them in admin/order context.

## 0.3.88 - 2026-06-11

- Add a "Download SooCool labels" bulk action to the WooCommerce orders list (HPOS and legacy). The bulk admin-post handler and `/shipping-label?orderIds=` client call already existed but nothing in the UI could reach them.
- Fix PHP 8.1+ deprecation: register the hidden API-Test admin page with an empty-string parent instead of `null`.
- Treat a 404 from the SooCool order-reference search as "no existing order" in both the order action and the REST sync controller, instead of failing the entire sync.
- Reuse the `WC_Order` object passed by `woocommerce_order_status_changed` in the auto-submit hook instead of refetching it.
- Stop logging a "Retrying temporary SooCool API error" entry on the final attempt when no retry will actually be performed.
- Guard the admin style version against `filemtime()` returning `false`; fall back to the plugin version.
- Remove an ineffective `FILTER_NULL_ON_FAILURE` flag on a `FILTER_UNSAFE_RAW` filter input.
- Extend uninstall cleanup to remove leftover `soocool_sync_lock_*` options and manual API-Test result transients.
- Bump readme Tested up to WordPress 6.9; recheck PHP syntax for all plugin files.

## 0.3.87 - 2026-06-10

- Fix duplicate dropdown-arrow rendering by consolidating the SooCool admin SelectControl arrow styling into one scoped mechanism.
- Recheck PHP syntax, admin JS syntax, CSS parse, security greps and ZIP integrity after the patch.

## 0.3.86 - 2026-06-10

- Bump release version metadata to 0.3.86.
- Avoid storing manual API-Test form values in the transient result because the response screen no longer reuses them and the fields may contain contact/address data.
- Recheck PHP syntax, admin JS syntax, CSS parse and ZIP integrity after the patch.

## 0.3.85 - 2026-06-10

- Fix dropdown arrow visibility in the SooCool admin UI while keeping the styling scoped to the plugin screen.

## 0.3.84 - 2026-06-10
- Release-ready package with the correct `soocool-for-woocommerce/` ZIP root folder.
- Align version metadata across plugin header, `SOOCOOL_VERSION`, assets, readme and POT.
- Clarify readme external service wording to only describe active connection, order submission, order lookup and label download flows.
- Recheck PHP syntax, admin JS syntax, CSS parse, REST/admin security greps, credential exposure, HPOS storage patterns and ZIP integrity.

## 0.3.83 - 2026-06-10
- Audit and clean the SooCool admin CSS/JS layer.
- Remove unnecessary `!important` flags from scoped admin CSS.
- Consolidate duplicate select, full-width panel and card-layout overrides without changing plugin behavior.
- Recheck PHP syntax, admin JS syntax, CSS parse, asset enqueue scope and ZIP integrity.

## 0.3.82 - 2026-06-10
- Move the API-Test tab between Shipping labels and Activity logs in the SooCool admin navigation.

## 0.3.81 - 2026-06-10
- Make the manual API-Test delivery-only in the UI because the form builds a single task and the SooCool contract requires at least one delivery task.
- Pickup + delivery remains available through normal WooCommerce order sync when pickup is enabled.

## 0.3.80 - 2026-06-10
- Move API-Test from the WordPress left submenu into the main SooCool tab navigation.
- Keep the API-Test page available as a hidden admin page linked from the tab bar.
- Add the shared tab navigation to the API-Test page so the active API-Test tab matches the main settings UI.

## 0.3.79 - 2026-06-10
- Polish main settings layout: keep cards content-sized instead of stretching short cards, and keep odd fields aligned in the grid.

## 0.3.78 - 2026-06-10
- Style the API-Test Vorige button with the same primary red button design as the existing plugin actions.

## 0.3.77 - 2026-06-10
- Hide the manual API-Test form after a response is shown and add a Vorige button to return to the editable form.

## 0.3.76 - 2026-06-10
- Migrates legacy 09:00-17:00 test delivery windows to the SooCool-confirmed 08:00-18:00 staging delivery window.
- Keeps API keys and portal credentials out of the plugin code.

## 0.3.75 - 2026-06-10

- Admin: rename the submenu label and page heading to **API-Test**.
- Admin: make the order reference field full width on the API-Test page.

## 0.3.73 - 2026-06-10

- Style the manual API-test page with the main SooCool admin design system.
- Enqueue the shared SooCool admin stylesheet on the manual API-test submenu page.
- Remove the old fictitious WooCommerce test-order button and unused REST route from the React settings screen.
- Keep the API-Test page as the staging validation path.

## 0.3.72 - 2026-06-10
- Combo fix: merge the uploaded API-contract controller/builders with the already fixed plugin instead of replacing the plugin wholesale.
- Payload: prefer product dimensions/weight when available and safely fall back to configured package width, depth, height and weight.
- Manual API test: keep the improved API-contract form fields, settings-based defaults and privacy redaction for displayed/stored debug output.
- Task handling: keep pickup/delivery date ordering safe when pickup is enabled, add mobile contact mapping, and expose a contactInfo filter.
- QA: reran PHP syntax checks, targeted compatibility greps and ZIP integrity after the patch.

## 0.3.71 - 2026-06-10
- Bugfix: save package width, depth, height and weight from the REST settings endpoint so the admin UI values are used in SooCool order payloads.
- Privacy hardening: redact personal/contact/address fields from manual API-test debug output before storing or rendering the transient result.
- Order safety: add a short per-order sync lock around REST and WooCommerce order-action submissions to reduce duplicate create-order requests from concurrent triggers.
- QA: reran PHP syntax checks and ZIP integrity after the patch.

## 0.3.70 - 2026-06-10
- API contract fix: build SooCool create-order payloads with nested task `timeWindow`, `address`, `contactInfo` and task-level `goods` ID references.
- Goods contract fix: use negative create-request `goodId` values, include dimensions, weight and `transportRequirements`, and reference those IDs from every task.
- Debugging: surface SooCool `errors[]` details in test-order and order-sync responses instead of only showing a generic rejection message.
- Settings: add package dimension, weight and transport requirement fields; normalize NL postcodes without spaces.
- QA: reran PHP syntax checks, admin JS syntax check, automatic delivery/pickup payload harnesses, version consistency and ZIP integrity.

## 0.3.66 - 2026-06-10
- Remove an unreachable duplicate return in API URL sanitizing.
- Fall back to the official SooCool hosts when direct option sanitizing receives old/corrupt API base URLs.
- Re-run PHP syntax, JS syntax, version consistency and ZIP integrity checks after the patch.

## 0.3.60 - 2026-06-10

- Settings: reject invalid pickup/delivery time windows in the REST controller before sanitizer fallback can silently reset them.
- Release hygiene: consolidate duplicate readme changelog and upgrade-notice entries, and set a conservative tested-up-to value.
- QA: rerun full file-by-file static audit, PHP syntax checks, JS syntax check, version consistency checks and ZIP integrity checks.

## 0.3.59 - 2026-06-10
- Manual API-test hardening: after Extra JSON merging, require at least one delivery task and ensure pickup tasks start before delivery tasks, matching the documented order requirement.
- Documentation cleanup: update stale README wording about the configurable delivery window.

## 0.3.58 - 2026-06-10
- API safety: do not automatically retry non-idempotent POST/PUT/DELETE requests, reducing duplicate-order risk when a create-order request reaches SooCool but the response is temporarily unavailable.
- Test-order hardening: validate the final payload again after overriding the generated test order reference.
- Documentation packaging: include the privacy and source/build disclosure files referenced from README.md.
- Documentation cleanup: remove stale fixed-delivery-window wording now that delivery windows are configurable.

## 0.3.57 - 2026-06-10
- Avoid WordPress local timestamp double-offset edge cases when building SooCool task dates and manual test defaults.

- Label hardening: treat any `order_ids` request as a bulk-label request, even when only one ID is provided, so nonce handling and the documented `/shipping-label?orderIds=` endpoint stay consistent.
- Test-order hardening: re-check the generated SooCool test order reference after overriding the payload reference.

## 0.3.55 - 2026-06-10

- Bugfix: keep commas unescaped in multiple-label `orderIds=1,2,3` requests while still encoding each ID value, matching the SooCool comma-separated query contract.
- Rechecked PHP syntax, admin JS syntax, ZIP integrity and release metadata after the patch.

## 0.3.53 - 2026-06-10

- Release/cache bugfix: update `assets/build/admin.asset.php` to the current plugin version so WordPress does not keep serving stale admin JavaScript after upload updates.
- API hardening: only send `Content-Type: application/json` when a JSON body is present, keeping GET/PDF label requests cleaner for stricter API gateways.
- Release hygiene: update the POT project version and upgrade notice to the current release.

## 0.3.52 - 2026-06-10

- Bugfix: catch unexpected runtime failures in the REST order sync endpoint so admin UI calls return a safe error response instead of a fatal error.
- Bugfix: catch unexpected runtime failures in the REST connection test endpoint.
- Validation: require `goods[].goodId` to be a positive integer in both automatic payloads and the manual API test.
- Privacy hardening: remove query strings such as `orderReference` from API log path context.
- Validation: keep manual API test `taskType` limited to `delivery` or `pickup` after Extra JSON merging.

## 0.3.49 - 2026-06-10

- Bugfix: use the configurable delivery time window in the admin UI and REST settings instead of showing a stale fixed 08:00-18:00 message.
- Bugfix: reject invalid delivery windows where the end time is not later than the start time.
- Bugfix: add a configurable `packaging_type` setting and use it for `goods[].packagingType` instead of hardcoding `box` only.
- Bugfix: validate the final manual API-test payload after Extra JSON merging so required SooCool fields cannot be accidentally removed.

## 0.3.48 - 2026-06-10

- Bugfix: preserve configured delivery time window settings instead of resetting them to 08:00-18:00 on save.
- Bugfix: format SooCool `startTime` and `endTime` with the WordPress site timezone offset instead of forcing UTC output.
- Validation: reject manual and automatic payloads where `endTime` is not later than `startTime`.

## 0.3.47 - 2026-06-10

- API: align the automatic WooCommerce order payload with the SooCool `/order` contract used by the manual API test.
- Tasks: send `taskType`, `startTime`, `endTime` and `postCode` inside every task instead of the older `type`, `date`, `timeWindow` and nested address-only shape.
- Goods: send `goodId`, `packagingType` and `contents` for each WooCommerce package, and omit empty barcode values by default.
- Validation: check required SooCool task and good fields before submitting automatic orders.

## 0.3.46 - 2026-06-10

- Admin: add a separate **SooCool > API-Test** menu item for sending an explicit SooCool `/order` test payload.
- API test: align the manual test body with the documented SooCool order shape: root `orderReference`, non-empty `tasks[]` and non-empty `goods[]`.
- API test: place `taskType`, `startTime`, `endTime` and `postCode` inside `tasks[0]`, and place `goodId`, `packagingType`, `contents` and optional `barcode` inside `goods[0]`.
- Admin: show a clear success/failure result, HTTP status, returned API errors, sent payload and SooCool response after every manual test.

## 0.3.44 - 2026-06-10

- Admin UI: make the test-order action a branded primary SooCool button with a visible busy label, so it is visually clear and consistent with the plugin interface.

## 0.3.43 - 2026-06-10

- Security hardening: remove API key first/last-character diagnostics from REST/admin/debug paths. Only non-secret diagnostics such as presence, source, status and length remain.
- Rechecked test-order implementation after packaging: PHP lint, admin JS syntax and ZIP integrity are clean.

## 0.3.42

- Add a secured REST endpoint and admin UI button to create a fictitious WooCommerce test order and send it to the SooCool test API.
- Block test-order creation when the plugin is configured for the production SooCool environment.
- Align the REST order sync endpoint with the manual order action by checking existing SooCool orders by reference before creating a new one.
- Keep API-key edge fragments out of stored logs while retaining non-secret key diagnostics.

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
