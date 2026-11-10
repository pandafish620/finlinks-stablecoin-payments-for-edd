# Old to new names

Plugin Name, folder, text domain, and WordPress.org slug stay:

- Plugin Name: `FinLinks Stablecoin Payments for EDD`
- Folder and slug: `finlinks-stablecoin-payments-for-edd`
- Text domain: `finlinks-stablecoin-payments-for-edd`

Version 1.1.0 moves plugin-owned globals to the `finlstpa` prefix. There are no compatibility wrappers for old PHP classes, functions, hooks, or options.

| Old | New |
| --- | --- |
| namespace `FinLinks\EDD` | namespace `Finlstpa\EDD` |
| `FINLINKS_EDD_VERSION` | `FINLSTPA_VERSION` |
| `FINLINKS_EDD_FILE` | `FINLSTPA_FILE` |
| `FINLINKS_EDD_DIR` | `FINLSTPA_DIR` |
| `FINLINKS_EDD_URL` | `FINLSTPA_URL` |
| class `Finlinks_Amount` | `Amount` |
| class `Finlinks_Api_Base` | `Api_Base` |
| class `Finlinks_Api_Client` | `Api_Client` |
| class `Finlinks_Credentials` | `Credentials` |
| class `Finlinks_Hosted_Url` | `Hosted_Url` |
| class `Finlinks_Logger` | `Logger` |
| class `Finlinks_Messages` | `Messages` |
| class `Finlinks_Session_Decider` | `Session_Decider` |
| class `Finlinks_Signature` | `Signature` |
| class `Finlinks_Timing` | `Timing` |
| class `Finlinks_Webhook_Processor` | `Webhook_Processor` |
| interface `Finlinks_Event_Store` | `Event_Claim_Store` |
| class `Finlinks_Memory_Event_Store` | `Memory_Event_Store` |
| option `finlinks_edd_settings` | `finlstpa_settings` |
| option `finlinks_edd_db_version` | `finlstpa_db_version` |
| option `finlinks_edd_clock` | `finlstpa_clock` |
| option `finlinks_edd_stale_webhook` | `finlstpa_stale_webhook` |
| option `finlinks_edd_pause` | `finlstpa_pause` |
| option `finlstpa_schema` | new, value `2` after upgrade |
| option `finlstpa_orders_schema` | new, value `2` after order upgrade |
| option `finlstpa_migration_error` | new, empty after a finished upgrade; `credentials_differ`, `credentials_unreadable`, or `event_copy_failed` while the old data is kept |
| transient `finlinks_edd_notice_{user}` | `finlstpa_notice_{user}` |
| lock option `finlinks_edd_lock_*` | `finlstpa_lock_*` (old leases are deleted, not copied) |
| cron hook `finlinks_edd_reconcile` | `finlstpa_reconcile` |
| cron schedule `finlinks_edd_ten_minutes` | `finlstpa_ten_minutes` |
| table `{prefix}finlinks_edd_webhook_events` | `{prefix}finlstpa_webhook_events` |
| REST `finlinks-edd/v1/webhook` | `finlstpa/v1/webhook` |
| admin post `finlinks_edd_save` | `finlstpa_save` |
| admin post and AJAX `finlinks_edd_tools` | `finlstpa_tools` |
| nonce action `finlinks_edd_admin` | `finlstpa_save` and `finlstpa_tools` |
| script handle `finlinks-edd-admin` | `finlstpa-admin` |
| JS global `FinLinksEDD` | `finlstpa_admin` |
| settings page `finlinks-edd-settings` | `finlstpa-settings` |
| gateway id `finlinks_usdc` | `finlstpa_usdc` |
| meta `_finlinks_edd_session` | `_finlstpa_session` |
| meta `_finlinks_edd_last_status` | `_finlstpa_last_status` |
| meta `_finlinks_edd_transaction_hash` | `_finlstpa_transaction_hash` |
| meta `_finlinks_edd_store_order_id` | `_finlstpa_store_order_id` |
| meta `_finlinks_edd_note_{hash}` | `_finlstpa_note_{hash}` |
| error `finlinks_edd_checkout` | `finlstpa_checkout` |
| error `finlinks_edd_busy` | `finlstpa_busy` |
| error `finlinks_edd_unavailable` | `finlstpa_unavailable` |
| uninstall globals `$finlinks_edd_*` | function `finlstpa_uninstall()` |

## Kept on purpose

These are payment-service or Easy Digital Downloads contracts, not WordPress plugin globals:

- API host `https://api.finlinks.io` and paths under `/api/v1/commerce/`
- Header names `Finlinks-Signature`, `Finlinks-Timestamp`, and `Finlinks-Event-Id`
- User-Agent `FinLinks-Stablecoin-Payments-EDD/{version}`
- Store order id `edd-{order id}`
- API key prefixes `flk_live_` and `flk_test_`
- Service error codes such as `COMMERCE_SESSION_AMOUNT_CHANGED`
- Internal connect codes `FINLINKS_API_BASE_MISMATCH`, `FINLINKS_CREDENTIALS_REJECTED`, `FINLINKS_CONNECT_CANCELLED`, and `FINLINKS_ROTATION_UNCERTAIN` (message map only; this plugin does not send them)
- EDD hooks `edd_payment_gateways`, `edd_enabled_payment_gateways`, `edd-gateway` nonce, and the dynamic `edd_{gateway}_cc_form` / `edd_gateway_{gateway}` hooks
- Hosted payment hosts `portal.finlinks.io` and `pay.finlinks.io`
- Text domain `finlinks-stablecoin-payments-for-edd`

## One route exception

`finlinks-edd/v1/webhook` stays registered. It runs the same HMAC verifier as `finlstpa/v1/webhook`. It exists only so a connection that was already registered, or a session created before the upgrade, can still deliver a signed notification. It is not a class, function, option, or cron alias. New sessions and the settings screen use `finlstpa/v1/webhook`.
