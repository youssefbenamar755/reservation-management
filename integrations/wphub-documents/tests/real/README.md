# Real WordPress/WooCommerce fixture

This fixture is separate from the stub contract test. It loads official WordPress and WooCommerce, uses a new MariaDB database, invokes the actual REST dispatcher, CRUD/storage/cache implementations, order transitions, email templates and InnoDB transactions. It intercepts `wp_mail` before transport. It does not prove Gmail, Outlook, an external HTTP/TLS connection or a live site's other plugins.

Never copy these files to a customer store. The production plugin ZIP should contain only its two PHP source files and installation guide.

## Isolation before testing

- Initialize a new MariaDB data directory. Never connect to an existing database or restore production data.
- Bind the dedicated database only to `127.0.0.1:33177`. The fixture permits only database names `wphub_fixture_hpos` and `wphub_fixture_legacy`.
- Unpack official WordPress/WooCommerce packages into the fixture's `wordpress/` directory. A `FICTIONAL-ONLY` marker is required.
- Configure WordPress with the fixed local DB host, loopback Home/Site URLs, `DISABLE_WP_CRON`, `WP_HTTP_BLOCK_EXTERNAL`, `DISALLOW_FILE_MODS` and `AUTOMATIC_UPDATER_DISABLED` enabled. No WordPress HTTP server is needed; tests are CLI only.
- Install `isolation.php` as an MU plugin before installing WordPress. It rejects every outbound WordPress HTTP request, all payment gateways and webhook delivery; disables the asynchronous Action Scheduler runner; and captures mail only for the explicit recipient allowlist. PHP's `mail` function must also be disabled.
- Set `WPHUB_VALIDATION_MODE=true`, `WPHUB_VALIDATION_MAIL_TRANSPORT=capture`, `WPHUB_VALIDATION_RECIPIENTS=fixture-recipient@example.invalid`, `WPHUB_FIXTURE_ROOT` to the fixture directory and `WPHUB_FIXTURE_STORAGE=hpos` or `legacy`.
- No live credentials, real customer records, PDFs, copied jobs or production webhooks enter this installation. Fictional receipt proofs explicitly do not represent real Gmail messages.

Run `install.php`, then `run.php hpos` (or `run.php legacy`) in separate PHP processes. Installation copies the current companion source into the isolated fixture and activates exactly WooCommerce and WP Hub Documents. The normal WordPress bootstrap initializes both plugins during the actual plugin/theme/init lifecycle. The two storage modes use different databases; HPOS compatibility synchronization is explicitly disabled.

On the provided Windows sandbox, native PHP `is_readable()` returns false even for files that `fopen()` can read. For this fixture only, run PHP with `-d disable_functions=mail,is_readable`. `windows-readability.php` implements that filesystem predicate by opening the actual file/directory, restricted to the fixture directory. Official WordPress/WooCommerce files remain untouched. No WordPress/WooCommerce business method is replaced. Other environments should use native readability and only disable `mail`.

## Evidence and limitations

`result-{mode}.json` records each assertion and actual versions. `api-events-{mode}.json` records real dispatcher responses with fictional IDs. `table-engines-{mode}.json` records database engines. `captured-paid-{mode}.json` and acknowledgement HTML files contain WooCommerce-generated emails intercepted before transport. They are not evidence of delivered Gmail/Outlook rendering.

The assertions cover capture/allowlist/network/payment isolation; native order storage and disabled HPOS compatibility sync; pairing/permission rejection; paid/unpaid English/French acknowledgement; receipt-before-completion; timestamp preservation for metadata-only writes; one private note; suppressed managed completion versus normal unmanaged notification; idempotent retries; status-save failure rollback/cache invalidation; real row-lock contention through a second database connection; cancelled/unpaid order rejection; and unchanged refund/admin notification filters.

Still perform approved store-specific staging verification with its actual WooCommerce version, SMTP sender, payment provider, other plugins, persistent object cache, external REST API-key authentication and real approved email-client accounts. This controlled fixture does not represent that entire environment.
