=== SooCool for WooCommerce ===
Contributors: webactueel
Tags: woocommerce, shipping, logistics, transport, orders
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.5.29
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Koppelt WooCommerce-orders aan de SooCool transport-API.

== Description ==

SooCool for WooCommerce lets authorized WooCommerce shop managers submit WooCommerce orders to the SooCool transport API, create pickup and delivery tasks, and download SooCool shipping labels from the WooCommerce orders list and bulk actions.

The plugin is intended for stores that use SooCool for transport and delivery operations. It does not send order data until the plugin is configured and an authorized shop manager manually submits an order, or automatic submission is explicitly enabled in the plugin settings.

Main features:

* WordPress admin settings screen under SooCool.
* SooCool API connection test using the documented `/ping` endpoint.
* Manual WooCommerce order action to submit an order to SooCool.
* Optional automatic order submission when an order reaches a configured WooCommerce status.
* Optional pickup plus delivery task support for collection workflows; delivery-only is the safe default.
* Checkout delivery schedule is leading for delivery task timeWindow; fallback delivery window is only used when an order has no selected daypart.
* Customer-facing delivery moment selection for the classic WooCommerce checkout with configurable delivery days, fixed dayparts, cut-off times, blocked dates and optional hiding of expired slots. Checkout Blocks are not supported by this release.
* Configurable pickup address and pickup time window.
* WooCommerce HPOS compatible order metadata handling.
* A6 and Collated A4 SooCool shipping label downloads.
* Bulk SooCool label download from the WooCommerce orders list.
* SooCool label PDF attachments for the WooCommerce admin new-order email when labels already exist at send time.
* Sanitized activity logs and masked API key handling.

= External service: SooCool API =

This plugin connects to the SooCool API when an authorized shop manager tests the connection, submits an order, searches for an existing SooCool order by order reference, or downloads a shipping label.

Official API hosts used by the plugin:

* Staging: `https://api.staging.soocool.nl`
* Production: `https://api.soocool.nl`

The plugin sends the configured API key in the `X-API-Key` header. The API key is not intentionally exposed in the WordPress admin UI, REST responses, frontend markup or logs.

Data sent to SooCool can include WooCommerce order reference, billing/shipping name, address, country, email address, phone/mobile number, pickup address, package/goods description, pickup and delivery dates, pickup time window and the selected checkout delivery daypart. The SooCool API delivery task timeWindow follows the customer-selected checkout daypart when present; the fallback delivery window is only used for orders without a selected daypart.

Data is sent only for configured SooCool actions. No tracking, advertising or unrelated external assets are loaded by this plugin.

Please review SooCool's own service terms, data processing terms and privacy information before using the integration in production.

= Source and build notes =

This production package contains the runtime PHP source, readable CSS/JS assets, `.min` runtime assets, documentation and compiled Dutch translation file required to install and run the plugin. The plugin loads `.min` assets by default and falls back to readable assets when `SCRIPT_DEBUG` is enabled. Use the matching development repository for reproducible asset builds, translation source files and coding-standard checks.

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

= Does the delivery-moment picker work with WooCommerce Checkout Blocks? =

No. This release supports the classic WooCommerce checkout only. Checkout Blocks require a separate block-checkout integration before support should be claimed. Site admins receive a warning when the active checkout page uses the Checkout Block.

= Where should I store the API key? =

You can store it in the plugin settings. For stricter environments, define it in `wp-config.php`:

`define( 'SOOCOOL_API_KEY', 'your-soocool-api-key' );`

When the constant exists, it takes precedence over the saved database value.

= Does the plugin support WooCommerce HPOS? =

Yes. The plugin declares WooCommerce custom order table compatibility and uses WooCommerce order APIs instead of direct order postmeta queries.

= Which label formats are supported? =

The plugin supports the SooCool shipping label formats `a6` and `collated_a4` through the documented `orderIds` and `goodIds` label query endpoints. When SooCool returns positive good IDs in order responses, WooCommerce order-list links and bulk actions can download stored-good labels.

= How is the webhook secured? =

The webhook receiver requires the stored SooCool webhook token and HMAC headers by default. The generated webhook URL does not include the token as a query parameter unless legacy fallback is explicitly enabled.

The receiver supports `X-SooCool-Webhook-Token`, `X-SooCool-Webhook-Timestamp`, `X-SooCool-Webhook-Signature` and optional `X-SooCool-Webhook-Id`. Legacy accounts that cannot send headers yet can opt in to query-token URLs with `SOOCOOL_ALLOW_QUERY_TOKEN_WEBHOOK_URL` or the `soocool_allow_query_token_webhook_url` filter, and can opt out of HMAC with `SOOCOOL_REQUIRE_WEBHOOK_SIGNATURE` or the `soocool_require_webhook_signature` filter after a documented risk decision and staging test.

== Privacy ==

This plugin sends WooCommerce order, address, contact and package data to the SooCool API only when needed for configured transport operations. The plugin stores SooCool sync metadata on the WooCommerce order and stores sanitized activity logs in WordPress options.

The plugin does not load third-party JavaScript or CSS in the frontend, does not add tracking pixels, and does not intentionally send data to unrelated third-party services.

Site owners are responsible for disclosing the use of SooCool as a transport service in their own privacy policy where applicable.

== Uninstall ==

Removing the plugin deletes the `soocool_settings` and `soocool_logs` options. WooCommerce order meta such as SooCool order IDs, references, sync status and last errors is intentionally retained for historical order and audit continuity.

== Changelog ==

= 0.5.29 =
* Cleanup: removed disabled manual API-test endpoints, dummy-order factory and admin-test bundle from the production package.
* Cleanup: added `Update URI: false` to prevent accidental WordPress.org update collisions for this private WooCommerce integration.
* Fixed: aligned admin asset metadata with the plugin version.

= 0.5.28 =
* Fixed: checkout-toeslagen lezen de WooCommerce `update_order_review` formulierdata nu correct uit `post_data`, zodat landtoeslagen en avondtoeslagen direct in de klassieke checkout-totalen worden meegerekend.
* Fixed: adminbedragen voor toeslagen accepteren nu ook decimale komma-invoer zoals `1,5` zonder dat de waarde als ongeldig of leeg wordt opgeslagen.
* Verified: bezorgdagen vooruit blijven begrensd op maximaal 92 dagen en geblokkeerde datums worden genormaliseerd als geldige `YYYY-MM-DD`-datums.

= 0.5.26 =
* Security: webhook-HMAC is now required by default and generated webhook URLs no longer include the token query parameter unless legacy fallback is explicitly enabled.
* Security: handmatige API-testverzoeken worden geblokkeerd op de productieomgeving, tenzij productietests expliciet in code zijn toegestaan.
* Compatibility: het SooCool-adminscherm onderdrukt geen niet-SooCool WordPress admin notices meer.
* Localization: Nederlandse `nl_NL` taalbestanden toegevoegd en bundled language loading expliciet gemaakt.
* Release: metadata afgestemd op WordPress.org-repositorydistributie; er staat geen private updatebron in de pluginheader.
* Hardening: REST-beheer, webhook-secret en handmatige API-test capabilities zijn filterbaar zonder de standaard shopmanager-flow te wijzigen.
* Compatibility: activation/runtime requirements now enforce WooCommerce 8.0 or higher, matching the plugin header.
* Fixed: the checkout phone field is only forced required while the SooCool delivery checkout is enabled.
* Added: de klassieke checkout toont nu in het Nederlands wanneer de België-toeslag en avondtoeslag voor 17:00-22:00 gelden.
* Added: het adminscherm toont nu ook een Nederlandse checkouttekst-samenvatting bij de toeslagvelden.
* Added: Nederland-toeslag en Avondtoeslag Nederland zijn instelbaar boven de België-toeslagvelden en worden toegepast bij afleverland NL.

= 0.5.25 =
* Added: België-toeslag en avondtoeslag België zijn nu instelbaar in de backend onder Bezorgschema.
* Changed: de klassieke checkout gebruikt de opgeslagen toeslaginstellingen in plaats van vaste codewaarden.

= 0.5.24 =
* Added: België krijgt in de klassieke checkout een bezorgtoeslag van €2,00 bij afleverland BE.
* Added: België krijgt bij het vaste avonddagdeel 17:00-22:00 aanvullend een avondtoeslag van €1,50.
* Changed: wijziging van bezorgdag of dagdeel triggert WooCommerce checkout-herberekening, zodat de toeslag direct zichtbaar wordt.

= 0.5.23 =
* Fixed: handmatige SooCool API-test UI en REST-endpoint zijn standaard uitgeschakeld tenzij `SOOCOOL_ENABLE_MANUAL_API_TESTS` expliciet op `true` staat.
* Fixed: productie-adminstylesheet behoudt de juiste `.soocool-shell :where(...)` descendant selectors in de minified CSS.
* Packaging: distributie-ZIP opnieuw opgebouwd met de canonieke pluginmap `soocool-for-woocommerce/`.

= 0.5.21 =
* Verbeterd: SooCool-webhook-URL bevat nu WooCommerce order-ID en orderreferentie, zodat callbacks de juiste WooCommerce-order betrouwbaarder kunnen koppelen.
* Verbeterd: webhook-verwerking kan orderreferentie uit de webhook-URL gebruiken en koppelt een bekende WooCommerce-order veilig aan de ontvangen SooCool order-ID.
* Verbeterd: adresopbouw gebruikt Postcode Checker split-meta als fallback wanneer WooCommerce address_1 geen huisnummer kan leveren.

= 0.5.20 =
* Release: versies gelijkgetrokken naar 0.5.20 in plugin header, constante, readme Stable tag, asset metadata en vertaaltemplate.

= 0.5.19 =
* Admin: bezorgmoment-editor opnieuw zichtbaar gemaakt in de WooCommerce order-metabox, zodat het gekozen bezorgmoment vanuit de order kan worden opgeslagen en naar SooCool kan worden gepusht.

= 0.5.18 =
* Orders: wanneer het bezorgmoment in de WooCommerce-order wordt bijgewerkt, wordt de bestaande SooCool-order direct mee bijgewerkt.


= 0.5.17 =
* Compatibiliteit: de oude dubbele pluginmap soocool-for-woocommerce-main wordt automatisch gedeactiveerd wanneer de canonieke pluginmap actief is. Dit voorkomt dubbele SooCool-menus, dubbele hooks en oude timezone-payloads.


= 0.5.16 =
* SooCool-tijdvensters worden nu met de Nederlandse SooCool-tijdzone verstuurd, zodat het gekozen bezorgmoment uit WooCommerce niet verschuift in het SooCool-dashboard.


= 0.5.15 =
* E-mail/UI: Track & Trace-label weggehaald, tekst behouden en e-mailtitel gecentreerd.


= 0.5.14 =
* Frontend: extra marge boven de titel Bezorging op de orderdetailweergave toegevoegd.
* Admin: labeltitel boven de downloadknoppen verwijderd en downloadlinks in de orderlijst netter gestyled.


= 0.5.12 =
* Restored single order and good label downloads to the documented per-order SooCool endpoints.
* Preserved signed SooCool good IDs for label downloads.
* Replaced the WooCommerce order metabox delivery editor/track-and-trace button with stacked label download buttons.
* Changed unmatched webhooks to a non-retrying 202 response and removed dummy-test webhook registration.

= 0.5.10 =
* Removed the label download button group from the WooCommerce order metabox. Order-list label links and bulk actions remain available.
* Routed single order-label downloads through the SooCool `orderIds` label query endpoint.
* Routed single good-label downloads through the SooCool `goodIds` label query endpoint.
* Removed the order lookup preflight from bulk label downloads.

= 0.5.8 =
* Made the checkout delivery schedule leading for the SooCool delivery timeWindow.
* Clarified fallback-only delivery fields under Planning & goederen.
* Increased the checkout planning range to 92 days and made billing phone required in classic checkout.

= 0.5.5 =
* Split the manual API-test UI into an opt-in admin-test asset so the default production admin bundle stays clean.
* Added screen-reader-only unavailable status text and aria labels for disabled checkout delivery dates.
* Standardized direct-access protection style across PHP files.
* Removed an unreachable duplicate return in API-key resolution.

= 0.5.4 =
* Updated the translation template metadata.

= 0.5.3 =
* Removed the select-control container margin override and added an 8px top margin.
* Documented why the scoped select-field and label `!important` overrides are required.
* Kept CSS maintenance notes concise and technical.

= 0.5.2 =
* Aligned admin dropdown field styling with the existing text/search input styling using scoped `!important` CSS overrides.
* Aligned dropdown labels with the same typography as the other settings labels.

= 0.5.1 =
* Fixed Plugin Check/WPCS translator comments and direct file access detection.

= 0.5.0 =
* Refactored order sync, checkout delivery helpers, shipping label helpers and task factories.

= 0.4.95 =
* Updated checkout delivery text and improved unavailable date styling.

= 0.4.92 =
* Added SooCool order-label and good-label PDF attachments to the WooCommerce admin new-order email when labels already exist at send time.
* Kept customer emails limited to delivery date, daypart and a generic Track & Trace availability notice.

= 0.4.89 =
* Made the Bezorgschema admin accordion single-open by default: only the first delivery day opens on initial load, and opening another day closes the previous one.
* Kept the final admin polish styling while making the default schedule overview calmer.
* Backend data, checkout behavior, order meta, validation and the SooCool API timeWindow are unchanged.

= 0.4.88 =
* Polished the Bezorgschema admin to open only the first delivery-day card by default.
* Changed the time-slot show-all control into a quieter secondary outline action.
* Kept backend data, checkout behavior, order meta, validation and the SooCool API window unchanged.

= 0.4.78 =
* Added configurable delivery fixed dayparts to the classic WooCommerce checkout.
* Customers now choose a delivery date and then an available daypart.
* Added backend settings for daypart start/end time, per-slot cut-off time, weekdays and hiding expired/unavailable slots.
* Saved the selected delivery daypart to WooCommerce order meta and displayed the full delivery moment in the order admin and customer order details, and sent the delivery date and dagdeel in order emails.
* Updated the SooCool API delivery task timeWindow so it follows the selected checkout daypart when present.

= 0.4.76 =
* Updated WooCommerce compatibility metadata for WooCommerce 10.8.
* Reused the central sanitizer for public SooCool API error messages before saving order notes.
* Clarified that the delivery-day picker supports the classic WooCommerce checkout only, not Checkout Blocks.

= 0.4.75 =
* Added a WooCommerce order admin delivery-date editor in the SooCool metabox.
* Delivery-date changes are validated against the existing checkout schedule and saved through WooCommerce order CRUD.
* Added an order note when the requested delivery date is changed by an admin.

= 0.4.74 =
* Added a central AssetResolver for admin, checkout and order-action assets.
* Removed duplicated asset path resolution while preserving the latest admin and checkout UI.

= 0.4.72 =
* Added compact checkout delivery guidance before date selection.
* Kept selected delivery notice hidden until a customer chooses a date.

= 0.4.70 =
* Checkout delivery notice now stays hidden until a customer selects a delivery date.
* Kept 0.4.69 admin CSS regression fix and latest checkout/admin UI polish.

= 0.4.69 =
* Cleaned up audit findings while preserving the final admin and checkout UI.
* Removed obsolete CSS hide/polish rules, added real minified assets, and reduced unnecessary asset loading.
* Replaced direct inline admin-footer JavaScript and inline order-list styles with scoped assets/classes.

= 0.4.67 =
* Increased checkout delivery picker title size for stronger visual hierarchy.

= 0.4.65 =
* Checkout bezorgdag-picker verfijnd: statische infoblokken verwijderd, compacte premium datumtegels en rustigere geselecteerde/disabled states.

= 0.4.64 =
* Final 10/10 Bezorgdagen admin polish: upgraded add-rule action to a clearer secondary button, tightened save footer spacing, and moved general setting controls closer to their labels.

= 0.4.62 =
* Final Bezorgdagen admin UI polish: removed the delivery-window badge, removed duplicate footer note, flattened the general settings panel, refined spacing, and kept add-rule as a secondary action button.

= 0.4.59 =
* Reworked the checkout delivery selector into a compact date grid with disabled unavailable dates.
* Added dynamic admin delivery rules with add and remove controls.
* Removed the stored delivery-rule cap so additional rules persist and are read by the schedule.
* Kept HPOS ordermeta storage unchanged and made the selected checkout daypart leading for the SooCool delivery timeWindow.

= 0.4.57 =
* Moved the checkout delivery settings into a dedicated, customer-friendly "Bezorgdagen" admin tab next to "Pickup & delivery".
* Redesigned the Bezorgdagen screen with a wide, sectioned layout (general settings plus delivery rule cards) that stays readable and responsive.
* Corrected the checkout dispatch message from 13:30 to 13:00 to match the delivery cut-off logic.
* Added a developer-only `soocool_delivery_schedule_now` filter for deterministic staging validation of cut-off scenarios.

= 0.4.56 =
* Added the client-facing checkout information box with delivery days, cooled delivery copy and 13:00 dispatch message.
* Added a dynamic “Let op” checkout notice that follows the currently selected valid delivery day.
* Tightened delivery cut-off validation so a delivery day expires at the configured cut-off time, including exactly 13:00.

= 0.4.55 =
* Added customer delivery-day selection to the classic WooCommerce checkout.
* Added configurable delivery weekdays, cut-off times, days-ahead display and blocked dates to the SooCool settings.
* Store the selected delivery day on WooCommerce orders and use it for the SooCool delivery task date with the existing delivery-offset fallback.
* Added checkout styling, order admin display and order/email delivery-day display.

= 0.4.54 =
* Fixed release metadata consistency for the patched package.
* Repacked the release ZIP with the canonical `soocool-for-woocommerce` plugin folder.
* Removed an unused webhook dependency and obsolete bulk-label helper.

= 0.4.53 =
* Fixes WooCommerce bulk label download redirects by returning a raw admin URL instead of an HTML-escaped nonce URL.

= 0.4.52 =

* Fixed WooCommerce bulk downloads for both SooCool order labels and SooCool good labels by using signed order-id download URLs instead of the short-lived token redirect that could arrive without a token.

= 0.4.51 =

* Reworked WooCommerce bulk label downloads to use a short-lived, single-use admin-post download token instead of streaming the PDF directly from the bulk action filter.
* Bulk label downloads remain scoped to the current user, nonce-protected, limited to 50 selected orders and available on HPOS and legacy order list screens.
* Require HMAC webhook signatures by default, with timestamp-window and duplicate-delivery checks.
* Added explicit release-package notes for runtime packages versus WordPress.org/source-review packages.

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
* Keep the React/settings JavaScript limited to the SooCool settings page while allowing CSS on the SooCool settings and order screens.

= 0.4.22 =
* Improved the SooCool order meta box label controls and added direct order-label and good-label links to the ord
