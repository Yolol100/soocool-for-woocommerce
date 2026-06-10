# Privacy and external service disclosure

SooCool for WooCommerce connects WooCommerce order data to the SooCool transport API when an authorized shop manager tests the connection, manually submits an order, downloads a shipping label, or when automatic submission is explicitly enabled.

## External service

The plugin can contact these SooCool API hosts:

- Staging: `https://api.staging.soocool.nl`
- Production: `https://api.soocool.nl`

The configured API key is sent server-side through the `X-API-Key` header. The key is masked in admin responses and is not intentionally written to plugin logs.

## Data that may be sent

Depending on the action, the plugin may send WooCommerce order references, pickup address data, delivery address data, customer contact details needed for transport, package descriptions, SooCool task windows and package identifiers.

## Storage

The plugin stores SooCool settings in WordPress options and stores sanitized sync status, SooCool order identifiers and error status on WooCommerce orders. Sanitized plugin logs are stored in WordPress options and can be cleared from the plugin settings.

Site owners remain responsible for documenting SooCool as a transport/logistics processor in their own privacy policy and data-processing agreements where applicable.
