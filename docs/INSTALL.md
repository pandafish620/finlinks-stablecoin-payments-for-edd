# Install and validate the FinLinks EDD gateway

## Requirements

- WordPress 6.4+; PHP 7.4+; Easy Digital Downloads 3.0+.
- A USD store; one-time totals from USD 1.00 through USD 10,000.00.
- Public HTTPS store and receipt URLs, pretty permalinks and a reachable WordPress REST API.
- A dedicated FinLinks commerce connection for EDD. The server must accept opaque storeOrderId values such as edd-123 and the /wp-json/finlinks-edd/v1/webhook callback.
- A service environment with the same payment-session API used by FinLinks WooCommerce 1.1.1. This plugin does not provision a merchant account or add EDD support to the FinLinks backend.

## Install

1. In WordPress, upload the plugin ZIP under Plugins > Add New > Upload Plugin.
2. Activate it after EDD is active. A missing or older EDD dependency leaves the gateway inactive and displays an administrator notice.
3. Configure the native EDD checkout and receipt/confirmation pages. Serve both over HTTPS.
4. Open Downloads > FinLinks USDC. Save the API key and matching webhook signing secret. Password fields never display stored values; blank retains a value, and explicit removal requires disabling the gateway.
5. Enable EDD test mode for a staging test. A test key and an explicit HTTPS staging API base are required. Live mode ignores and clears the staging override, calls only https://api.finlinks.io, and requires an flk_live_ key.
6. Run Test Connection, Register Webhook & Verify Store, and Test Webhook.
7. Select Allow USDC payments, and enable FinLinks USDC separately in Downloads > Settings > Payments.
8. Complete the acceptance checklist below before using live credentials.

WooCommerce and EDD must not share the same FinLinks connection. Registering a webhook updates the connection's callback URL. The EDD plugin uses its own WordPress options, event table, order namespace and callback route.

## Acceptance checklist

- A supported cart displays Pay with USDC without credit-card fields.
- An unsupported currency or amount does not offer this method.
- Starting checkout creates one native EDD pending order and a hosted payment URL.
- Repeating the same EDD purchase key reuses its order and server session identity.
- Only an approved FinLinks HTTPS invoice URL is used for the redirect.
- Buyer return/receipt navigation never marks an order complete.
- The signed callback passes HMAC/timestamp checks; an authenticated GET rechecks the session, order, mode, amount and currency.
- EDD changes the order to complete and applies its normal digital-download permissions.
- The API's transaction hash appears in the EDD transaction record, _finlinks_edd_transaction_hash order meta and confirmation note.
- Duplicate event IDs do not repeat fulfillment or notes.
- Tampering, an environment mismatch or a wrong order/session cannot grant downloads.
- A paid amount/currency mismatch stays pending for manual review.
- A missed webhook is recovered by the ten-minute WP-Cron check for recent unpaid orders. Configure a real scheduled runner on sites with unreliable traffic-driven WP-Cron.
- A revoked connection temporarily suppresses the payment method; an ACTIVE connection test clears the pause.
- Invalid settings/tool nonces and insufficient capabilities are rejected.
- Test and live credentials are never included in frontend HTML or diagnostic output.

## Limits and operations

This release supports single payments, not recurring subscription charges or automatic FX conversion. EDD refund actions do not initiate an on-chain refund. Handle refunds through the FinLinks merchant service workflow and reconcile the EDD record explicitly.

Finish or reconcile outstanding test orders before changing a store to live mode. A pending order retains its original mode, and a cross-mode response is rejected.

Keep EDD's checkout/receipt pages and payment endpoints outside full-page caches where EDD requires it. Do not protect the webhook with a WordPress login or browser nonce; it is authenticated with the FinLinks raw-body HMAC.

An inaccessible event table produces a retryable HTTP 503 rather than a false duplicate acknowledgement. Investigate database errors and restore the plugin schema before re-enabling checkout.

The connection tools have normal POST fallbacks when JavaScript is unavailable. AJAX checks have a timeout and never display an unconfirmed result as success.

## Credentials and uninstall

Credentials use a non-autoloaded WordPress option, matching the WooCommerce adapter's storage approach. No custom at-rest encryption guarantee is made. Secure WordPress database access, backups and administrator accounts.

Deactivation removes the scheduled reconciliation hook. Uninstall removes plugin settings, credentials, leases, event history and schedules. EDD orders, transactions, metadata and notes remain for audit purposes.

This is an installable test release, not evidence of WordPress.org approval or a successful live FinLinks transaction.

