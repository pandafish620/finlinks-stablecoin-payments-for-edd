# Refactor checklist

Reviewed submission was 1.0.0. This branch is 1.1.0. The WordPress.org slug, plugin folder, Plugin Name, and text domain are unchanged.

## File checklist

| File | Result |
| --- | --- |
| `finlinks-stablecoin-payments-for-edd.php` | Namespace `Finlstpa\EDD`, constants `FINLSTPA_*`, version 1.1.0, loader keeps no global variables |
| `includes/class-bootstrap.php` | New cron, table, REST namespace; legacy signed route only; webhook permission stays unsigned |
| `includes/class-settings.php` | Options, nonces, AJAX action, page, and script use `finlstpa`; shop-manager capability; no guest AJAX |
| `includes/class-gateway.php` | Gateway id and meta keys renamed; checkout still uses the EDD nonce and allows guests |
| `includes/class-order-sync.php` | Session id, store order id, reason, note, and transaction hash checked before write |
| `includes/class-lock.php` | Lease options use `finlstpa_lock_` |
| `includes/class-event-store.php` | Claims go to the new table |
| `includes/class-migrate.php` | Versioned copy of settings, state, events, orders, and EDD gateway settings |
| `includes/core/class-*.php` | Former `Finlinks_*` classes now live in `Finlstpa\EDD` without that prefix |
| `includes/core/class-host-policy.php` | Rejects local, private, and reserved hosts |
| `includes/core/class-api-base.php` | Test base URL must pass the host policy; live mode still calls only `https://api.finlinks.io` |
| `includes/core/class-hosted-url.php` | Buyer redirect target must pass the host policy |
| `includes/core/class-webhook-processor.php` | Event type and challenge are bounded; signature order is unchanged |
| `includes/core/class-session-decider.php` | Order-note fields are constrained; paid/unpaid decisions are unchanged |
| `includes/core/class-logger.php` | Logs only when diagnostics are enabled, with the same redaction |
| `assets/js/admin.js` | Global `finlstpa_admin`; action `finlstpa_tools` |
| `uninstall.php` | `finlstpa_uninstall()` removes current and leftover 1.0.0 operational rows |
| `readme.txt` | Stable tag 1.1.0, new webhook path, new transaction-hash meta key |
| `languages/*.pot` | Regenerated from the renamed sources |

Payment logic, API paths, signature formula, and event idempotency are unchanged.

## Migration

`Migrate::run()` is idempotent and runs from activation and `plugins_loaded`.

1. Create `{prefix}finlstpa_webhook_events` if needed.
2. Copy valid event ids from `{prefix}finlinks_edd_webhook_events`, then drop the old table.
3. Copy `finlinks_edd_settings` into `finlstpa_settings`. `api_key` and `webhook_secret` are copied as the same strings when they contain no control characters and are at most 256 bytes. The old row is deleted only after `hash_equals` confirms the new row. There is no encryption layer and no additional authenticated data, so renaming the option does not re-key a ciphertext.
4. If `finlstpa_settings` already has a key or secret, that row is kept and the old row is deleted.
5. Copy clock, stale-webhook, and pause values when the new options are empty. Delete `finlinks_edd_db_version`.
6. Unschedule `finlinks_edd_reconcile`. Do not register it again.
7. Delete `finlinks_edd_lock_*` and `finlinks_edd_` admin-notice transients. Active 1.0.0 leases are not resumed.
8. When Easy Digital Downloads order tables exist, set `gateway` from `finlinks_usdc` to `finlstpa_usdc`, rename the order meta keys above, and update `edd_settings` gateway entries.
9. Set `finlstpa_schema` to `2` only when the legacy options, cron event, event table, locks, and notice transients are gone.
10. Set `finlstpa_orders_schema` to `2` only when no `finlinks_usdc` orders and no legacy meta keys remain.

Uninstall still does not delete Easy Digital Downloads orders, transaction ids, or order notes.

## Security findings and fixes

| Finding | Fix |
| --- | --- |
| Prefixes `FinLinks` and `finlinks_edd` can collide with other plugins | Renamed plugin-owned globals. No `function_exists` or `class_exists` wrapper around our own symbols |
| Settings and tools trusted the shop screen | `manage_shop_settings` plus separate nonces for save and tools. No `wp_ajax_nopriv` hook |
| Checkout must keep working for guests | Still verifies Easy Digital Downloads nonce action `edd-gateway`. No login requirement |
| Webhook must accept a signed server POST | `permission_callback` returns true. The body is accepted only after HMAC verification. Bad signatures return 401. Replays of `payment_session.*` return duplicate and do not change the order again |
| A logged-in shopper could resume another customer's order when both user ids are set | Checkout now refuses that pair. Guest orders stay allowed |
| Test API URL and hosted URL could point at a loopback or private address | `Host_Policy` rejects those hosts before the request or redirect |
| Hosted URL could redirect off the allow-list | Redirects that are neither an allow-listed hosted URL nor this site go back to checkout |
| Webhook challenge and event type were reflected or dispatched with little shape checking | Event types must match a short token. Challenges must be non-empty, at most 512 bytes, and free of control characters |
| Session id, store order id, transaction hash, and order note could be stored from a loose payload | Ids are checked against the existing token rules. Notes are passed through `sanitize_textarea_field` and capped. Amounts shown in notes must be decimal strings |
| Connection status from the API was printed | Status must match `[A-Z0-9_]{1,32}` or it is shown as `UNKNOWN` |
| Diagnostics could be mistaken for always-on error logs | Nothing is logged unless the merchant enables redacted diagnostics |
| Old lock and notice rows would have kept the short prefix after upgrade | They are deleted |

Admin AJAX and `admin-post` both require a logged-in shop manager. The checkout route and the webhook route do not.

## Residual risk

- Test mode can still be pointed at a public hostname. That hostname is not resolved, so a name that later points at a private address is not re-checked. Live mode never uses the override.
- `finlinks-edd/v1/webhook` remains a signed compatibility route. It is the only old public entry that stays.
- A checkout that was already open on gateway id `finlinks_usdc` must be restarted after the upgrade. Saved orders are rewritten to `finlstpa_usdc`.
- Old checkout leases are dropped. A payment that was inside the 180 second lease at the moment of upgrade can be started once more. Session creation is still confirmed with the API before an order is completed.
- Order meta that does not use the known keys, including a note key whose suffix is not 64 hex characters, is not rewritten.
- Uninstall leaves order meta so order history remains, including session ids. It removes settings and the webhook secret.
- Credentials are still stored in the WordPress options table as strings, same as 1.0.0. They are not written to the log by this upgrade.
- This plugin does not call production, move funds, or upload to WordPress.org.
