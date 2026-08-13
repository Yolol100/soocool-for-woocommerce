=== SooCool for WooCommerce ===
Contributors: webactueel
Tags: woocommerce, shipping, logistics, transport, orders
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.7.147
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Koppelt WooCommerce-orders aan de SooCool transport-API.

== Description ==

SooCool for WooCommerce connects WooCommerce orders to the SooCool transport API. Authorized shop managers can configure the connection, queue eligible delivery orders, retry failed synchronization, receive SooCool status updates and download shipping labels.

Main features:

* Settings under WooCommerce > SooCool.
* Test and production SooCool API environments with masked API-key handling.
* Automatic background submission for eligible physical delivery orders plus controlled manual retry.
* Pickup and delivery tasks using the configured pickup address and delivery schedule.
* Classic checkout delivery-moment selection with server-side validation.
* A Checkout Blocks adapter that is disabled by default and remains staging-first until full parity is proven.
* WooCommerce HPOS-compatible order access through WooCommerce order APIs.
* A6 and collated A4 shipping-label downloads, including bulk actions.
* Asynchronous label prefetch for WooCommerce admin order e-mail without blocking mail rendering on a live provider request.
* Sanitized operational logging, webhook authentication and replay protection.

= External service: SooCool API =

This plugin connects to the SooCool API when an authorized manager tests the connection, submits or refreshes an order, searches for an existing SooCool order, or downloads a shipping label. It also exposes an authenticated webhook endpoint that SooCool can use to send status and tracking updates.

Default API hosts:

* Test: `https://api.staging.soocool.nl`
* Production: `https://api.soocool.nl`

The configured API key is sent in the `X-API-Key` request header. Order data sent to SooCool can include the WooCommerce order reference, billing or shipping name, address, country, e-mail address, telephone number, pickup address, goods/package information, pickup and delivery dates, time windows and the selected checkout delivery moment. The plugin does not intentionally send this data to unrelated third parties.

Review SooCool's service terms and privacy information before production use:

* Service: https://soocool.nl/
* Terms: https://soocool.nl/wp-content/uploads/2024/06/Algemene-voorwaarden-SooCool-Geldig-per-13-6-2024.pdf
* Privacy: https://soocool.nl/privacybeleid/

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate SooCool for WooCommerce.
3. Open WooCommerce > SooCool.
4. Start with the Test environment and enter the test API key, or define `SOOCOOL_API_KEY` in `wp-config.php`.
5. Complete the pickup address and contact details.
6. Configure the delivery schedule and label format as needed.
7. Save the settings and run the connection test.
8. Create a test order and verify synchronization, webhook status and labels before using production credentials.

== Frequently Asked Questions ==

= Does the plugin send orders automatically? =

Yes, after the integration is configured. Eligible physical delivery orders are queued in the background. Virtual-only orders, pure local-pickup orders and orders already linked to SooCool are not submitted as new orders again.

= Does the delivery picker support WooCommerce Checkout Blocks? =

Not as a production compatibility claim yet. The adapter is disabled by default and can be enabled explicitly on staging through the `soocool_enable_checkout_blocks_adapter` filter. The plugin declares Checkout Blocks incompatibility until Store API, fee, payment and browser parity have been verified.

= Where should I store the API key? =

You can store it in the plugin settings. For environments that keep secrets outside the database, define `SOOCOOL_API_KEY` in `wp-config.php`. The constant takes precedence over the saved key.

= Does the plugin support WooCommerce HPOS? =

The plugin declares WooCommerce custom order table compatibility and uses WooCommerce order CRUD APIs for SooCool order data. Runtime verification on the target store is still recommended after WooCommerce upgrades.

= How is the incoming webhook secured? =

The receiver requires the stored SooCool webhook token plus timestamped HMAC headers and replay protection. Conflicting order identifiers fail closed before order state is changed.

= Does the plugin support WordPress Multisite network activation? =

Not as a formal compatibility claim. Activate it per site until network-wide activation, upgrade, deactivation and uninstall have been verified on representative Multisite staging.

= Which label formats are supported? =

The plugin supports SooCool `a6` and `collated_a4` label output through the existing order and goods label flows.

== Privacy ==

The plugin sends order, address, contact and package information to SooCool only for configured transport operations. SooCool synchronization metadata is stored on the WooCommerce order. Operational logs are sanitized to avoid intentionally storing API keys or full personal-data payloads.

WooCommerce personal-data exports include relevant SooCool shipment and delivery metadata. When WooCommerce order erasure is enabled and processes an erasure request, the plugin removes the selected delivery moment and tracking data covered by its erasure policy. Operational remote identifiers and synchronization state are retained to prevent duplicate transport submissions and preserve order/audit continuity.

Site owners remain responsible for documenting SooCool as a transport/data recipient in their own privacy information where applicable.

== Uninstall ==

Uninstall removes plugin settings, plugin-owned logs, locks, transients and temporary e-mail-label files. SooCool order metadata remains on WooCommerce orders for historical and audit continuity.

== Changelog ==

= 0.7.147 =
* Maakt remote SooCool-statusmapping taakbewust: orderstatussen hebben voorrang, pickup-taskstatussen kunnen de hele order niet meer ten onrechte afronden en alleen eenduidige delivery-taskstatussen worden als fallback toegepast.
* Laat ambigue of ongescopeerde task-statussen fail-closed zonder de WooCommerce-orderstatus te wijzigen en maakt de uitkomst onafhankelijk van de volgorde van pickup- en delivery-taken in API- en webhookpayloads.
* Herstelt verouderde SooCool-koppelingen automatisch en voorkomt een harde reload van het WooCommerce-orderbewerkingsscherm nadat de beheerder de pagina heeft gewijzigd; bestaande remote orders worden veilig gekoppeld zonder dubbele SooCool-order.

= 0.7.108 =
* Scheidt echte synchronisatiefouten van fouten tijdens bijwerken, vernieuwen en annuleren van al gekoppelde SooCool-orders.
* Herstelt gekoppelde legacy `failed`/`pending` state veilig zonder een onjuiste nieuwe synchronisatie te starten; de laatste fout blijft zichtbaar voor handmatige controle.
* Voorkomt dat een stale queuejob uit een oude API-context een nieuwere geldige orderstate overschrijft.

= 0.7.107 =
* Binds queued sync, webhook, label and stored SooCool order state to the active provider/account context so stale work fails closed after API configuration changes.
* Preserves provider Retry-After/cooldown semantics for API and PDF label flows and revalidates transport eligibility before background dispatch.
* Shows the calculated pickup date in the WooCommerce order card; delivery on 20-08-2026 is displayed with pickup on 19-08-2026 instead of only a weekday label.

= 0.7.106 =
* Invalidates a successful connection test when the active API key, effective API base URL, environment or plugin version changes, preventing stale readiness from being shown as current.
* Extends bounded webhook maintenance cleanup to expired plugin mutex rows left behind by an aborted worker, using exact-value deletion so a renewed live lock cannot be removed by the cleanup pass.

= 0.7.105 =
* Limits background failed-order recovery to retryable local synchronization failures and never re-submits an order that became linked before the queued job executes.
* Keeps definitive SooCool failures/rejections visible for manual review instead of repeatedly queueing them as recoverable failures.
* Clarifies that manual re-synchronization keeps the order-reference duplicate check active.

= 0.7.104 =
* Restores webhook event-order validation by adding the missing sequence and timestamp normalizers, with conflicting or malformed event metadata rejected before order state changes.
* Restores asynchronous admin e-mail label attachment validation and rejects invalid, oversized, symlinked or non-temporary cached PDF files while cleaning rejected temporary files.
* Keeps the existing WooCommerce, HPOS, Checkout Blocks and public hook/REST compatibility contracts unchanged.

= 0.7.103 =
* Guards group-wide Action Scheduler cleanup with the same initialization policy used by scheduling and retry paths, while WP-Cron cleanup still always runs.
* Keeps direct uninstall loading complete by loading the Action Scheduler readiness helper before the order cleanup class.
* Reduces `readme.txt` below the WordPress.org 10 KB risk threshold and keeps historical release notes in `changelog.txt`.
* Reverts the unintended delivery-settings grid spacing drift from 16px to the prior 14px without changing settings or checkout behavior.

= 0.7.102 =
* Prevents overlapping partial settings saves from overwriting a newer change by another request.
* Uses Action Scheduler only after initialization for normal order and e-mail-label scheduling paths, with WP-Cron fallback before initialization.
* Removes confirmed unused admin CSS and corrects release-history metadata.

Full historical release notes are included in `changelog.txt`.
