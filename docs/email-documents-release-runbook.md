# Email & Documents — validation and release runbook

This is a preparation package. No command in this guide has been executed against production by the validation work. Do not activate customer sending, install the companion on a live store, change live notification settings or deploy without the owner's explicit approval.

## Evidence categories

- **Implemented:** the application and companion code exist locally; draft profiles are registry-driven and disabled by default for every website.
- **Automatically tested:** PHP/browser contract tests use isolated databases, fictional data and HTTP fakes. Their assertions are valuable, but they do not verify OAuth credentials or a remote store.
- **Local capture verified:** `scripts/document-validation-smoke.php` creates a new SQLite database and private storage for every run, excludes the repository `.env` and config/route/event caches, and captures a genuine MIME message containing a harmless PDF. No Google request is transmitted.
- **Real integration:** only a running WordPress/WooCommerce test-store result with recorded versions qualifies. The companion's PHP stub harness alone does not. Refer to the separate release report and store evidence for actual execution results.
- **Actual email checkpoint:** four fictional EN/FR document-delivery tests were accepted by Gmail for the exact two approved recipients. Gmail receipt/PDF opening is owner-confirmed; Outlook web receipt and all three PDFs were directly inspected. This does not establish every brand, acknowledgement, client, images-blocked view or received-byte hash. See the release report for the separate evidence.
- **Local storage/worker checkpoint:** a fresh SQLite/local-filesystem fixture verifies private downloads, real Laravel worker restart, sync-only recovery and local backup/restore. The store gateway in this probe is simulated. This does not establish Cloud deployment or S3/R2 persistence.
- **Pending:** remaining brand/client/received-byte checks, production-like private object-storage persistence across deployment and managed-worker restart, hosted restore/capacity checks, and each real website's configuration and notification inventory.

## Reproduce safe local capture

Run from the repository with PHP and Composer dependencies installed:

```powershell
C:/xampp/php/php.exe scripts/document-validation-smoke.php --output=C:/absolute/private/evidence/directory
```

The existing output directory must be outside the publicly served application. The runner creates an unpredictable new child; it never reuses an existing database or copies production jobs. It generates a new disposable application key in process and never reads or prints production secrets. It disables telemetry, mail and broadcasting, leaves the approved store-origin list empty, and runs migrations only against the newly created SQLite file. Each report records blocked payment/marketing/store/Gmail endpoints, incoming callback rejection, unrelated job rejection, an unauthenticated private-file HTTP check, MIME/PDF paths and the PDF hash. Fixture capture addresses use `.invalid` and cannot reach customers.

The opt-in application safeguards are:

| Setting | Meaning |
| --- | --- |
| `WPHUB_VALIDATION_MODE=true` | Allowed only with `APP_ENV=local`, `testing` or `staging`; production fails closed. Default is false. |
| `WPHUB_VALIDATION_MAIL_TRANSPORT=capture` | Default private local `.eml` transport, with no OAuth refresh, alias API call or Gmail send. |
| `WPHUB_VALIDATION_RECIPIENTS` | Comma-separated explicit test addresses. Both capture and Gmail reject missing/other recipients, duplicate To headers, CC/BCC and resent recipients. |
| `WPHUB_VALIDATION_MAIL_TRANSPORT=gmail` | Use only after the owner approves the exact recipient. Google endpoints are narrowly permitted and outgoing raw MIME is checked again. |
| `WPHUB_VALIDATION_STORE_ORIGINS` | Exact separately approved staging origins only; empty means no external store calls. Only WooCommerce order/companion API paths are allowed. This is not authorization to add a production origin. |

All unrelated Laravel HTTP traffic, Laravel mail delivery, API callbacks and non-document queued jobs are blocked while this mode is enabled. A clean database and filesystem remain mandatory: the guard does not prove that arbitrary third-party code or copied credentials are safe. Do not run the general scheduler or import production queues into this fixture. Do not bring a production database dump or customer PDFs into the browser fixture. Use the companion's capture-only test-store guard for store notifications, with the same recipient allowlist; disable its gateways, cron, external HTTP and webhooks before constructing orders. A test-store capture must never be described as a received Gmail email.

## Actual hosting observations

The repository contains Laravel 12, PHP `^8.2`, Dompdf, the AWS S3 Flysystem adapter, the existing Gmail OAuth service and database/Redis/SQS queue configurations. There is no checked-in production Supervisor or Laravel Cloud process manifest. `.env.example` sets `QUEUE_CONNECTION=sync`; it is not a production worker configuration.

A read-only Laravel Cloud inspection on 2026-10-21 reported PHP **8.4.26**, Laravel **12.42.0**, Node **24**, MySQL **8.4**, queue **sync**, default filesystem **local**, no configured S3 bucket/credentials and both feature migrations absent. The Frankfurt app has 512 MiB / 1 vCPU, scheduler enabled, no listed background processes, a 30-second HTTP timeout and 30-second graceful shutdown. The UI also showed an out-of-memory/restarting warning. Thus a dedicated document worker, capacity/timeout review and private persistent storage are deployment prerequisites, not already verified capabilities. Local capture ran with PHP **8.2.12** / Laravel **12.42.0**; that difference is recorded, not concealed.

Push-to-deploy is enabled for `main`. Do not push or merge this release to `main` before approval: that could deploy it automatically. Actual build hooks already install Composer dependencies without dev packages and run `npm ci`, `npm run build` and `npm run build:ssr`; the deploy hook runs `php artisan migrate --force`. Inspect and preserve those hooks rather than inventing a separate deployment process.

Gmail transmission is still a confirmed synchronous action. Sender/OAuth checks and a final remote order check can each consume their bounded network budgets before a 20-second Gmail request. The current 30-second HTTP limit is therefore not verified adequate. Staging must measure slow-provider behavior and configure a sufficient total request/grace budget (or record that it cannot be accommodated) before real sending. A timeout may leave an uncertain send and must not trigger automatic resend. Also prove enough memory for MIME attachments and sample PDF rendering; the existing OOM warning is a blocker for capacity sign-off. Document completion is separate queued work with a 90-second job limit; give the worker enough shutdown grace to finish or recover idempotently.

Required PHP capabilities include the locked Composer platform requirements plus cURL for securely pinned store requests, the configured PDO database driver, fileinfo for PDF MIME validation, mbstring, DOM/XML for PDF generation and OpenSSL/TLS. Verify with `composer check-platform-reqs --no-dev` in the approved target build.

## Exact migrations

Apply only after backup and deployment approval. Both are additive; neither enables a website.

| Migration | Purpose |
| --- | --- |
| `2026_10_21_000001_create_website_document_profiles.php` | Per-website brand/template/sender settings, pairing identity, configuration versions, capability and test metadata; workflow disabled by default. |
| `2026_10_21_000002_add_branded_document_delivery.php` | Versioned private approved documents; durable test sends; workflow/document mode, independent synchronization and encrypted manual-review fields on existing deliveries. |

Do not use `migrate:rollback`, `migrate:fresh`, database resets, blanket queue flushes or deletion of receipt metadata as an emergency rollback. They risk erasing evidence and replaying an already accepted email.

## Environment names — no secret values

- Existing application/database secrets: `APP_KEY`, `APP_URL`, `APP_ENV`, `APP_DEBUG`, `DB_CONNECTION` and the host-managed database settings. Preserve the same `APP_KEY` through releases and restores because Gmail tokens, snapshots, MIME and review evidence are encrypted.
- Documents: `WPHUB_DOCUMENT_DISK`. Production needs a private persistent shared disk. For the existing `s3` disk: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, optional `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`. Do not set a public asset URL for document access. Credentials belong in Cloud secrets; bucket contents and credentials must be separate for staging.
- Queue: keep the application's existing `QUEUE_CONNECTION` unchanged. Completion jobs are pinned to the new `documents` database connection and `documents` queue. `WPHUB_DOCUMENT_QUEUE_RETRY_AFTER` defaults to and is clamped at **180 seconds**, above the 90-second job limit; `DB_QUEUE_CONNECTION` / `DB_QUEUE_TABLE` select the existing queue storage if customized. Configure only the document worker; do not accidentally drain old jobs from the shared `default` queue. Laravel requires the worker timeout to be shorter than retry-after. [Laravel 12 queue timeout guidance](https://laravel.com/framework/docs/12.x/queues#job-expirations-and-timeouts)
- Gmail OAuth client settings and website credentials are already stored encrypted through application settings. No new Gmail scope or hardcoded domain list is required. Fresh validation must use fictional settings or explicitly approved test credentials only.
- Keep `WPHUB_VALIDATION_MODE=false` in production. Never promote a test database containing `capture_` delivery references or simulated verified aliases to production.

## Approved deployment sequence — not executed

1. Back up MySQL, private object storage and configuration/key escrow. Record a consistent snapshot time and verify a restore into an isolated target using the matching `APP_KEY`. Confirm private PDFs and encrypted delivery snapshots can be read there. Do not make a live secret-bearing dump part of task evidence.
2. Configure a private persistent S3-compatible bucket or proven shared durable volume. Keep public access blocked, credentials least-privileged, backups/version retention enabled and staging isolated. The `local` disk under an ephemeral Cloud application instance is insufficient unless its persistence/shared-mount properties have been positively established. Laravel Cloud can attach a named S3-compatible R2 disk; set `WPHUB_DOCUMENT_DISK` to that actual disk name. Cloud visibility is bucket-wide, and R2 does not support per-object ACL headers. Verify this release's private write/read options against the selected adapter/bucket rather than assuming S3 mocks prove compatibility. [Cloud object-storage setup and visibility](https://laravel.com/cloud/docs/resources/object-storage)
3. Review Laravel Cloud's actual build/deploy hooks. Keep dependency/asset compilation and optimization in the **build** hook; migrations belong in the **deploy** hook. Cloud explicitly requires `optimize` / `config:cache` during build, not deploy. Preserve existing hooks and avoid duplicates. [Cloud build/deploy settings](https://laravel.com/cloud/docs/environments#build-and-deploy-commands)

Build hook, after approval:

```sh
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
npm ci
npm run build
npm run build:ssr
php artisan optimize
```

Deploy hook, after backup and migration approval:

```sh
php artisan migrate --force
```

4. In Laravel Cloud, add a background queue worker on the approved app or worker cluster, with connection **documents**, queue **documents**, one process initially, 90-second timeout and one attempt. The equivalent custom command is `php artisan queue:work documents --queue=documents --sleep=3 --tries=1 --timeout=90 --max-time=3600`. Cloud restarts queue workers on deployments; an extra `queue:restart` deploy command is unnecessary. Select adequate graceful shutdown and memory after the staging capacity check. Existing webhook/reporting/default queues remain unchanged. [Cloud background processes and worker lifecycle](https://laravel.com/cloud/docs/workers)
5. Keep the existing scheduler running once per minute (`php artisan schedule:run`). `documents:retry-completion` processes only due accepted-send completion updates, ten at a time with bounded retries; it never sends email. Do not manually replay `OrderEmailDeliveryService::send` to repair an order update.
6. Configure upload/request limits above the 10 MiB aggregate plus multipart overhead; each PDF remains capped at 5 MiB by the application. Make outbound verified TLS available to Google and the intended stores.
7. On an isolated approved staging store, install the packaged companion using [its installation guide](../integrations/wphub-documents/README.md). Pair the exact website, verify storage/HPOS capabilities, publish that website's reviewed branding explicitly and refresh the health check. Actual live installation requires separate approval.
8. Complete each website's readiness review separately. Check its own logo/colours/sender/Reply-To, both languages, acknowledgement and delivery wording, required services, real plugin status, store notification inventory and configuration version. Save draft first. A missing/ambiguous field blocks that website; another brand's successful test does not fill it.
9. Only after explicit controlled-activation approval, activate the chosen website, publish its enabled brand state and verify the same current version. Observe the first controlled order through the entire journey before considering another website.

## Persistent/private storage acceptance test

Before activation, upload a fictional approved PDF through the actual staged application and record its database ID, storage key, byte length and SHA-256. Download through an authorized session and verify the same hash. Check the same URL while logged out and as another website owner; access must fail. A guessed public `/storage/...` path and bucket object's anonymous URL must also fail. Do not publish a signed URL to customers as a substitute.

Redeploy the staged application, restart the managed queue worker, and read the same approved document through a fresh application instance; verify unchanged hash, approval/version metadata and completed delivery history. Repeat after restoring the staging backup to an isolated target. This proves deployment/worker and restore persistence. A local file surviving a PHP process restart or a unit test using `Storage::fake()` does not establish these hosting guarantees. These actual Cloud persistence/restore checks remain pending until an approved staging bucket/worker exists.

## Reproduce the isolated local storage/worker drill

The lifecycle probe uses a new random directory and new SQLite database for every run. It never reads the repository `.env`, configuration cache, production data or active Gmail fixture. Native PHP mail is disabled, validation transport is capture, store origins are empty, and unexpected Laravel HTTP is blocked. The store gateway used for sync outcomes is explicitly simulated; queue execution, local filesystem I/O, encrypted model casts and backup/restore use real framework implementations.

```powershell
C:/xampp/php/php.exe -d disable_functions=mail tools/validation/storage-worker-local.php --output=C:/absolute/private/evidence/root
python tools/validation/verify-storage-worker-pdfs.py C:/absolute/private/evidence/root/results.json
```

The 21 October local result verified three approved PDFs and their version metadata through backup/restore, correct owner download, anonymous/other-owner/cross-order rejection, private cache headers, tamper rejection, and wrong-key decryption failure. A real `queue:restart` signal stopped the first Laravel worker; a new worker drained only `documents`, preserving the unrelated default-queue canary. A simulated store failure remained pending, and recovery did not increment the recorded send-attempt count. The stale-sync recovery command and completed-state idempotence also passed. All six original/restored PDF instances strict-opened and matched their saved hashes.

The boundary probe uploads two 5 MiB PDFs, creates MIME, saves/reads encrypted MIME and serializes the Gmail JSON payload. It accepted the 10 MiB aggregate, rejected an excess attachment, and measured **168 MiB peak PHP allocated memory** and **1.572 seconds** of local work on PHP 8.2.12. The retained benchmark buffers make this a reproducible diagnostic, not a production request or concurrency benchmark. Measure the full deployed request, PHP 8.4 runtime and concurrent load before sizing Cloud; no 512 MiB capacity sign-off follows from this number. Windows lacks `pcntl`, so the 90-second process-kill timeout was not exercised locally; verify it and graceful shutdown on the managed Linux worker.

The local AWS SDK wire probe serializes a `private` ACL header for the application's private upload options. Its HTTP handler is a capture stub, so it proves neither R2 acceptance nor denial. Cloud R2 uses bucket-level privacy and does not support per-object ACLs; verify this exact write against the private staging bucket before accepting compatibility. Do not remove privacy checks or change a bucket to public to resolve a failed upload. [Cloud object-storage visibility](https://laravel.com/cloud/docs/resources/object-storage#bucket-file-visibility)

Only `results.json`, `pdf-integrity.json` and reviewed PNGs are shareable evidence. Do not package `state.private.json`, fixture/backup databases, logs or private document directories. The harness applies narrow, process-local workarounds for this Windows sandbox's false `is_readable()` metadata result; actual file reads and fileinfo MIME detection still execute. No framework/vendor source is changed.

## Approved Cloud staging storage probe

`tools/validation/cloud-staging-probe.php` is a portable acceptance probe for the fresh approved Cloud environment. Its preparation and local refusal checks do not count as a Cloud pass. The operator supplies the exact observed staging URL and actual backend bucket name (Cloud's display name can differ). Never print the full filesystem configuration, which contains credentials.

The probe refuses unless both the process and cached application environment are `staging`, the app URL matches, debug is off, validation transport is `capture`, the store allowlist is empty, MySQL's selected schema is exactly `documents_staging`, and the document disk is the private `documents_private` R2 disk with the expected bucket. Native PHP mail must be disabled. It blocks Laravel HTTP and does not send messages or call WooCommerce. Its only external network operations are the selected R2 bucket's real authenticated storage I/O and an unsigned object GET with redirects disabled. Only the explicit worker phase enqueues the narrowly described job below.

Run `seed` before importing any business fixtures; it requires no existing users, websites, jobs, orders, submissions, document deliveries or Gmail connections. It creates only a paused `.invalid` website, fictional users/orders/PDF, and a disconnected, token-free Gmail metadata row needed for the encrypted delivery snapshot's foreign key. No Gmail consent or connected sender is needed. Rerunning `seed` on a completed fixture performs verification without creating duplicates. Fixture rows are transactional; an object written before a later failure may remain private for inspection. The probe has no destructive reset option.

```sh
APP_ENV=staging php -d disable_functions=mail tools/validation/cloud-staging-probe.php --phase=seed --expected-app-url=https://reservation-management-documents-staging-o3nliw.laravel.cloud --expected-bucket=ACTUAL_APPROVED_BUCKET
APP_ENV=staging php -d disable_functions=mail tools/validation/cloud-staging-probe.php --phase=verify --expected-app-url=https://reservation-management-documents-staging-o3nliw.laravel.cloud --expected-bucket=ACTUAL_APPROVED_BUCKET
```

Save only the safe JSON output. A passing run confirms actual private upload/read, approved PDF hash/version, encrypted snapshot/MIME reads, owner/anonymous/other-owner/cross-order route behavior, private cache headers, and unsigned R2 access denial. Application routes are exercised through the real HTTP kernel in-process; actual edge/session behavior and bucket-wide public/CDN visibility still require their own checks.

Repeat `verify` after an approved redeploy/managed worker restart and again after attaching a restored copy of the staging database with its matching `APP_KEY`. Compare the same document ID, SHA-256, approval version and encrypted snapshot ciphertext hash across all outputs. If a separate restored app is used, pass that approved app's exact observed URL; keep the selected schema named `documents_staging`. Record the actual platform deployment/restore operation alongside the output. One passing probe run does not prove a redeploy, independent backup, worker lifecycle or capacity.

The optional `--phase=backup-copy` writes and verifies one second private prefix copy in the same bucket and preserves its manifest for subsequent `verify` runs. This is useful for object-copy integrity only. It does not exercise an independent storage backup, replace MySQL backups or provide protection from bucket loss. Preserve or export object backups separately under the approved staging resource plan. The probe intentionally never deletes or overwrites the approved original PDF.

For the approved managed worker, run `worker-enqueue`, then `worker-verify` with the same bounded label. Each label can enqueue at most one real `SyncDocumentCompletion` job; its database ID, payload UUID and failed-job baseline are saved atomically with the queue insert. The fixture remains a **prepared partial** delivery, which the production synchronization service returns without claiming, sending or contacting a store. Verification requires the job to be gone, no new failure records, and unchanged send/sync attempts and order/delivery state. No queue mocks, extra job classes or validation allowlist changes are used.

```sh
APP_ENV=staging php -d disable_functions=mail tools/validation/cloud-staging-probe.php --phase=worker-enqueue --worker-label=initial --expected-app-url=https://reservation-management-documents-staging-o3nliw.laravel.cloud --expected-bucket=ACTUAL_APPROVED_BUCKET
APP_ENV=staging php -d disable_functions=mail tools/validation/cloud-staging-probe.php --phase=worker-verify --worker-label=initial --expected-app-url=https://reservation-management-documents-staging-o3nliw.laravel.cloud --expected-bucket=ACTUAL_APPROVED_BUCKET
```

Capture the actual Cloud worker process identity, issue `php artisan queue:restart` only in this isolated staging environment, and capture the new managed process identity. Repeat enqueue/verify with `--worker-label=after-restart`; labels `after-redeploy` and `after-restore` are also available. Keep the Cloud process/log evidence next to the probe output. This establishes basic managed-worker consumption and restart when observed; it does not establish a long-running provider request, enforced timeout, graceful in-flight completion or successful WooCommerce fulfillment.

## Emergency disable and safe rollback

Use **Disable this workflow** in the affected website's Email & Documents settings. This dedicated action updates only the saved enabled flag and configuration version, under the website/profile lock, and records an audit event. It does not require valid templates, a working Gmail connection or a still-authorized sender assignment. The button remains available with unsaved edits; those edits are preserved locally and are not published by disabling. If another operator changed the saved configuration, the older draft keeps its stale version and cannot silently overwrite the newer settings; reload before applying it.

The action stops new branded document workflow actions but does not change a remote store. Explicitly publish the saved disabled configuration to the companion when the store is reachable; reload/discard unneeded local edits first if the publish action is blocked by a dirty draft. Review pending completion attempts and pause the document completion worker/recovery schedule if rollback work requires it; do not stop unrelated production workers without assessing their responsibilities. If the store is unreachable, record that remote branding remains unchanged and publish the saved disabled state from WP Hub after access returns. The companion settings screen currently exposes pairing and status, not a separate disable toggle. Application disabling does not prove a remote setting changed.

Retain both migrations, database rows, immutable MIME/snapshots, documents, sent IDs, suppression receipts and action history. Never mark a sent/uncertain email as unsent or bulk requeue messages. A rollback to older application code must keep jobs compatible or quarantine pending completion jobs for review before workers resume. Existing accepted emails require sync-only review. An uncertain send requires operator evidence, not a resend retry.

Keep the companion installed while reviewing already managed orders: deactivation can restore redundant normal completion notifications for those orders. Do not delete per-order suppression markers. Disable future workflow actions rather than erasing historical fulfillment evidence. After the fault is understood, re-enable one reviewed website at a time with a new preview and current capability/configuration check.

## Actual client verification instructions

Obtain approval for the exact Gmail and Outlook test recipients before selecting Gmail transport, and add only those addresses to both test systems' allowlists. Use fictional order/customer data and harmless sample PDFs. Send acknowledgement and delivery examples in French and English for each configured brand, covering long names and filenames, one/multiple attachments, desktop/mobile and blocked images. Save the received raw `.eml` where possible; verify From, Reply-To, subject, body/plain-text alternative, attachment names/count and decoded PDF hashes against the approved selected versions. Open every received PDF and confirm its harmless content. Record date, client/version, test address identifier, result and screenshots without disclosing account tokens.

Check that the attachment instruction is visible near the top, the brand remains recognizable with images blocked, colours/text remain readable, there is no fake download button, and no brand or sender fallback. Observe the actual store transport's acknowledgement and redundant-completion behavior separately. Browser screenshots and locally captured MIME are useful evidence but are explicitly not Gmail/Outlook rendering or delivery proof. If account access/recipient approval is absent, mark these checks pending.
