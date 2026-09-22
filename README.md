# WP Hub

A workspace for connected WordPress websites, WooCommerce orders, Fluent Forms submissions, customer records, document fulfilment, marketing, and Google reporting.

## Documentation

Sign in and open **Documentation** in the sidebar (`/documentation`) for searchable, step-by-step guides. Use **Download guide** to save both guides as Markdown for onboarding or handover.

- [User guide](docs/USER_GUIDE.md): getting started, every main feature, daily workflows, and troubleshooting.
- [Owner and handover guide](docs/OWNER_GUIDE.md): installation, integrations, background processing, deployments, recovery, and transfer planning.
- [Document email engineering notes](docs/email-documents.md): Gmail integration, snapshots, optional open tracking, and session troubleshooting.

## Features

- Dashboard, order search including transaction IDs, inline status updates, and an order work queue.
- Website connection settings, WooCommerce/Fluent Forms webhooks, fallback imports, and webhook recovery.
- Structured submission details, customer views, WhatsApp links, and customer CSV exports.
- Reviewed Gmail document emails with PDF uploads, sender aliases, history, and optional open detection.
- Brevo marketing audiences by website and source, branded templates, reviewed campaigns, and provider event reporting.
- Business analytics, GA4/Search Console reports, SEO opportunities, alerts, notifications, and action history.

## Development

Laravel 12, PHP 8.2+, Vue 3, TypeScript, Inertia, Tailwind CSS, and Vite. Install locked dependencies with `composer install` and `npm ci`. Follow the [installation guide](docs/OWNER_GUIDE.md#installation-and-environment) to configure a separate environment, database, application key, and integrations before running the app.

```sh
php artisan test
npm run test:frontend
npm run build:ssr
```

Use synthetic records and fake provider responses in tests. Do not send customer emails as a deployment check. See `routes/console.php` and `config/queue.php` for scheduled and background work.

## Maintaining the guide

The in-app documentation reads `docs/USER_GUIDE.md` and `docs/OWNER_GUIDE.md`; level-two headings become chapters. Update the guide alongside feature changes. Never put credentials, customer data, or private environment values in documentation.

This application builds on the Laravel Vue starter kit. Preserve the repository's applicable license notices and review third-party dependency and asset licenses when distributing it.
