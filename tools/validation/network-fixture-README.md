# Capture-only app → TLS → real WooCommerce journey

This fixture creates a new WordPress installation and MariaDB data directory, plus a separate Laravel SQLite database and private document storage. It never uses the existing Gmail fixture, production credentials, orders or plugins. Do not deploy these harness files to a store.

Run from the repository root on the prepared Windows development host:

```powershell
& ./tools/validation/run-network-fixture.ps1
```

Prerequisites are the PHP, MariaDB, Node and Python paths recorded in that runner, Python `cryptography`, the existing downloaded official WordPress 6.8.3 and WooCommerce 10.3.0 ZIPs under `outputs/woo-integration`, and permission for local loopback connections. Ports 33177, 8802 and 8443 must be unused. The runner refuses occupied ports. It starts only its own processes and closes those processes in `finally`, shutting down the isolated database first. It does not modify a Windows service, hosts file, system certificate store or firewall.

The connection is real TLS from Laravel's HTTP client to a Node loopback proxy and then real HTTP to the WordPress PHP front controller. A generated seven-day fixture CA is trusted only by the harness's HTTP client. The normal Woo REST authentication checks newly generated consumer credentials. WordPress/WooCommerce CRUD, the companion REST routes, InnoDB transactions, order transitions and email hooks are actual implementations.

The production gateway intentionally refuses private addresses. A subclass defined only in this CLI harness replaces the resolver for the single name `fixture.example.invalid`, pinning it to `127.0.0.1`, and adds the fixture CA. A harness-only cURL `CONNECT_TO` mapping routes the original HTTPS port443 to loopback port8443, preserving request origin, hostname verification and TLS SNI. The inherited production brand, eligibility, receipt, receipt-verification, completion and order-read methods are unchanged. This is **local TLS evidence**, not proof of public DNS, internet routing, public certificate issuance, a remote hosting gateway or a store's customized plugin/SMTP environment.

All email uses the application's actual capture transport and the exact `.invalid` recipient `fixture-recipient@example.invalid`. Fixture sender identities and OAuth tokens are explicitly fictional; they are not Google verification evidence. No email is transmitted. Native PHP mail is disabled, WordPress outgoing HTTP is denied, gateways and webhook delivery are disabled, cron and background Action Scheduler are disabled, and unrelated application HTTP/jobs retain the normal validation guards. The only worker run is the isolated `documents` database queue.

The journey covers:

- invalid and valid Woo API authentication over TLS;
- brand publication and current paired capability health;
- English/French paid-order acknowledgement capture on the real store;
- actual Woo order import, private PDF approval, immutable email preview and capture;
- the real database job, receipt persistence/read-back, controlled completion, final order refresh and idempotency;
- a deliberately lost response after Woo has committed completion, followed by sync-only recovery without a second email capture;
- a real Woo order change after preview that blocks the send before capture;
- final workflow disabling in both isolated app and store.

`journey-evidence.json`, `setup-evidence.json`, `store-evidence.json`, `tls-requests.jsonl` and `store-requests.jsonl` are scoped evidence. Inspect the request records to confirm captured store notifications, ordering and the injected response loss. Private database/configuration, generated credentials, certificate key and private logs must stay outside the release package. Locally captured MIME/PDFs are fictional evidence only. Passing this journey does not close the hosted private-bucket/redeployment/backup/restore, real Gmail transport, per-website sender, actual store inventory or external HTTPS staging gates.
