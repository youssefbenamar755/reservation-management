# WP Hub owner and handover guide

Last reviewed: 22 September 2026. Use this alongside the user guide when installing, maintaining, demonstrating, or transferring this application. This is an operational guide, not a representation that third-party accounts or customer data are included in a sale.

## Product scope and account access

WP Hub is a Laravel 12 / PHP application with a Vue 3, TypeScript, Inertia, and Vite frontend. It manages connected WordPress/WooCommerce and Fluent Forms data, order fulfilment workflows, Gmail document delivery, Brevo campaigns, GA4 reports, and Search Console opportunities.

### Explain access to a new operator

1. Distinguish a normal user from an administrator. An administrator can manage users and global integration application settings; individual feature access also depends on ownership and its own permission checks.
2. Treat websites as belonging to user accounts. Creating a user does not automatically share existing websites, Gmail credentials, email history, or reporting mappings.
3. Sign in as the intended operating user and verify the websites and actions they can access.
4. Do not promise shared-team assignment, granular staff roles, a subscription billing portal, or automatic tenant onboarding: these are not established features of the current application.
5. Demonstrate using synthetic data in a separate installation. Never expose production customer records or connected account credentials in a buyer demo.

The repository includes framework and third-party dependencies. Review their licenses and your rights to transfer branding, content, and integrations before packaging a distribution.

## Installation and environment

The current repository targets PHP 8.2 or later compatible with its lock file, Composer, Node.js, and a database supported by the application. Production has been exercised on MySQL 8.4; run database-dependent tests against the database you intend to use.

### Prepare a separate installation

1. Obtain the repository and check out a reviewed release. Install dependencies from `composer.lock` and `package-lock.json` with `composer install` and `npm ci`.
2. Create a private environment configuration from `.env.example`. Set the app URL, database, mail, session, cache, queue, and broadcasting configuration for this installation. Review `config/` for the settings actually used by the code.
3. For a **new empty installation**, generate its application key with `php artisan key:generate`. When restoring an existing database, retain its original key so encrypted integration credentials and saved data remain readable.
4. Create the database and run `php artisan migrate`. In a non-interactive production deployment, the reviewed migration step normally uses `--force`.
5. Build the frontend with `npm run build:ssr`. This builds the browser assets and the Inertia SSR bundle. The repository enables SSR; configure its service where it is used.
6. Configure the web server document root to `public/`, HTTPS, and writable Laravel storage/cache directories. Keep `APP_DEBUG=false` in production and set the correct public `APP_URL`.
7. Provision the first administrator through your controlled provisioning process. Once an administrator exists, **Settings → User Management** can create additional users. There is no documented first-admin setup wizard to rely on.
8. Configure scheduler and queue processing, connect a test website, then validate the user-guide workflows before real use.

Do not run migrations or onboarding scripts against production merely to demonstrate installation. A new customer's installation should use its own database, application key, service accounts, and domains.

## Integration setup for an owner

### Gmail application

1. Create or select a Google Cloud project and enable the Gmail API.
2. Configure the consent application and create an OAuth client of type Web application.
3. Copy the exact callback displayed in **Settings → Email** into the client's authorized redirect URIs. The path is `/settings/email/callback` on this installation's domain.
4. Save the client ID and secret in the administrator section of Email settings.
5. Each operator connects their own Google account, reviews consent, refreshes verified sender aliases, and saves the correct sender for each website.

The code requests `gmail.send` and `gmail.settings.basic`; it uses the settings scope to read verified send-as addresses. Follow [Google's server-side authorization guidance](https://developers.google.com/workspace/gmail/api/auth/web-server) for consent and application configuration. A testing application can require approved test users and reconnection; review its publishing configuration before a broader rollout.

### GA4 and Search Console application

1. Enable Google Analytics Data, Google Analytics Admin, and Search Console APIs in the intended Google Cloud project.
2. Configure a Web application OAuth client and use the exact callback shown in **Settings → Traffic & SEO**, ending in `/settings/traffic/callback`.
3. Save the client credentials in that settings page's administrator section.
4. Connect the account holding property access. The reporting connection requests read-only Analytics and Search Console access plus account email identification.
5. Map each WP Hub website to its GA4 property and Search Console site, request a report, and verify coverage.

The application uses an interactive Google account connection. Do not substitute a service-account JSON key for the client ID/secret fields. See the [Analytics Data API overview](https://developers.google.com/analytics/devguides/reporting/data/v1) for the reporting API; WP Hub's own settings govern this integration's account flow.

### Brevo and domain identity

1. Prepare a Brevo account with the correct verified senders and authenticated domains.
2. Add the required DNS records through the domain's DNS provider, using Brevo's exact displayed values for that domain.
3. Connect its API key through **Settings → Marketing** and verify the resulting sender list.
4. If IP authorization is enabled, obtain the deployment's current outbound addresses from the host and authorize those in Brevo. Recheck when changing host or region.
5. Verify campaign-event reporting and send a test to the configured Brevo account address before scheduling a real audience.

Keep API keys and OAuth secrets out of this guide, source control, and support messages. Reconnecting services during a transfer may invalidate saved previews or scheduled work; review that work before changing connections.

## Fluent Forms integration contract

The Fluent Forms receiver expects the form and submission identifiers under `__submission`, with ordinary form fields at the top level. The token belongs in the webhook URL's query string, not in the body.

Example with synthetic data:

```json
{
  "__submission": {
    "id": 123,
    "form_id": 4,
    "email": "customer@example.com",
    "created_at": "2026-09-22 10:00:00"
  },
  "email": "customer@example.com",
  "name": "Example Customer"
}
```

1. Configure the site's form integration to supply the real submission and form IDs in this structure; both must be positive integers.
2. Retain the actual field keys used by the form and supply the relevant reservation data.
3. Submit a controlled entry and check Website health before relying on the integration.
4. Sync the form schema to populate human-readable labels.
5. Test any optional payment, order-linking, flight, or PNR fields against the intended form. The minimal example above does not establish those optional integrations.

The receiver normalizes a single-item array wrapper, but arbitrary plugin payload shapes are not automatically supported. The authoritative mapping is in `FluentWebhookPayload` and `ProcessFluentWebhookEvent`; retain any site-side customization when handing over the websites.

## Background processing and live updates

There are two different queues: the **Order work queue** is the user's fulfilment view; Laravel's queue processes background jobs.

### Hosting checklist

1. Keep Laravel's scheduler active, normally by invoking `php artisan schedule:run` every minute through the host.
2. Match the worker setup to `QUEUE_CONNECTION`. For an asynchronous connection, run a worker for the default queue so webhook and related jobs are processed. A synchronous configuration executes jobs in the request and does not provide the same isolation.
3. Keep the dedicated reporting connection/queue separate. The scheduler currently drains the `traffic` queue with bounded work each minute.
4. Configure the broadcasting provider and matching frontend values so live order events reach the browser. Verify the authenticated connection indicator with a controlled event.
5. After deployment, verify scheduler and worker state in the host, not only that the website loads. Check for unintended pending configuration changes.

### Scheduled work in the current code

| Task | Schedule | Purpose |
| --- | --- | --- |
| WooCommerce reconciliation | Every five minutes | Recover changed orders using an incremental scan after the initial history scan. |
| Reporting worker | Every minute | Process bounded GA4/Search Console reporting jobs. |
| Useful-alert scan | Every five minutes | Reassess grouped issues using stored records. |
| Marketing processing | Every minute | Prepare and submit eligible scheduled campaigns. |
| Email-preview pruning | Daily | Clear expired saved attachment snapshots. |
| Report pruning | Daily | Clean up reporting snapshots. |

Review `routes/console.php` and `config/queue.php` when changing this setup. A page refresh cannot repair a stopped scheduler, a failed worker, or an invalid provider credential.

## Deployments, backups, and recovery

### Release a change

1. Review the diff against the latest repository version and run the tests relevant to the change, along with frontend builds when applicable.
2. Back up the database before a migration or material data change. Verify that the backup can be restored and that the application key and other required configuration are recoverable securely.
3. Deploy the reviewed commit through the configured hosting pipeline. In the existing workflow, Laravel Cloud deploys the `main` branch.
4. Wait for build, deploy commands, and traffic routing to complete successfully.
5. Check sign-in, a read-only order view, the changed feature, background processing, and live updates. Use controlled test recipients for email testing.
6. Record the release commit and any migration or configuration steps. If a release fails, inspect the build/deploy logs before attempting another release.

### What the Updates page does

**Settings → Updates** is an administrator action that runs migrations, clears caches, and records the installed application version. It does **not** fetch new source code or replace the hosting deployment pipeline. Review backups and the code already deployed before using it.

### Recover safely

Keep database backups, the matching application key, private configuration, and any separately stored files together in the recovery plan. Reverting a commit does not automatically reverse database changes. Validate a restore in an isolated environment and disable production outbound sends there before starting scheduled work.

For Google callbacks that repeatedly return to login, verify the exact HTTPS callback and session cookie configuration. This application previously required `SESSION_SAME_SITE=lax` for the cross-site callback, with secure/HttpOnly cookies retained. Start a fresh connection after correcting configuration; do not reuse an old callback URL.

## Transfer to a future owner

Use this sequence to prepare a practical handover without treating a source-code copy as a completed business transfer.

1. Agree what is being transferred: source code, domain names, branding and logos, WordPress sites, hosting, provider accounts, and any permitted data. Record exclusions.
2. Prepare a separate demonstration using synthetic orders and contacts. Walk the buyer through the user guide without showing production secrets or customer PDFs.
3. Deliver the reviewed source version, dependency lock files, this documentation, deployment procedure, and a list of required external services. Preserve applicable license notices.
4. Set up the new owner's controlled hosting and administrative access. If migrating an existing installation, plan database ownership and retain the key required to decrypt it. If creating an empty installation, generate a fresh key.
5. Move or recreate each external integration using the provider's supported process. Update domain URLs, Google callbacks, WordPress webhooks, Brevo sender verification, outbound IP authorization, and website-property mappings.
6. Review scheduled campaigns and unsent document previews before switching credentials. Decide which should be cancelled or prepared again; do not let test environments send production messages.
7. Validate one controlled order event, one form entry, live updates, a document-email test, a marketing test, reporting refresh, and recovery access.
8. Confirm the new owner can deploy and restore a backup. Then remove obsolete access and rotate credentials through a planned transition that keeps the application working.
9. Record the acceptance date, known limitations, unresolved provider approvals, and who provides support after handover.

There is no one-click ownership-transfer wizard in the current app. Website ownership, private user connections, and customer data need an explicit migration plan. Third-party billing, account verification, and branding rights must be handled with the respective providers and owners.

## Documentation maintenance

The in-app guide is rendered from `docs/USER_GUIDE.md` and `docs/OWNER_GUIDE.md`. Both are included in the downloadable Markdown guide.

1. Update the relevant chapter whenever a feature, field label, limit, or workflow changes.
2. Keep steps grounded in the implemented interface. Clearly label prerequisites and provider-dependent features.
3. Use synthetic examples and relative app links. Do not add customer information, keys, account-specific configuration, or private screenshots.
4. Retain a reviewed date and include documentation changes in the same release as the feature.
5. Verify chapter links, search, mobile layout, and download after major documentation changes.

Level-two Markdown headings define chapters in the in-app guide. Keep chapter titles stable where possible so saved links continue to work. More detailed engineering notes can remain in `docs/` without being exposed as user instructions.
