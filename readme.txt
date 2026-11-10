=== FinLinks Stablecoin Payments for EDD ===
Contributors: pandafish620
Tags: usdc, payments, easy digital downloads, checkout, stablecoin
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDC payments in Easy Digital Downloads through a FinLinks hosted payment page.

== Description ==

Adds a Pay with USDC payment method for Easy Digital Downloads. The buyer pays on a FinLinks hosted page. The download becomes available only after FinLinks reports the payment as confirmed and this plugin has checked that result with the FinLinks API. Opening the receipt page is not payment confirmation.

= Before you install =

* Easy Digital Downloads 3.0 or newer is installed and active. Without it, this plugin does not register a payment gateway.
* WordPress can install plugins from the dashboard. If it cannot, ask the host to make the WordPress folder writable by the web server.
* The site uses HTTPS and is publicly reachable, so payment confirmations can be delivered to the store.
* Pretty permalinks are enabled. The payment notification uses a WordPress REST address and is not registered when plain permalinks add a query string.
* You have a FinLinks merchant account. Installing this plugin does not open that account.
* The store currency is USD.
* Each order total is from 1.00 USD through 10,000.00 USD. This is the range the plugin enforces. Orders outside that range are not offered Pay with USDC.

Use this gateway with Easy Digital Downloads in Live mode. With a live API key, Pay with USDC is hidden at checkout while EDD Test mode is on. Do not use Test mode or a custom API URL. Live requests go only to https://api.finlinks.io.

* One-time orders in USD, from 1.00 USD through 10,000.00 USD.
* Signed payment notifications (HMAC-SHA256, a five-minute replay window, and duplicate protection) plus a backup status check about every ten minutes.
* A settings warning when the store clock differs from FinLinks by more than two minutes.
* API key and webhook signing secret fields are masked. Leave a field blank to keep the saved value.

The services this plugin contacts are listed under External services.

== External services ==

FinLinks is an external service operated by Finlinks Global Solutions LLC (Wyoming, United States). An approved FinLinks merchant account is required. Merchant portal access is currently invitation-only. Downloading or activating this plugin does not activate payment services.

Product website: https://www.finlinksglobal.com/
Merchant Services Agreement: https://www.finlinksglobal.com/legal/merchant-services-agreement.html
Website Terms: https://www.finlinksglobal.com/legal/terms.html
Privacy Policy: https://www.finlinksglobal.com/legal/privacy.html
Merchant portal: https://portal.finlinks.io
Source code: https://github.com/pandafish620/finlinks-stablecoin-payments-for-edd

= api.finlinks.io =

The store server contacts https://api.finlinks.io when an administrator tests the connection, registers or verifies the webhook, or sends a test webhook. It also contacts the API when a buyer starts Pay with USDC, when an open session must be cancelled because the order amount changed, and when payment status is checked. Status checks include the signed payment notification and the backup check about every ten minutes.

Authenticated calls send the API key as a bearer token, an Accept header of application/json, and a user-agent of FinLinks-Stablecoin-Payments-EDD/ followed by the plugin version. The FinLinks API also sees the store server's IP address as the source of the HTTPS request. The plugin does not send a separate buyer IP address, name, email, phone, or postal address field.

Webhook registration sends the store webhook URL, the Easy Digital Downloads version, and the plugin version (PUT /api/v1/commerce/connection/webhook). Connection verification (POST /api/v1/commerce/connection/verify), the connection test (GET /api/v1/commerce/connection), and the test webhook (POST /api/v1/commerce/connection/test-webhook) send those authenticated headers and no order contents.

Creating a payment session (POST /api/v1/commerce/sessions) sends the store order id (edd- followed by the Easy Digital Downloads order number), an order label of the form EDD Order # and that number, the amount, the currency USD, the receipt return URL, the checkout cancel URL, and the webhook URL. The return URL can include the EDD payment key and, when Easy Digital Downloads provides one, a receipt hash and order id. Do not put other personal information in those URLs. Cancelling a replaced session (POST /api/v1/commerce/sessions/{session id}/cancel) and reading a session (GET /api/v1/commerce/sessions/{session id}) send the session id in the path. These calls are used to start payment, confirm it, and update the Easy Digital Downloads order. FinLinks sends signed POST notifications to the store REST address /wp-json/finlstpa/v1/webhook. A store that already registered /wp-json/finlinks-edd/v1/webhook still accepts signed notifications at that address. The plugin verifies the raw body before it asks the API for the session.

= portal.finlinks.io and pay.finlinks.io =

After a payment session is created, the buyer's browser opens the hosted payment page. The address is https, the path is /inv/ plus the session id, and in Live mode the host is only portal.finlinks.io or pay.finlinks.io. This plugin does not POST the order to those sites. The browser request includes the visitor's IP address and the browser user-agent. Information entered on the hosted page is covered by the FinLinks Privacy Policy.

These hosts and finlinks.io email are part of the service operated by Finlinks Global Solutions LLC. Their domain differs from the product website at www.finlinksglobal.com.

= Custom API address =

Do not use Easy Digital Downloads Test mode, and do not enter a custom API URL. In Live mode the plugin ignores any custom API address and sends the requests above only to https://api.finlinks.io. Saving settings while Live mode is on clears a custom API address. Pay with USDC stays hidden at checkout while Test mode is on if the saved key is a live key. A different HTTPS address is used only if an administrator turns Test mode on and saves one. Leave Test mode off so that does not happen.

== Installation ==

1. Install and activate Easy Digital Downloads 3.0 or newer. Set the store currency to USD, turn on pretty permalinks, and serve the site over HTTPS.
2. In WordPress, open Plugins > Add New, install FinLinks Stablecoin Payments for EDD, and activate it.
3. Leave Easy Digital Downloads in Live mode under Downloads > Settings > Payments. Do not turn on Test mode, and do not enter a custom API URL.
4. Open Downloads > FinLinks USDC. Paste the live API key and webhook signing secret from the FinLinks merchant portal, then save.
5. Use Test Connection, then Register Webhook & Verify Store, then Test Webhook.
6. Check Allow USDC payments, and enable FinLinks USDC under Downloads > Settings > Payments.

== Frequently Asked Questions ==

= When does the buyer get the download? =

Only after a signed payment notification, or the backup status check, and a confirmation request to the FinLinks API. Returning to the receipt page does not complete the order or grant the download.

= Why is Pay with USDC missing at checkout? =

The store currency must be USD and the order total must be from 1.00 USD through 10,000.00 USD. Easy Digital Downloads must be in Live mode. Save a live API key and webhook signing secret, check Allow USDC payments, and enable FinLinks USDC under Downloads > Settings > Payments. With a live API key, the method is hidden at checkout while EDD Test mode is on. It is also hidden for up to five minutes after FinLinks rejects the connection, for example when a key is revoked. Correct the connection, then use Test Connection.

= What happens if the paid amount does not match? =

If the confirmed amount or currency does not match the order, the order stays pending for review and receives a note. A partial payment stays pending and receives a note. An expired, cancelled, or failed session is marked failed and does not grant the download.

= Where is the transaction hash stored? =

When the confirmed FinLinks session includes a transaction hash, the plugin stores it as the Easy Digital Downloads transaction ID, in the order meta field _finlstpa_transaction_hash, and in the payment note.

= Are refunds supported? =

Not from Easy Digital Downloads in this version. Refunding an order in Easy Digital Downloads does not start a refund through FinLinks. Handle refunds in the FinLinks merchant portal at https://portal.finlinks.io.

= Does uninstall delete orders? =

No. Uninstall deletes this plugin's settings, credentials, and operational data. Easy Digital Downloads orders, transactions, and notes remain.

== Support ==

* WordPress.org support forum: https://wordpress.org/support/plugin/finlinks-stablecoin-payments-for-edd/
* Merchant portal: https://portal.finlinks.io
* Email: support@finlinks.io

== Changelog ==

= 1.1.1 =
* Record the stage of a Pay with USDC checkout that does not redirect. The FinLinks USDC screen shows that stage, a short detail token, and the HTTP status. It does not show the API key, webhook secret, buyer email, or payment key.
* Continue an in-progress order that still has the previous gateway id.
* Do not treat an unresolved official payment host as a private redirect. A private or reserved answer is still refused. API calls still stop when the API host does not resolve.
* Allow checkout session creation the same 30 seconds already used for store verification.

= 1.1.0 =
* Use the finlstpa prefix for plugin hooks, options, scheduled tasks, and order meta.
* Move existing API keys, webhook secrets, orders, and payment sessions to the new storage names. Credentials are copied unchanged and are not printed.
* Keep accepting signed payment notifications at the previously registered webhook address.
* Require a shop-manager capability and a nonce for settings changes. Checkout and signed payment notifications do not require a WordPress login.
* Reject local and private hosts for test API addresses and hosted payment redirects.

= 1.0.0 =
* Initial release for Easy Digital Downloads 3.0 and newer.
* Pay with USDC on a FinLinks hosted page for one-time USD orders from 1.00 through 10,000.00.
* Complete an order only after a signed payment notification or the backup check, confirmed with the FinLinks API.
* Live mode calls only https://api.finlinks.io. Do not use EDD Test mode or a custom API URL.
