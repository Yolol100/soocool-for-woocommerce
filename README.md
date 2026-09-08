# SooCool for WooCommerce — Transport API Integration

> **Portfolio project · WordPress/PHP · WooCommerce · REST API · webhooks · HPOS · background processing**

SooCool for WooCommerce connects WooCommerce orders to the SooCool transport platform. It handles order submission, delivery scheduling, status updates, retries and shipping labels while keeping API credentials, webhook handling and order state changes behind explicit safety boundaries.

**Built by:** [Andrew Baeten](https://github.com/Yolol100) · [Portfolio](https://andrewbaeten.nl)

## What problem it solves

Shipping integrations sit between ecommerce, customer data and an external logistics provider. A failure can create duplicate transport orders, stale statuses or broken fulfilment. This plugin turns that integration into a controlled WooCommerce workflow with asynchronous processing, replay protection and explicit compatibility boundaries.

## Portfolio snapshot

| Area | What it demonstrates |
| --- | --- |
| WooCommerce | HPOS-compatible order access and admin workflows |
| API integration | Test/production environments, authenticated requests and remote status handling |
| Webhooks | Timestamped HMAC verification, replay protection and fail-closed state changes |
| Background work | Queued order submission, retries, label prefetch and maintenance recovery |
| Checkout | Delivery-moment selection with server-side validation |
| Privacy & security | Masked API keys, sanitized logging and scoped personal-data handling |
| Compatibility | Real WordPress/WooCommerce runtime matrices and explicit unsupported-state declarations |

## Important engineering choices

- Existing remote orders are linked safely instead of blindly recreated.
- Ambiguous remote task statuses do not overwrite WooCommerce order state.
- Checkout Blocks support stays staging-first until parity is proven.
- HPOS compatibility uses WooCommerce order APIs rather than direct legacy post access.
- Operational logs avoid intentionally storing API keys or complete personal-data payloads.

## Requirements

- WordPress 6.5+
- PHP 8.1+
- WooCommerce
- Current stable version and full compatibility notes: see [`readme.txt`](readme.txt)

## Technical review

Useful places to inspect:

- `src/` — application logic and integration boundaries.
- `tests/` — regression and runtime-oriented coverage.
- `.github/` — automated quality/release workflows.
- `phpcs.xml.dist` — PHP coding-standard configuration.
- [`readme.txt`](readme.txt) — full installation, privacy, compatibility and changelog documentation.

## About the developer

I am **Andrew Baeten**, a WordPress Developer & Web Designer with 10+ years of experience across **70+ WordPress projects**. My work combines WordPress, WooCommerce, Elementor, UX, performance, technical SEO and quality-focused delivery.

[Portfolio](https://andrewbaeten.nl) · [LinkedIn](https://www.linkedin.com/in/andrew-baeten-305a1478/) · [Email](mailto:info@andrewbaeten.nl)

## License

See [`LICENSE`](LICENSE).
