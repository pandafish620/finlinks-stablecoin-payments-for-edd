# FinLinks EDD 1.0.0 delivery

## Download

- Installable ZIP: finlinks-stablecoin-payments-for-edd-1.0.0.zip
- Root folder and text domain: finlinks-stablecoin-payments-for-edd
- Files: 26; PHP files: 20
- Archive bytes: 55462
- SHA-256: 1dff2f03ea477003cff34400f9733a627f135504a125ac4c1c65f101b55a1a98

The ZIP contains the complete plugin source, readme.txt, GPLv2+ LICENSE, native WordPress admin tools, admin JavaScript, POT catalog and installation/architecture documents. It contains no test fixtures, credentials, local database, build dependencies or private updater. The WooCommerce 1.1.1 artifact and frontend repository are unchanged.

## Entry points and setup

- Gateway ID: finlinks_usdc
- Settings: Downloads > FinLinks USDC
- Enable separately in Downloads > Settings > Payments
- Callback: /wp-json/finlinks-edd/v1/webhook
- Store order identity: edd-<native EDD order ID>

Use a dedicated EDD merchant connection. The real FinLinks backend must accept these identifiers and callback URL. The WooCommerce-only OAuth endpoints are not called. Configuration uses the existing manually provisioned API key/webhook-secret workflow, with blank-retains and explicit removal semantics.

Live checkout uses the production API; test mode follows EDD's global mode and requires a separate HTTPS staging API base and test key. Currency/amount eligibility remains USD 1-10,000. This initial gateway is for one-time purchases; it does not initiate EDD refunds or subscription renewal charges.

## Verified

- All 20 PHP files passed php -l on PHP 8.4.27.
- Admin JavaScript passed node --check.
- Plugin Check 2.1.0 ran in WordPress 7.1.3 via WP-CLI default static checks: 0 errors, 0 warnings. No check exclusions or ignore-code flags were used.
- Additional PHPCS security, SQL, prefix and i18n rules passed.
- EDD 3.7.2: 40 integration assertions passed.
- EDD 3.0: the same 40 assertions passed.
- Real EDD order creation, state transitions, transaction records, notes, metadata, event-table deduplication and option leases were used through the official WordPress SQLite integration.
- Tests covered native gateway visibility, amount/currency limits, confirmed completion, native payment-complete recognition, transaction-hash storage, repeated checkout identity, callback tampering/replay, mode mismatch, manual review on amount mismatch, backup reconciliation, nonce/capability rejection and masked settings HTML.
- Merchant HTTP calls were intercepted with fixtures; email was suppressed. No live payment was made.
- ZIP CRC, directory structure, version, text domain and absence of private update modules were verified.

## Limits before production

The tests do not establish that the deployed FinLinks backend already accepts EDD connections, edd-<id>, this callback path or the required session response fields. Confirm those on staging before using real funds. No EDD OAuth endpoint has been invented or assumed.

The database integration test used SQLite, not a production MySQL/MariaDB deployment; parallel-worker load was not stress-tested. The adapter keeps the atomic unique event ID insert and uses token-owned order leases, but operational recovery should be tested on the actual host.

EDD 3.0's own source emits deprecation notices on PHP 8.4 and early-translation notices on current WordPress. Its 40 adapter assertions passed, but the old upstream EDD runtime is not described as warning-free. Prefer a supported EDD/PHP pair for deployment. PHP 7.4 is the declared source minimum; the verification runtime here was PHP 8.4.27.

Full browser checkout/receipt rendering, PCP runtime/browser checks, network/multisite activation, real merchant onboarding and chain settlement remain acceptance tasks. WordPress.org publication/approval is separate from passing static checks.

The verification fixture is outside the installable ZIP and refuses to run unless WP-CLI is active, the environment type is local and the site host is edd-validation.invalid. It seeds dummy data/settings on that isolated test site. Do not run it on a live store.

## References

- Official EDD source packages: 3.0 and 3.7.2 from downloads.wordpress.org/plugin/
- EDD gateway example: https://github.com/awesomemotive/easy-digital-downloads/blob/main/includes/gateways/manual.php
- EDD order functions: https://github.com/awesomemotive/easy-digital-downloads/blob/main/includes/orders/functions/orders.php
- FinLinks terms: https://www.finlinksglobal.com/legal/terms.html
- FinLinks privacy: https://www.finlinksglobal.com/legal/privacy.html

See INSTALL.md, ARCHITECTURE.md, PROJECT-FILES.txt and VALIDATION.json for the complete source map, setup and test evidence.
