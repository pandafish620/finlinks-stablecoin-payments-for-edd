=== FinLinks Stablecoin Payments for Easy Digital Downloads ===
Contributors: finlinks
Tags: usdc, payments, easy digital downloads, stablecoin
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDC through FinLinks hosted checkout with signed payment confirmation for Easy Digital Downloads.

== Description ==

Adds a Pay with USDC gateway for Easy Digital Downloads 3.0 or newer. Buyers complete payment on a FinLinks hosted page. An authenticated session lookup confirms the order, amount, currency, environment and session identity before the plugin marks an EDD order complete. Returning to the receipt page is never payment confirmation.

* One-time USD purchases, from USD 1.00 to USD 10,000.00.
* Register, verify and test the webhook from Downloads > FinLinks USDC.
* Masked API-key and webhook-secret fields. Leave blank to retain saved credentials.
* EDD test mode requires test credentials and an explicit HTTPS staging API base.
* Signed HMAC-SHA256 webhooks with a 5-minute replay window and persistent event deduplication.
* Atomic checkout and order leases prevent overlapping work; a 10-minute backup check reconciles recent unpaid orders.
* Transaction hashes supplied by FinLinks are saved in EDD transaction records, order metadata and notes.
* Namespaced classes and independent options, order IDs and webhook route can coexist with the WooCommerce plugin.
* Updates use WordPress.org only after approval and publication. There is no private updater.

A FinLinks merchant account and an EDD-compatible dedicated commerce connection are required. Do not share a WooCommerce connection: registering the EDD webhook changes that connection's webhook URL. The server must accept storeOrderId values such as edd-123 and the independent callback route /wp-json/finlinks-edd/v1/webhook. This plugin does not call the WooCommerce-only OAuth endpoints.

This release does not implement automatic recurring billing, card collection, automatic FX conversion or refunds from EDD. Handle refunds through the merchant service workflow; an EDD refund action does not initiate an on-chain refund. Do not enable this gateway for subscription renewals.

== External services ==

This plugin connects to FinLinks when an administrator runs connection tools, a buyer starts checkout, or recent unpaid orders are reconciled. It does not send the buyer's name, email, phone or billing address to FinLinks.

= api.finlinks.io =

Production requests use HTTPS and the saved API key as a bearer token. Calls retrieve connection status, register the webhook URL, verify the store, send a test webhook, create payment sessions and retrieve confirmed session state. Session creation includes a namespaced order identifier (edd-123), order label, amount, currency and store return/cancel/callback URLs. Return URLs include an EDD receipt key or receipt hash needed to restore the buyer's receipt. Do not share these URLs.

FinLinks sends signed POST requests to /wp-json/finlinks-edd/v1/webhook. The original raw request body is verified without modifying it. The gateway then calls the authenticated API before updating an order.

= portal.finlinks.io and pay.finlinks.io =

The buyer's browser opens an HTTPS /inv/{sessionId} page on an approved FinLinks host. These hosts receive the session URL, not a direct POST of buyer details from this plugin. A hosted page does not grant downloads by itself.

= Staging API =

When EDD test mode is enabled, an administrator must configure an HTTPS non-production API. It receives the same types of request with a test key. Live mode ignores the staging override and calls only api.finlinks.io.

FinLinks Terms: https://www.finlinksglobal.com/legal/terms.html
FinLinks Privacy Policy: https://www.finlinksglobal.com/legal/privacy.html
Merchant portal: https://portal.finlinks.io

Credentials are stored in a non-autoloaded WordPress option and are never rendered back into the settings page. Database and backup security remain the site operator's responsibility. Optional diagnostic logging uses EDD's logger with credential, bearer-token, signature and URL-query redaction. Redacted logs may contain order/session identifiers.

== Installation ==

1. Install Easy Digital Downloads 3.0 or newer and configure a USD store with HTTPS, pretty permalinks and native EDD checkout/receipt pages.
2. Upload this ZIP under Plugins > Add New > Upload Plugin and activate it.
3. Open Downloads > FinLinks USDC. Paste the API key and webhook signing secret for a dedicated EDD connection. Select the desired EDD test/live mode in EDD settings. In test mode, supply an HTTPS staging API base; the production API is never used for test mode.
4. Save settings and use Test Connection, then Register Webhook & Verify Store, then Test Webhook.
5. Enable Allow USDC payments and enable FinLinks USDC in Downloads > Settings > Payments.
6. Complete a staging purchase, verify the signed callback and API recheck, confirm the EDD order is complete and the transaction hash is recorded, then verify download permissions.
7. For live use, turn EDD test mode off, save live credentials, repeat connection verification, and perform an authorized live acceptance test.

== Frequently Asked Questions ==

= Does this plugin need WooCommerce? =

No. The API, amount, signature, session-decision and redaction code was adapted from FinLinks' WooCommerce 1.1.1 release into an independent namespace. It uses EDD 3.x order APIs.

= Is returning to the success page enough to unlock a download? =

No. Only a signed event or backup reconciliation followed by an authenticated payment-session lookup can complete an order. EDD controls its normal digital-download permissions.

= Can both FinLinks plugins use the same credentials? =

Use a dedicated EDD connection. The callback route and namespaced EDD order identifiers are separate. The backend must support these values before the gateway is used live.

= Where is the transaction hash stored? =

When supplied by the confirmed API session, it is stored as the EDD transaction ID, in _finlinks_edd_transaction_hash order metadata, and in the payment-confirmation note.

= Where do failed or partial payments go? =

A confirmed amount or currency mismatch remains pending for manual review. Partial payments stay pending and receive a note. Expired, cancelled or failed sessions use EDD's failed status and never grant downloads.

= Does uninstall delete orders? =

No. Uninstall deletes plugin credentials, operational event data and schedules. EDD orders, transactions, metadata and notes are retained.

== Changelog ==

= 1.0.0 =
* Initial independent EDD 3.x gateway with hosted USDC checkout and confirmed order completion.
* Reuse the FinLinks WooCommerce 1.1.1 payment/security core with isolated EDD adapters.
* Include nonce-protected settings, connection/webhook tests, deduplication and backup reconciliation.

