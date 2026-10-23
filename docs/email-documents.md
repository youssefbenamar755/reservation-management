# Email & Documents — implementation and activation guide

The validation and release-preparation follow-up is documented in [the release runbook](email-documents-release-runbook.md). It distinguishes local capture, real store checks, actual hosting observations and remaining approvals. The historical implementation test totals below are not a claim of live Gmail or production readiness.

This feature is implemented in the existing Laravel 12 / Vue / Inertia application. It is **off by default for every website**. Nothing in this change deploys the application, installs a live store plugin, activates a website, changes a live notification, or sends customer email.

## Existing flow and scope

The website registry owns store identity. Orders belong to `website_id` and an internal order ID; external order numbers can repeat across websites. The submission details screen already locates a linked order and opens the shared `OrderEmailComposer`. The new composer extends that action, so orders, the work queue and linked submissions use the same workflow.

Existing Gmail OAuth, encrypted connections, verified sender aliases, MIME sending, order policies, order-update storage, scheduler and action history are reused. The existing scopes are `gmail.send` and `gmail.settings.basic`; the second is already used to inspect approved send-as aliases. No additional mailbox-reading permission was added. An unavailable alias check blocks sending and directs the operator to reconnect; there is no fallback sender.

WooCommerce is the ecommerce adapter actually implemented in this repository. Fluent Forms supplies submissions; Amadeus supports reservation/PDF generation, and Brevo is the separate marketing integration. No implemented Shopify order-completion adapter was found. Registry entries without WooCommerce credentials receive a clearly labelled send-only capability. A name or domain does not select business behavior.

The older PNR generator writes legacy submission PDFs under `storage/app/public` and does not provide a trustworthy approved order/customer document relationship. This feature does **not** auto-import or auto-attach those files, change old public links, or trigger booking generation. Operators can download/review a legitimate existing PDF, then explicitly upload and approve it for the linked order, just as they can upload PDFs downloaded from another booking system. All new workflow uploads use private versioned storage. Historic deliveries are not reclassified as fulfilled orders.

## Daily operator workflow

1. Open an order, or the **Linked order** card in submission details, then **Email documents**. Activated websites open the branded document workflow; other websites keep the existing composer.
2. Upload 1–5 actual PDFs (maximum 5 MiB each / 10 MiB combined). Confirm they belong to this exact order and its recorded customer. Each approval records the operator, website, order, customer, filename, SHA-256, size and version.
3. Select the approved versions for this delivery. Historic files are not automatically selected; newly approved uploads are selected. Download links require staff authentication and the order policy.
4. Choose **Send selected documents — keep order processing** for a partial delivery. For full fulfillment, use **Send documents & complete order** only after confirming every configured required service and all of this order’s required documents are included. The checklist is an explicit operator attestation, not an inference from file names or counts.
5. Review the website/brand, approved From, Reply-To, customer email, subject, HTML/plain text, exact attachments, saved document-set hash and configuration version, completion behavior and suppression status. A changed recipient requires a visible reason saved in the audit snapshot; no CC/BCC is added.
6. Confirm Send. Gmail acceptance is reported as **Sent**, never Delivered or Read. Successful full fulfillment queues a separate store update. Partial delivery never changes order status.
7. If the email was sent but completion failed, use **Retry order update**. This retries only the store operation. The scheduled recovery checks up to ten due pending updates per minute with backoff and stops automatic retries after six attempts; manual retry remains available.
8. Use **Resend documents** or **Send corrected documents** from the relevant prior delivery, with explicit confirmation. Neither action re-triggers completion. Prior versions remain in history.
9. For **Sending outcome unknown**, inspect the correct connected Gmail account’s Sent folder, exact recipient, subject, attachments and time. Record a reason and your observation through the review action. A manually reviewed result remains distinct from a provider-confirmed send and never completes an order automatically. A reviewed-not-sent attempt is closed; a fresh preview and explicit send are needed. A reviewed-sent attempt requires the explicit resend/correction action before sending those documents again. Do not infer exactly-once delivery from this workflow.

## Website setup and readiness

Open **Settings → Email & Documents** or the link in a website’s settings. The overview is driven by all websites the operator is allowed to manage. Administrators see the full registry. Newly registered websites need no code or domain-list update.

| Area | Required setup | Initial state in this change |
| --- | --- | --- |
| Brand | Brand name, trusted HTTPS logo, four colours, support email, URL, service label and footer; optional phone | Same-website unambiguous historical identity may prefill a draft. Missing/ambiguous fields show Configuration required. |
| Sender | Existing connected Gmail account, verified alias, display name and Reply-To | No cross-brand fallback. An administrator must explicitly assign their account to another owner’s website. |
| Templates | Acknowledgement, delivery and correction in French and English | Editable shared defaults; neutral optional blocks empty; review incentives absent. |
| Fulfillment | Explicit required-service checklist for the website, checked against each actual order by the operator | Empty list cannot enable combined completion. |
| Store | Compatible paired companion plugin, explicit brand publication and health verification matching the current config version | Completion suppression not configured until verified. Send-only remains available. |
| Activation | Save draft, test, then explicitly activate that website | Disabled. Saving does not install a plugin or publish store branding. |

**Per-website production readiness has not been asserted.** The production registry, current aliases, plugin inventory and storage were not modified or validated during this local implementation. Every actual registry entry will receive its own readiness card after migration; complete the missing fields and staging checklist separately for each site. Existing logos and sender settings seed only trustworthy same-site values. No brand assets were invented or copied between sites.

Saving increments `config_version`; old unsent previews become invalid. Publishing store branding is a separate, confirmed action. Full-completion readiness requires a matching enabled remote configuration, its verified pairing and a health check within the last 24 hours. Refresh the capability check when stale. Unsupported or unverified stores expose send-only behavior, with no promise of completion-email suppression.

## Templates, previews and tests

The renderer uses a responsive 600px table layout, inline styles, visible brand text when images are blocked, an attachment count/list near the top, compact support footer and plain-text alternative. There is no fake download button or customer portal. Logos are embedded as HTTPS image references; WP Hub does not fetch them.

Supported literal placeholders:

```text
{{brand_name}} {{customer_first_name}} {{order_number}}
{{document_count}} {{document_list}} {{support_email}}
{{website_url}} {{service_label}}
```

Subjects must include brand and order number. Unknown placeholders and subject header injection are rejected; customer values and text overrides are escaped, not executed as Blade, PHP or HTML. Edit subject, heading, preheader, body and optional block for each template/language; edit the common footer in Brand identity. Restore defaults returns editable draft text without saving or publishing it.

Actual order emails use recorded French/English locale, then the website fallback. Supported metadata keys include `_wphub_language`, `_order_locale`, `_order_language`, `wpml_language`, `_wpml_language` and `pll_language`. No language is guessed from names. Sample previews can explicitly select either language. Service wording comes from this website’s service label and templates; it does not claim issued airline tickets, visa acceptance or an invented delivery deadline.

Settings previews use fictional Alex / DEMO order data, zero/one/multiple sample attachment lists, desktop/mobile widths and no network writes. **Test email** requires an explicitly entered recipient, uses fictional sample PDF bytes, an obvious TEST banner/subject and a durable idempotency key. It never uses a real order or calls store completion. Test send is an explicit external email action; approval is required before anyone performs it on a real account. No test emails were sent during implementation.

For isolated validation, enable `WPHUB_VALIDATION_MODE` only in a fresh local/testing/staging environment and explicitly list fictional or approved test recipients in `WPHUB_VALIDATION_RECIPIENTS`. The default validation transport is private local `.eml` capture. Capture references are labelled as capture, not Gmail acceptance; stored fixture aliases are not real Google verification. Gmail transport additionally requires explicit recipient approval and its environment allowlist. Validation blocks unapproved outgoing HTTP destinations, non-document queued jobs, Laravel mail delivery and incoming API callbacks. These controls supplement a fresh database, isolated storage and removal of production credentials; they do not make a copied production environment safe.

### Trustpilot review invitations

Each saved website profile has an optional `trustpilot_url`, managed separately in **Brand identity → Trustpilot reviews**. Paste the website's own Trustpilot profile link; HTTPS Trustpilot review/evaluation URLs are normalized and their domain must match the registered website, not the editable email signature URL. An empty value omits the invitation.

Delivery and correction emails with PDFs include a neutral French/English button and a plain-text link to the matching review form. The same renderer handles settings previews, test emails and real document preparations. Acknowledgements, legacy email composition and marketing templates are unchanged.

`PATCH /settings/email-documents/{website}/review-link` requires website-update authorization and the expected current URL. It records the update in action history, rejects stale changes, and preserves `config_version`, `brand_sync_version`, pairing and workflow activation. Normal profile saves ignore this separately managed field. Already prepared HTML/text/MIME remain the exact reviewed snapshot; only new preparations use the updated link. Deploy the additive nullable-column migration before configuring links. No store companion publication or customer email is triggered by saving the link.

## Reliability and security

Preview creates an encrypted immutable recipient/sender/template/attachment snapshot and MIME message. Keys include website and internal order IDs. Per-order database locks and a unique deduplication key prevent competing sends, including different operators and competing previews of the same document set. Approval and document metadata are rechecked at send time; the outgoing bytes remain the exact reviewed MIME bytes. Provider calls are never blindly retried.

Email state is separate from store synchronization. A successful Gmail result and message ID are persisted before any store write. The adapter then writes an order-specific receipt, independently reads it, and only then requests completion. The plugin rechecks payment, current processing state and modification timestamp; an externally refunded, cancelled or changed order cannot be blindly overwritten. A final GET refreshes WP Hub via its existing order store, which preserves newer webhook data.

The store adapter uses public HTTPS, authenticated headers, validated public DNS pinned for the connection, TLS verification, no redirects and no proxy credential forwarding. No credentials or raw provider bodies appear in public errors. Snapshots, MIME and review evidence are encrypted; private PDFs require authorized staff access. Email-open tracking is not added to the new document workflow; its status represents Gmail acceptance only.

The durable delivery record retains operator, recipient override reason, sender, versions, attempt count, provider ID, timestamps and current sync error. Action history adds send attempts, manual review and synchronization attempts, plus profile save and brand publication actions. Unknown network outcomes remain reviewable; a worker or audit-write failure never justifies repeating an accepted email.

## Installation after approval

1. Back up the application database and private document storage. Review the release diff independently of unrelated local work.
2. Install locked PHP and frontend dependencies. The S3 filesystem adapter is included for private object storage. Run the two additive migrations with `php artisan migrate --force` in the approved target environment, and build frontend assets. No data backfill enables workflows or invents historic sent evidence.
3. Set `WPHUB_DOCUMENT_DISK` to a **private, persistent** filesystem disk. `local` is suitable for development or a durable shared mounted volume; ephemeral deployment/container storage is not suitable. For private S3-compatible storage use `s3` and the existing AWS bucket/region/endpoint credentials through deployment secrets. Enable private bucket access, retention/backups and an appropriate PDF retention policy. Do not use the public disk, a public bucket, a public symlink or `storage:link` for these PDFs. The HTTP upload limits must allow the application’s 10 MiB aggregate plus multipart overhead.
4. Preserve `APP_KEY` and encrypted database backups. Configure the dedicated **documents** database connection/queue worker for `SyncDocumentCompletion`, leaving the application's existing default queue unchanged. Its retry-after is at least 180 seconds, above the 90-second job limit. Keep `schedule:run` every minute for bounded recovery, allow the bounded store requests to finish, and verify shutdown grace/capacity. Queue outages leave a durable pending update for recovery. Follow the actual Cloud settings and commands in the release runbook.
5. Follow [the companion plugin installation guide](../integrations/wphub-documents/README.md) on a staging store first. Pair each actual registry website separately using its own pairing ID and existing manager-owned read/write WooCommerce credentials. Verify PHP/WooCommerce versions, InnoDB and HPOS/legacy storage support.
6. Configure the website’s draft profile. Review both languages, one/multiple attachments and image-blocked rendering. Approve a test recipient and send harmless samples only when authorized.
7. Inventory actual store mail/checkout/CRM plugins, custom WooCommerce templates and payment-provider receipts. The repository cannot establish which live plugins are installed. The companion suppresses only WooCommerce’s `customer_completed_order` filter for a trusted full-fulfillment receipt. Other mailers and payment receipts may be independent.
8. On staging, verify paid and unpaid acknowledgements, complete and partial delivery, changed/refunded/cancelled order races, lost responses, repeated sync, private notes and unmanaged orders. Verify that only the redundant completion notification is suppressed.
9. After approval, activate **one website at a time**, explicitly publish its store branding, verify the current version and inspect the first controlled fulfillment. Then repeat for each remaining registered website. Activation is never inferred from test success.

Email A stays WooCommerce-owned: existing processing/on-hold email details and transport are preserved, with local saved branding and safe unpaid wording. Checkout rendering does not depend on an HTTP call to WP Hub. WP Hub sends Email B once the operator confirms actual documents. Refund, cancellation, account, administrator and payment-provider messages are not globally disabled.

## Rollback

Use that website's dedicated **Disable this workflow** action in Settings; it works even when the saved Gmail assignment/templates are invalid and does not publish unsaved edits. Explicitly publish the saved disabled brand state to its store as a separate action. Review pending synchronization attempts; do not resend accepted messages to repair status. New combined actions stop, and the existing composer is available again. Keep sending records, receipts and PDFs for audit. Deactivating the companion after approval restores default WooCommerce completion notifications, including for previously managed orders; evaluate that consequence before manually re-triggering status emails.

Prefer disabling the feature over reversing schema after real deliveries exist. Do not drop document tables, delete receipts, rotate `APP_KEY` or remove stored PDFs during an operational rollback. A code rollback must account for queued jobs and retain the additive schema until those jobs are drained/reviewed.

## Verification and limits

Automated tests cover tenant isolation despite repeated external order numbers, sender/language/brand, actual MIME/PDF bytes, missing data, approval revocation, duplicates, known failure, unknown outcome, explicit review, partial/resend/correction behavior, separate completion retries, marker-before-transition, unmanaged notifications, private notes, test-mode isolation, new websites, missing capability and DNS restrictions.

The isolated plugin harness is a contract test, not a running WordPress/WooCommerce instance. The subsequent release-validation fixture separately exercised official WordPress 6.8.3 / WooCommerce 10.3.0 / PHP 8.2.12 / MariaDB 10.4.32: 68 HPOS and 66 legacy checks passed, including actual row-lock contention and rollback, with HPOS compatibility synchronization off and emails captured locally. Persistent object caches, each store’s installed plugins and translations, external HTTPS/OAuth, hosted storage and Gmail/Outlook rendering still require the staging checks above. Browser previews are fictional and do not prove email-client compatibility. No production installation, migration, notification change or customer email was performed.

Final local verification on this implementation:

| Check | Actual result |
| --- | --- |
| Full PHP/Pest suite | **931 passed, 6,907 assertions**, 97.37 seconds |
| Frontend Node test suite | **283 passed** |
| Isolated WooCommerce plugin contracts | **49 assertions passed** |
| Pint on 28 affected PHP files | Passed |
| Vue TypeScript, scoped ESLint and Prettier | Passed |
| Vite production build | Passed |
| Composer validation and S3 adapter loading | Passed; five new locked dependencies, no existing package updates |
| Fictional desktop/mobile browser fixture | Settings and delivery previews checked at 1440px and 390px; no page errors or horizontal overflow |

Screenshots are provided in the task output folder under `document-delivery/`; the actual Vue components and backend-rendered fictional email were used, with a local layout/API fixture. HTML/text samples are also provided. This is local visual QA, not live authenticated production validation or actual email-client testing.

On this Windows environment, PHP’s `is_readable()` check rejects the normal PHPUnit bootstrap even though Pest loads Composer successfully. The local-only test configuration removed only the redundant bootstrap attribute; it did not change tests or vendor code and was removed after verification. The normal `php vendor/bin/pest` command remains the intended CI command. The local Composer install used an isolated configuration with TLS enabled and the existing CA bundle, leaving global settings unchanged.

## Files changed for this feature

- Models: `WebsiteDocumentProfile`, `OrderDocument`, `DocumentTestSend`; extended `OrderEmailDelivery`.
- Services: `WebsiteDocumentProfiles`, `BrandedDocumentRenderer`, `BrandedDocumentDelivery`, `OrderDocumentStorage`, `DocumentStoreGateway`, `DocumentCompletionSync`, `DocumentDeliveryReview`; extended `ActionHistory`.
- Controllers: settings `DocumentSettingsController`, `DocumentDeliveryController`, `DocumentTestController`; extended `OrderEmailController`.
- Jobs/commands: `SyncDocumentCompletion`, `RetryDocumentCompletion`.
- Schema/config: two `2026_10_21` migrations, `config/document_delivery.php`, `.env.example`, S3 Composer dependency/lock, `routes/document-settings.php`, `routes/document-deliveries.php`, route includes and recovery schedule.
- Vue: `EmailDocuments` settings page; `DocumentDeliveryComposer` and `DocumentEmailPreview`; shared composer, website/settings links and TypeScript contracts.
- Tests: profile/renderer, workflow, test-send and gateway PHP suites; document settings/composer frontend tests and legacy-composer regression coverage.
- Store: `integrations/wphub-documents/` plugin, contract harness and installation/rollback guide.

Unrelated local work is preserved. Generated preview fixtures and screenshots live outside the application repository; no production credentials or private customer documents are included.
