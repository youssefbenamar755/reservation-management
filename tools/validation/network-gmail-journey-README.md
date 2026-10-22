# Isolated connected-store Gmail journey

This narrowly scoped bridge joins the actual Laravel document workflow, the real local TLS/Woo fixture, and the already approved separate Google test client. It is not a deployment or customer-mail script. It does not read production credentials or write to the original browser fixture at `localhost:8799`.

## Prepare first — no email transmission

```powershell
& ./tools/validation/run-network-gmail-journey.ps1 -Phase Prepare
```

Preparation creates a new random directory, new MariaDB data directory, new WordPress/WooCommerce installation, new Woo API keys, new application key and SQLite database. The occupied-port guards, private CA, loopback TLS port8443 and temporary cURL `CONNECT_TO` route have the same limitations documented in `network-fixture-README.md`.

Only the exact previously consented isolated test OAuth client may be imported from `outputs/wphub-local-gmail-4483c123ccd53998`. Its source SQLite connection is query-only. The source encryption key is used only in memory; client secret/tokens are decrypted in memory and immediately re-encrypted by normal Laravel casts into the **new private fixture database**. No credentials, raw database, key or private logs belong in release artifacts. Do not use this tool to copy credentials from any production database.

The real Gmail alias API must verify `visabookinghelp@gmail.com`. The sender cannot be changed by a command-line option. Exactly two fictional paid orders are created, and normal application upload/approval/preview services save these messages:

- English, one harmless fictional PDF, to `youssefbenamar755@gmail.com`.
- French, two harmless fictional PDFs, to `benamar-y14@outlook.com`.

Both addresses were explicitly approved for fictional testing. They are the fixed application recipient allowlist; no CC/BCC is allowed. Store installation messages are initially captured to an `.invalid` fixture address, then the two approved addresses become the fixed store capture allowlist. All WordPress mail stays captured, with native PHP mail disabled, HTTP blocked, gateways/webhooks disabled and cron off. No store SMTP email is transmitted.

Preparation prints a safe review projection and writes `gmail-bridge-prepared.json`, `reviewed-en.eml` and `reviewed-fr.eml`. These are fictional MIME previews with the exact approved sample bytes, not received-email evidence. Review sender, recipient, `[TEST]` subject, body, filename/count and PDF hashes before the separate send phase. The new fixture path is printed. All its processes are stopped after preparation; the original browser app is untouched.

## Explicitly send the two reviewed messages

Run only after the saved previews have been reviewed within the existing exact-recipient authorization:

```powershell
& ./tools/validation/run-network-gmail-journey.ps1 -Phase Send -Fixture 'ABSOLUTE_NEW_PREPARED_FIXTURE_PATH'
```

This uses the actual `BrandedDocumentDelivery` and `GmailClient` implementations. Accepted sends enqueue the actual `documents` database worker, which records and re-reads the receipt through the local TLS/Woo REST server before controlled completion and final order refresh. No plugin/business/authentication/receipt implementation is mocked. The fixtures remain fictional; DNS/transport routing alone is harness-specific.

Each saved preview is sent at most once by the runner. Already accepted messages are not resent. A failed, in-progress or uncertain result stops for review; the tool does not recreate a preview or retry Gmail automatically. Do not start another Prepare run to work around a failed/uncertain send. If Gmail accepted a message but completion is pending, inspect and repair **synchronization only**. The send phase disables the fixture workflow and attempts to publish the disabled state, returns transport to capture in process, and stops its own server/database processes. The persisted manifest was never changed out of capture.

`gmail-bridge-evidence.json` records provider IDs, exact recipients, sample hashes, send/sync attempts and local final order state. It establishes Gmail acceptance and local-store synchronization only. Recipient inbox arrival, client rendering and received raw MIME/PDF hash checks must be observed separately. The genuine public HTTPS/Cloud storage/managed-worker/store-configuration gates remain separate, as does actual store acknowledgement SMTP delivery.

To reread safe delivery state without sending:

```powershell
& ./tools/validation/run-network-gmail-journey.ps1 -Phase Evidence -Fixture 'ABSOLUTE_NEW_PREPARED_FIXTURE_PATH'
```

The fixture contains real **test** OAuth credentials after preparation. Keep it private and outside served roots; never package it, upload it to production or expose its database/private logs.
