# Architecture and source provenance

## Core reused from WooCommerce 1.1.1

includes/core contains the existing decimal amount handling, production API resolver, credential rules, hosted URL allowlist, HMAC signature verifier, timing helpers, confirmed-session state machine, webhook processor, API client, messages and redaction logic.

The files are adapted into the FinLinks\\EDD namespace and the finlinks-stablecoin-payments-for-edd text domain. Version constants, platform version reporting, EDD logging and clock-option storage are adapter-specific. No WooCommerce runtime dependency remains. The signature algorithm, replay tolerance and core decimal/session decision algorithms are preserved.

WordPress.org handles future updates after acceptance and publication. There is no updater module, Update URI header, GitHub update check or downloaded executable code.

## EDD adapters

- Bootstrap: EDD dependency guard, schema, REST registration and ten-minute WP-Cron.
- Settings: masked non-autoloaded credential storage, EDD-mode selection, capability/nonce enforcement and connection tools.
- Gateway: edd_payment_gateways registration, edd_finlinks_usdc_cc_form explanation and edd_gateway_finlinks_usdc checkout handling.
- Order_Sync: authenticated session lookup and native EDD order/transaction/note APIs.
- Event_Store: custom-table INSERT IGNORE with a primary event_id key and eight-day retention.
- Lock: expiring, token-owned WordPress-option leases with atomic compare-and-delete for recovery and release.

The checkout intent is keyed by the EDD purchase key. The API's idempotent identity is edd-<order ID>; a retry uses the same identity. Order updates use a separate lease shared between checkout and synchronization.

## Checkout request

POST /api/v1/commerce/sessions receives storeOrderId, orderLabel, amount, currency, returnUrl, cancelUrl and callbackUrl. Buyer name, email, phone and billing address are not added to the API request.

The response must include a session ID, matching storeOrderId, boolean livemode, amount, currency and an approved hostedUrl. A failed or mismatched response leaves the EDD order unpaid.

The API key is a server-side bearer token. Its value is not included in redirects, JavaScript or order notes.

## Callback flow

POST /wp-json/finlinks-edd/v1/webhook receives the raw body and Finlinks-Timestamp / Finlinks-Signature headers.

1. Enforce the payload-size limit.
2. Verify HMAC-SHA256 over timestamp + dot + raw body, with the unchanged five-minute replay tolerance.
3. Atomically claim the event ID.
4. Resolve only edd-<id> sale orders belonging to finlinks_usdc.
5. Acquire the order lease and match its currently attached session.
6. GET /api/v1/commerce/sessions/<session ID> with the saved API key.
7. Recheck session ID, order identity and environment; use the reused state machine for amount/currency and state decisions.
8. Save the transaction hash supplied by the confirmed session through EDD's transaction API, store audit metadata and add an idempotent note.
9. Use edd_update_order_status(..., 'complete') so EDD applies its native purchase completion behavior.

Transient failures return HTTP 503 and release the event claim for retry. Duplicate event IDs are acknowledged without repeating the business handler. A tampered signature returns HTTP 401. A signed but stale callback is retryable without processing it.

A browser redirect never performs a payment status transition.

## FinLinks backend acceptance requirements

The backend must provision an EDD-compatible connection, allow the dedicated callback, accept namespaced order IDs, and support the existing connection/session endpoints. The plugin deliberately does not call the WooCommerce-only /woo/connect OAuth flow or invent an EDD OAuth endpoint.

GET/PUT connection, verify and test-webhook endpoints are unchanged from the WooCommerce client. platformVersion reports EDD_VERSION; pluginVersion reports this gateway release.

## Compatibility evidence

The gateway API, order APIs, transaction functions and note arguments were checked against official EDD 3.0 and 3.7.2 source packages. Isolated integration results are delivered separately. They use real WordPress and EDD persistence through the official SQLite integration, with FinLinks HTTP responses mocked and email suppressed.

This verifies adapter behavior, not real FinLinks provisioning, chain settlement, MySQL production behavior or official plugin-directory approval.

