# Security Policy

Report security issues privately to the project maintainer. Do not open public issues for secrets, authentication bypasses, data exposure, order data leaks, or API credential handling.

## Handling secrets

- Never commit API keys or portal credentials.
- Never include API keys in screenshots, logs, error reports or GitHub issues.
- Rotate keys after sharing them in email, chat or staging environments.

## External service and privacy

The plugin sends selected WooCommerce order, address, contact, pickup and package data to the official SooCool API hosts only when configured actions are run. See `readme.txt` for the full external-service disclosure.

## Public reports

When sharing screenshots or logs, remove API keys, portal credentials, customer names, customer addresses, phone numbers, email addresses, order IDs and SooCool trace IDs unless the recipient is authorized to receive them.
