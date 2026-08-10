=== SooCool for WooCommerce ===
Contributors: webactueel
Tags: woocommerce, shipping, logistics, transport, orders
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.7.24
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Koppelt WooCommerce-orders aan de SooCool transport-API.

== Description ==

SooCool for WooCommerce lets authorized WooCommerce shop managers submit WooCommerce orders to the SooCool transport API, create pickup and delivery tasks, and download SooCool shipping labels from the WooCommerce orders list and bulk actions.

The plugin is intended for stores that use SooCool for transport and delivery operations. It does not send order data until the plugin is configured and an authorized shop manager manually submits an order, or automatic submission is explicitly enabled in the plugin settings.

Main features:

* WordPress admin settings screen under WooCommerce > SooCool.
* SooCool API connection test using the documented `/ping` endpoint.
* Manual WooCommerce order action and a direct order-panel button to submit an unsynced order to SooCool.
* Optional automatic order submission when an order reaches a configured WooCommerce status, including newly created pending orders.
* Optional pickup plus delivery task support for collection workflows; delivery-only is the safe default.
* Checkout delivery schedule is leading for delivery task timeWindow; fallback delivery window is only used when an order has no selected daypart.
* Customer-facing delivery moment selection for classic checkout, plus a staging-first WooCommerce Checkout Blocks adapter based on the native additional-checkout-field API. Blocks compatibility remains declared false until parity is proven on staging.
* Configurable pickup address and pickup time window.
* WooCommerce HPOS compatible order metadata handling.
* A6 and Collated A4 SooCool shipping label downloads.
* Bulk SooCool label download from the WooCommerce orders list.
* Asynchronous SooCool label prefetch for the WooCommerce admin new-order email; email rendering reads only validated cached PDFs and never waits for the provider.
* Sanitized activity logs and masked API key handling.

= External service: SooCool API =

This plugin connects to the SooCool API when an authorized shop manager tests the connection, submits an order, searches for an existing SooCool order by order reference, or downloads a shipping label.

Official API hosts used by the plugin:

* Staging: `https://api.staging.soocool.nl`
* Production: `https://api.soocool.nl`

The plugin sends the configured API key in the `X-API-Key` header. Its technical HTTP User-Agent also contains the plugin version and public site URL. The API key is not intentionally exposed in the WordPress admin UI, REST responses, frontend markup or logs.

Data sent to SooCool can include WooCommerce order reference, billing/shipping name, address, country, email address, phone/mobile number, pickup address, package/goods description, pickup and delivery dates, pickup time window and the selected checkout delivery daypart. The SooCool API delivery task timeWindow follows the customer-selected checkout daypart when present; the fallback delivery window is only used for orders without a selected daypart.

Data is sent only for configured SooCool actions. Pickup and delivery time windows are validated as canonical ISO-8601 timestamps with an explicit timezone before submission. Successful API responses declared as JSON must contain valid JSON; malformed JSON is rejected and logged without exposing secrets or personal data. No tracking, advertising or unrelated external assets are loaded by this plugin.

Please review SooCool's own service terms, data processing terms and privacy information before using the integration in production.

= Source and build notes =

The installable package contains runtime PHP, JavaScript, CSS, translations, license and release documentation. The matching source package also contains Composer/PHPUnit, WPCS, PHPStan, Vitest, Playwright, wp-env, CI workflows and translation sources. Development dependencies, tests and local build artifacts are excluded from the installable ZIP through `.distignore`.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate this plugin.
3. Open WooCommerce > SooCool in the WordPress admin.
4. Choose the Test environment first.
5. Enter the SooCool API key or define `SOOCOOL_API_KEY` in `wp-config.php`.
6. Leave pickup disabled unless SooCool has confirmed pickup tasks for this account. If pickup is enabled, fill in the pickup address and pickup time window completely.
7. Save settings and run Test connection.
8. Create a WooCommerce test order and use Synchroniseer nu met SooCool in the SooCool order panel.
9. Confirm the order in the SooCool test portal, then enable automatic submission and select Betaling in afwachting for immediate submission of newly created orders.

== Frequently Asked Questions ==

= Does the plugin send data automatically? =

Not until the integration is configured and either an authorized shop manager manually submits an order or automatic submission is enabled. Select Betaling in afwachting to queue newly created orders immediately; later statuses wait until WooCommerce reaches that status.

= Does the delivery-moment picker work with WooCommerce Checkout Blocks? =

Not as a production compatibility claim yet. The plugin includes an opt-in native additional-checkout-field adapter with server-side validation and stale-session cleanup. Enable it only on staging with WooCommerce 9.9 or newer and the `soocool_enable_checkout_blocks_adapter` filter. Older WooCommerce versions fail closed because conditional additional checkout fields require WooCommerce 9.9. The WooCommerce Blocks compatibility declaration remains false until live fee recalculation, Store API refresh, payment and browser parity pass on staging.

= Where should I store the API key? =

You can store it in the plugin settings. For stricter environments, define it in `wp-config.php`:

`define( 'SOOCOOL_API_KEY', 'your-soocool-api-key' );`

When the constant exists, it takes precedence over the saved database value.

= Does the plugin support WooCommerce HPOS? =

Yes. The plugin declares WooCommerce custom order table compatibility and uses WooCommerce order APIs instead of direct order postmeta queries.

= Which label formats are supported? =

The plugin supports the SooCool shipping label formats `a6` and `collated_a4` through the documented `orderIds` and `goodIds` label query endpoints. When SooCool returns positive good IDs in order responses, WooCommerce order-list links and bulk actions can download stored-good labels.

= How do I report a security issue? =

Report security issues privately to the plugin maintainer. Do not publish API keys, customer data, authentication bypasses or order-data leaks in public issues or screenshots.

= How is the webhook secured? =

The webhook receiver always requires the stored SooCool webhook token plus timestamped HMAC headers. The generated webhook URL never includes a bearer token in its query string.

The receiver supports `X-SooCool-Webhook-Token`, `X-SooCool-Webhook-Timestamp`, `X-SooCool-Webhook-Signature` and optional `X-SooCool-Webhook-Id`. Distinct events for one order are serialized, replayed or out-of-order events are ignored safely, and provider event sequence or timestamp metadata is retained on the WooCommerce order when available.

== Privacy ==

This plugin sends WooCommerce order, address, contact and package data to the SooCool API only when needed for configured transport operations. The plugin stores SooCool sync metadata on the WooCommerce order. Sanitized operational logs are written primarily through the WooCommerce logger; a small legacy option summary is retained temporarily for backward-compatible admin display.

The plugin does not load third-party JavaScript or CSS in the frontend, does not add tracking pixels, and does not intentionally send data to unrelated third-party services.

Site owners are responsible for disclosing the use of SooCool as a transport service in their own privacy policy where applicable.

== Uninstall ==

Removing the plugin deletes the `soocool_settings` and `soocool_logs` options plus plugin-owned temporary e-mail label files and transients. WooCommerce order meta such as SooCool order IDs, references, sync status and last errors is intentionally retained for historical order and audit continuity.

== Changelog ==

= 0.7.24 =
* Maakt de uitleg over bezorg- en avondtoeslagen in checkout weer volledig, eenvoudig en duidelijk geformuleerd.

= 0.7.23 =
* Maakt de dagdeelkeuze in checkout rustiger en beter scanbaar met een aparte naam- en tijdregel, subtielere selectie en een compacter radiopunt.
* Behoudt beide dagdeelopties, de bestaande selectie, checkoutwaarden en volledige geselecteerde samenvatting ongewijzigd.

= 0.7.22 =
* Houdt de beschikbare dagdeelkeuzes zichtbaar nadat een klant een dagdeel heeft gekozen, zodat de selectie direct vergelijkbaar en wijzigbaar blijft.
* Verkort de checkout-uitleg voor Track & Trace, besteltijd en NL/BE-toeslagen.
* Trekt de korte Track & Trace-tekst gelijk in checkout, orderdetails en klantmails.

= 0.7.19 =
* Herstelt een checkout-fatal bij het tonen van bezorgtoeslagen en gebruikt daarbij de reeds genormaliseerde instellingenwaarden zonder een tweede toeslagparser toe te voegen.
* Rondt de recente beheerinterface op: compactere logdetails, kleinere gecentreerde disclosure-iconen, consistente kaartindeling en een korte uitleg voor zichtbare bezorgdagen.
* Vermindert onnodige instellingenrequests door reeds geladen instellingen tussen tabs te hergebruiken en gelijktijdige loads te dedupliceren.
* Verwijdert bevestigde dode code en consolideert dubbele webhook-identifiervalidatie zonder bestaande hooks, routes, option keys of ordercontracten te wijzigen.
* Trekt plugin-, asset-, readme- en vertaalmetadata gelijk op versie 0.7.19.

= 0.7.18 =
* Verwijdert de niet-werkende automatische remote-orderannulering uit de instellingen en actieve achtergrondlogica; handmatige annulering blijft beschikbaar.
* Ruimt reeds ingeplande legacy-annuleringstaken op zodra Action Scheduler veilig beschikbaar is.
* Herstelt Transportvereiste, Niveau, logretentiebreedte en binnenmarges van de activiteitenlogwerkbalk.
* Synchroniseert de admin-assetversie zodat gewijzigde beheerschermen niet door een oude JavaScript-cache worden gemaskeerd.
* Verwijdert verouderde automatische-annuleringsvertalingen en synchroniseert de vertaalmetadata met deze release.

= 0.7.17 =
* Laat ongeldige booleans in bezorgregels en dagdelen fail-closed valideren.
* Behoudt geldige true/false-waarden, bestaande hooks, REST-routes en option keys.

= 0.7.16 =
* UI: het transportvereiste-label gebruikt dezelfde sterke labelhiërarchie en compacte afstand als de overige instellingenvelden.
* UI: alle automatiseringskaarten gebruiken één gelijkmatig grid en blijven op ieder breakpoint even breed.
* UI: tekstvelden en de Niveau-select delen dezelfde interne labelafstand, veldhoogte en verticale uitlijning.
* CSS: de correcties zijn in de bestaande componentregels verwerkt zonder nieuwe override- of importantlaag.

= 0.7.15 =
* CSS: overtollige focus- en componentlagen verwijderd zonder de huidige vormgeving te wijzigen.
* CSS: ongebruikte tokens, dubbele selectors en aantoonbaar overschreven declaraties opgeschoond.
* CSS: leesbare en geminificeerde assets opnieuw gelijkgetrokken.
* UI: verbindingsknoppen gebruiken vaste geometrie voor optisch gecentreerde tekst.
* UI: de transportlabelafstand en het lettergewicht zijn gelijkgetrokken met compacte velden.
* UI: de ophaaltoggle en logretentiekaart zijn rustiger uitgelijnd zonder functionele wijzigingen.
* UI: verbindingsknoppen hebben nu exact dezelfde maatvoering, met behoud van de rode primaire actie.
* UI: automatiseringsinstellingen zijn als rustige, afzonderlijke kaarten opgebouwd met betere toggle-uitlijning.
* UI: logfilterlabels staan dichter bij hun velden en alle tekst- en selectvelden zijn exact uitgelijnd.
* UI: systeemgereedheid uit de instellingeninterface verwijderd; API-koppeling opent direct.
* UI: afgeronde paneelhoeken, bezorgschema-acties, automatiseringsvelden en logfilters vereenvoudigd en uitgelijnd.
* Accessibility: zichtbare labels en volledige actieknoppen blijven behouden op smalle schermen.

* Strengthens keyboard focus indicators across settings, order actions and checkout controls with high-contrast fallbacks.
* Keeps the native WordPress SelectControl indicator and removes the brittle custom arrow, negative offset and unnecessary important overrides.
* Adds clear horizontal-overflow cues for settings tabs on narrow screens and keeps the delivery-schedule save action reachable while changes are pending.
* Collapses long order-list error details behind a native accessible disclosure without removing the full diagnostic text.
* Preserves all existing settings, hooks, REST routes, checkout data, synchronization behavior and public contracts.

= 0.7.10 =

* Redacts plain token and secret assignments from untrusted log and API error text while preserving ordinary diagnostic sentences.
* Deletes cached e-mail label PDFs and their transients safely during uninstall without touching files outside the system temporary directory or following symlinks.
* Removes temporary label files when PDF preparation cannot write the complete response and prevents the underlying filesystem warning from reaching output.
* Preserves all existing settings, hooks, REST routes, checkout behavior, synchronization behavior and user interface.

= 0.7.9 =

* Makes invalid boolean-like settings fail closed instead of enabling resubmission, remote cancellation or delivery rules.
* Limits the WooCommerce delivery-fee tax-class slug to 200 characters in REST validation and stored settings.
* Cleans obsolete PHPDoc annotations without changing public hooks, routes, option keys, synchronization behavior or the user interface.

= 0.7.8 =

* Redacts whitespace-delimited API keys, webhook tokens and related credentials from untrusted provider error text before logging or admin display.
* Preserves all existing settings, hooks, REST routes, synchronization behavior and user interface.

= 0.7.7 =

* Fixes automatic remote-order cancellation retry handling when another SooCool action temporarily holds the order lock.
* Preserves all existing settings, hooks, REST routes, user interface and synchronization behavior.

= 0.7.6 =

* Splits the WooCommerce settings and order-screen admin CSS into dedicated, conditionally loaded bundles.
* Preserves the existing selectors, declarations, responsive behavior and dialogs without adding settings or changing public functionality.
* Keeps Checkout Blocks compatibility fail-closed and prevents the opt-in conditional field adapter from running below WooCommerce 9.9.

= 0.7.5 =

* Refactors settings normalization and API credential resolution into focused internal services while preserving all existing settings and option keys.
* Separates webhook order resolution, checkout rendering, API transport/PDF handling and delivery-setting validation behind the existing public classes.
* Preserves existing hooks, REST routes, synchronization behavior, order metadata and user-facing functionality; no new settings or features are introduced.

= 0.7.4 =

* Prioritizes initial and delayed SooCool synchronization jobs in Action Scheduler so eligible orders are claimed before ordinary default-priority jobs.
* Preserves the existing asynchronous checkout behavior, unique actions, idempotency, bounded retries, watchdog recovery, WP-Cron fallback and direct manual synchronization path.

= 0.7.3 =

* Replaces native browser confirmations with consistent, keyboard-safe SooCool dialogs in settings and WooCommerce order actions.
* Fixes modal design-token inheritance and uses the current WordPress Modal accessibility property.
* Clarifies the connection-screen action hierarchy and consolidates duplicate admin and checkout selectors without changing public behavior.

= 0.7.2 =

* Rejects conflicting webhook order identifiers during remote fallback resolution.
* Preserves webhook reservation identity safely for long-running PHP processes.
* Applies configured log retention, retains retry-delay diagnostics and redacts structured personal API errors.
* Clears webhook cleanup jobs on uninstall and removes stalled WP-Cron tasks before watchdog recovery.
* Deletes prefetched label PDFs immediately when transient cache persistence fails.

= 0.7.1 =

* Clears stale Checkout Blocks delivery-session values when a previously selected daypart becomes unavailable or delivery selection is disabled.
* Adds deterministic, `.distignore`-aware release packaging and a machine-readable product-readiness gate.
* Expands QIT profiles for PHP compatibility, Woo API, Woo E2E, minimum/current environments and a named premium release group.
* Documents strict dependency-lock, provider-acceptance and premium release stop criteria.

= 0.7.0 =

* Adds a persistent, environment-specific connection test state and a safe system-readiness dashboard with diagnostics export.
* Completes the staging Checkout Blocks session path for fresh Store API checkout updates while keeping compatibility fail-closed.
* Adds QIT profiles, a standardized Playwright test package, support and compatibility policies, an operations runbook, a customer acceptance gate and a release procedure.
* Adds dependency audit gates, Dependabot configuration and stronger local release verification.
* Positions this release as a premium release candidate; production acceptance still requires the documented WooCommerce and SooCool staging flows.

= 0.6.3 =

* Describes configurable evening delivery slots without incorrectly hardcoding 17:00-22:00 in customer surcharge copy.
* Adds a controlled regression for combined, evening-only and zero-surcharge customer messages.

= 0.6.2 =

* Keeps admin and server validation aligned when checkout delivery selection is disabled.
* Enforces the twelve-daypart limit in the admin before a schedule can be saved.
* Rejects invalid pickup and fallback-delivery offset combinations instead of silently changing them.
* Adds comprehensive settings-interaction and dependency-free admin-contract regressions.
* Removes confirmed unused admin CSS selectors without changing active markup or public contracts.

= 0.6.1 =

* Cancels every pending SooCool Action Scheduler task by its dedicated group, including tasks with order arguments.
* Loads the email-label hook owner during isolated uninstall cleanup to prevent a fatal error.
* Adds regressions for group-scoped queue cleanup and standalone uninstall execution.

= 0.6.0 =

* Makes system health fail closed when Checkout Blocks are active or the checkout mode cannot be determined.
* Adds bounded daily cleanup and continuation batches for expired durable webhook replay records.
* Extends CI across the declared minimum and current WordPress/WooCommerce stacks with both HPOS and legacy order storage.
* Adds regression coverage for checkout-mode reporting, webhook cleanup, Blocks field sanitization and separate Classic/Blocks browser contracts.
* Keeps Checkout Blocks compatibility explicitly disabled until complete staging parity is proven.

= 0.5.99 =

* Scopes Action Scheduler cleanup to the SooCool action group during deactivation.
* Runs dependency-free controlled and source-contract regressions before dependency installation in CI.
* Removes the unused `@wordpress/scripts` development dependency and documents the remaining lockfile requirement.
* Added stable delivery-slot identity, configurable fee taxability and request-local delivery-schedule memoization.
* Added opt-in, deduplicated remote cancellation for cancelled and fully refunded WooCommerce orders.
* Added renewable owner-bound leases around long-running synchronization and webhook operations.
* Switched label downloads to validated temporary files, corrected exact response-size boundaries and added bounded Retry-After-aware GET back-off.
* Moved admin-email labels to asynchronous prefetch and migrated primary logs to the WooCommerce logger.
* Added durable webhook idempotency, bounded event history and a custom order-reference resolver filter.
* Added an opt-in staging-first Checkout Blocks additional-field adapter while retaining the explicit incompatibility declaration.
* Added reproducible PHP, JavaScript, wp-env, CI and translation tooling.
* Expanded uninstall cleanup and keyboard focus behavior.

= 0.5.97 =

* Redacts compact credential field names such as `apikey`, `clientsecret`, `consumerkey` and `sessiontoken` before log storage.
* Synchronizes overlapping release notes between `readme.txt` and `changelog.txt`.
* Adds a validated 17,024-scenario source and controlled-runtime audit manifest with two complete green rounds.

= 0.5.96 =

* Harden HTTPS URL validation by rejecting embedded whitespace and control characters before WordPress URL normalization.
* Added 14,856-scenario controlled-runtime regression coverage.

= 0.5.94 =

* Preserves the canonical checkout delivery schedule, including custom rule and daypart sort order, when unrelated settings are updated.
* Separates WooCommerce order references from independent shipment, tracking, package and label references in incoming webhook payloads.
* Retains explicit legacy schedule updates and rejects genuine conflicting order references.

= 0.5.92 =

* Rejects impossible webhook event dates, clock values and UTC offsets instead of allowing PHP to normalize them.
* Rejects credential-bearing or fragment-containing outbound webhook URLs during final payload validation.
* Rejects non-existent Europe/Amsterdam local times during daylight-saving transitions instead of silently shifting delivery cutoffs or SooCool task windows.

= 0.5.91 =

* Allows confirmed retries and successful resynchronization to recover orders from failed or rejected SooCool states while preserving remote terminal states.
* Adds watchdog scheduling to the WP-Cron fallback, including duplicate queued actions.
* Prevents partial bulk order or goods-label downloads when any selected order or goods identifier is unavailable.
* Reports failed maintenance scheduling attempts as remaining work instead of falsely reporting a completed batch.
* Prevents identical webhook statuses from clearing unrelated local errors and permits authoritative webhook recovery from a local timeout state.
* Keeps webhook crash reservations shorter than the signed-request validity window so an authenticated delivery can be retried.

= 0.5.90 =

* Enforces JSON list shapes and strict text types for SooCool payload contracts.
* Extracts nested trace IDs with bounded traversal and reuses centralized secret redaction for API errors.
* Redacts prefixed credential names and credentials embedded in URL user information.
* Accepts only credential-free HTTPS tracking URLs in webhook, status and stored order metadata.
* Recovers malformed option locks atomically without taking over valid active locks.

= 0.5.89 =

* Redacts secrets and personal data from both newly written and previously stored activity-log messages.
* Prioritizes canonical SooCool order IDs and order references over incidental generic response fields.
* Uses each good's explicit `goodId` before its generic `id` fallback.
* Clears a stale webhook event ID when a newer sequence or timestamp arrives without an event ID.
* Aligns REST URL validation with sanitization by accepting harmless surrounding whitespace.

= 0.5.88 =

* Splits combined addresses at the final valid house-number token so numbered street names remain intact.
* Supports separated house-letter suffixes and removes a separator comma from parsed street names.
* Normalizes Dutch international numbers written with the optional trunk zero, including `+31 (0)` and `0031 (0)`.
* Accepts case-insensitive HTTPS schemes for custom webhook URLs.
* Rejects webhook URL fragments because fragments are never transmitted to the receiving server.

= 0.5.86 =

* Rejects sanitized but invalid customer email addresses before building SooCool contact data.
* Rejects every non-empty invalid contact field instead of allowing another valid contact field to mask it.
* Normalizes millisecond and microsecond webhook timestamps to seconds before event ordering.
* Prevents empty or invalid webhook updates from advancing the saved provider event cursor.
* Preserves enabled delivery rules and dayparts before applying configuration size limits.

= 0.5.85 =

* Rejects delivery slots whose cutoff is later than the slot end and aligns runtime, settings and REST validation.
* Keeps delivery dates and dayparts coupled in checkout summaries, accessibility state and stored order labels.
* Uses the effective delivery country for contact normalization and SooCool delivery payloads.
* Rejects unknown remote statuses and invalid stored time windows before they change order metadata.
* Tightens SooCool payload validation for country, email and telephone formats.

= 0.5.84 =

* Keeps the daypart selector visible after checkout refreshes when a delivery date is selected but no daypart is chosen.
* Uses the billing country for delivery surcharges when shipping to a different address is disabled.
* Aligns checkout delivery dates with the effective pickup date after a same-day pickup window has ended.
* Replaces expired requested same-day delivery windows with a future API delivery window during delayed synchronization.
* Refreshes stored delivery labels without sending an unnecessary remote update when the delivery date and times are unchanged.

= 0.5.83 =

* Keeps delivery dates and dayparts coupled so blocked, expired or out-of-horizon dates cannot expose or validate a stale daypart.
* Prevents duplicate dayparts with the same start and end time from creating ambiguous checkout values.
* Preserves configured daypart labels in checkout summaries, order metadata, emails and admin changes.
* Uses the fallback delivery window when an invalid requested delivery date is replaced during SooCool task creation.
* Aligns admin-side schedule validation with server validation for disabled rules and dayparts.
* Repairs duplicate legacy schedule data by retaining the enabled delivery rule or daypart instead of resetting to defaults.

= 0.5.82 =

* Removes internal audit-process language and exaggerated UI wording from the historical changelog.
* Rewrites two CSS maintenance comments to document selector-specificity constraints directly.
* Standardizes the compatibility-shim class comment without changing plugin behavior.
* Aligns plugin, asset, readme and Dutch translation metadata on version 0.5.82.

= 0.5.81 =

* Removes historical and overexplained comments that did not document runtime constraints.
* Keeps concise security, compatibility, translation and PHPCS rationale comments.
* Aligns plugin, asset, readme and Dutch translation metadata on version 0.5.81.

= 0.5.80 =

* Splits the settings sanitizer into focused connection, pickup, checkout and operational sections while preserving exact normalized output.
* Removes one unused import, five private pass-through or duplicate helpers and excessive blank lines.
* Reduces the highest measured cyclomatic complexity without changing public hooks, REST routes, options, metadata or frontend contracts.
* Adds characterization coverage for settings sanitization and remote status/tracking mapping.
