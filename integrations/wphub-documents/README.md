# WP Hub Documents companion plugin

This reusable plugin supplies the store-side portion of WP Hub's opt-in document workflow. It contains no domain list and stores no Gmail tokens or WooCommerce API keys. Installing it alone does not enable branding or suppress completion messages.

## Requirements and installation

1. Use an isolated staging store first. Requirements: PHP 8.2+, WooCommerce 8.8+, MySQL/MariaDB with InnoDB order, order-metadata and private-note tables, and permission to use advisory locks and transactions. Both WooCommerce HPOS and legacy order storage are supported by the implementation; each actual store's versions, other plugins and persistent cache still need staging verification. WooCommerce 8.8 is the API floor for the scoped metadata-save filter, not a claim that every version from 8.8 has been tested.
2. Zip the `wphub-documents` directory, with `wphub-documents.php` at its root. Upload through WordPress → Plugins → Add New → Upload, then activate after approval.
3. In WP Hub, save the registered website's **Email & Documents** profile. Copy that website's pairing ID.
4. In the matching WordPress store, open WooCommerce → WP Hub Documents. Paste the pairing ID and save. A new/different pairing resets local activation to disabled.
5. In WP Hub, verify the integration. Its existing registered WooCommerce connection must use a manager-owned, read/write API key. Credentials stay in the HTTPS Authorization header, never the URL.
6. Review the website's brand, languages, approved sender, support details, required services and templates. Send only approved test emails with fictional data. Explicitly activate and synchronize that website's brand when ready.
7. Complete the staging checklist below before approving production installation/activation. The implementation does not install itself or alter live store settings.

The pairing ID is an identity check, not a credential. Every REST operation still requires WooCommerce authentication, `manage_woocommerce`, and order edit permission for order routes. Reusing one site's API credentials/pairing with another registry entry is rejected.

## Notification behavior

- WooCommerce remains the sole owner of the initial customer acknowledgement. The plugin customizes the existing customer processing/on-hold emails; it does not send another WPHub acknowledgement.
- Order lines, totals and payment information remain rendered by WooCommerce. Existing store mail transport and From address remain in use. Only the acknowledgement's display name, Reply-To, wording, colors and intro/footer use this website's saved profile.
- All acknowledgement configuration is in the WordPress options table. Rendering and the email-enabled filter make no HTTP calls to WP Hub.
- Paid wording requires both WooCommerce `is_paid()` and a recorded paid date. Unpaid/on-hold orders use fixed safe pending-payment wording instead of configurable paid copy.
- Language uses the first supported recorded locale from `_wphub_language`, `_order_locale`, `_order_language`, `wpml_language`, `_wpml_language`, or `pll_language`, then the configured website fallback. It never uses a customer's name to infer language.
- This language choice applies to the companion's subject, heading and added acknowledgement copy. Core WooCommerce greetings, order-table labels and additional content still use the store's native email locale. Install the appropriate WordPress/WooCommerce translations and verify the store's multilingual-email integration before activation. On WooCommerce 10.3, the locale setup hook runs before the current order is assigned to the email object; this plugin deliberately does not select a locale from a stale object. The English-only fixture therefore renders the French companion copy alongside English core labels, and does not prove a fully localized French email.
- Acknowledgement-only styles preserve a 16px mobile body and use the configured brand/text colors for headings, links and order details. Other WooCommerce email types retain their existing styles.
- Template substitutions are literal. Supported names match WP Hub: `{{brand_name}}`, `{{customer_first_name}}`, `{{order_number}}`, `{{document_count}}`, `{{document_list}}`, `{{support_email}}`, `{{website_url}}`, `{{service_label}}`. The acknowledgement has zero document attachments; its document count/list are `0`/empty. Customer values are escaped in HTML. French and English overrides are stored locally.
- The only suppression hook is `woocommerce_email_enabled_customer_completed_order`. It returns false only for a matching, full-fulfillment receipt that was saved before the completion transition. Unmanaged orders and previously disabled notifications retain their normal behavior.
- Refund, cancellation, payment, security, administrator, account and payment-provider receipts are not disabled. Custom plugin mailers can bypass WooCommerce's completed-email filter and must be checked on staging.

## Receipt and completion protocol

Base: `/wp-json/wc/v3/wphub-documents`. All routes use the existing WooCommerce key authentication and the `X-WPHub-Website` pairing header.

| Route | Method | Effect |
| --- | --- | --- |
| `/health` | GET | Pairing, version, enabled state and supported capabilities |
| `/brand` | PUT | Explicitly save the website's local brand and activation state |
| `/orders/{id}/eligibility` | GET | Current status, paid date, recorded customer, modification timestamp and strong fulfillment revision |
| `/orders/{id}/receipt` | POST | Save the sent-email proof for the exact order; no status change |
| `/orders/{id}/receipt` | GET | Independently verify the persisted receipt |
| `/orders/{id}/complete` | POST | Recheck receipt, configuration, payment, processing status and modification timestamp; then complete |

The receipt contains a delivery UUID, website identity, internal store order ID, Gmail message ID, sent timestamp, document-set hash, attachment count, configuration version, expected fulfillment revision and explicit full-fulfillment confirmation. It contains no customer PDF or OAuth credential. A conflicting receipt cannot silently replace it. Completion readiness requires the `order_revision` capability; an older companion cannot silently satisfy the new contract.

The Laravel adapter validates the durable sent result and fulfillment coverage, writes the receipt, performs a separate read to verify it, and only then requests completion. A final ordinary WooCommerce order GET updates WP Hub through its existing idempotent order store. If any stage fails, the caller retries store synchronization only; this adapter never sends email.

Writes use WooCommerce order CRUD, including HPOS metadata. A bounded advisory lock serializes plugin retries. A forced database transaction and row lock on the active order header table serialize normal WooCommerce status writes; cached order objects are evicted after acquiring the lock and on rollback. No direct postmeta/order updates are issued. A scoped WooCommerce HPOS filter prevents these integration-only metadata writes from changing the order modification timestamp. Private notes use `add_order_note(..., false, true)` and an idempotency marker.

The strong revision covers status, currency, customer/account and billing/shipping details, payment and transaction information, totals, customer notes and order line items with their metadata. It excludes integration order metadata and modified timestamps. This detects changes occurring within the same second, which WooCommerce's modification timestamp alone cannot distinguish. Both receipt creation and locked completion verify it. After `update_status`, the plugin evicts caches and rereads the persisted order/receipt before adding a success note and committing; WooCommerce can catch a failed save internally while leaving the in-memory status changed.

The HTTP adapter only accepts registered public HTTPS URLs. It resolves public addresses, rejects private/mixed DNS answers, pins the connection to the validated address, preserves TLS hostname verification, and disables redirects/proxies. Provider/store response bodies and credentials are never included in operator errors.

## Staging verification required

Laravel HTTP-fake tests and the small plugin contract harness remain mocked tests. The separate [real WordPress/WooCommerce fixture](tests/real/README.md) runs native order storage, REST dispatch, email generation and InnoDB transactions against a new fictional database, with captured mail and all external HTTP blocked. Its evidence must be distinguished from external Gmail/Outlook or store-specific staging validation; it does not establish production-plugin compatibility.

Before activation, verify on each site's actual stack:

1. Read/write API authentication and pairing; reject wrong IDs/keys and read-only credentials.
2. HPOS and legacy storage as applicable, including persistent object caching, synchronized HPOS backup tables, InnoDB row locks and the installed WooCommerce version.
3. A normally paid order receives one processing acknowledgement retaining order details. An unpaid order never says payment was received.
4. Full document delivery stores/reads its marker before completion. Only the redundant WooCommerce customer completion email is suppressed. The private note must not email the customer.
5. Unmanaged, refunded, cancelled and changed orders retain their proper behavior. A simultaneous manual state change must not be overwritten.
6. Repeating an already successful synchronization does not resend documents, duplicate notes or change unrelated orders. A connection drop after store completion is safely reconciled by a later retry.
7. Inventory actual store plugins/email automation (custom WooCommerce templates, checkout/funnel automations, CRM/marketing extensions, payment-provider receipts). This repository cannot prove which are installed or that none send an independent completion message.
8. Gmail, Outlook and mobile email rendering, including image-blocked mode and real PDF attachments. No actual email-client compatibility claim is made by these local tests.

## Rollback

Disable the website workflow in WP Hub and explicitly synchronize the disabled brand state. New combined send/complete operations stop; legacy/manual store notification behavior remains available. Already sent document receipts remain audit evidence. If restoring all default WooCommerce completion notifications is desired, deactivate the plugin after approval; do not delete order receipts or historic sending records. Re-pairing is also explicit and disables local branding, but should not be used as a routine rollback because it changes receipt identity validation.

## Local checks

```text
php integrations/wphub-documents/tests/contract.php
php vendor/bin/pest tests/Feature/DocumentStoreGatewayTest.php
php vendor/bin/pint app/Services/DocumentStoreGateway.php app/Exceptions/DocumentStoreException.php tests/Feature/DocumentStoreGatewayTest.php --test
```

On the provided Windows environment, PHP's `is_readable()` reports false for the Composer bootstrap although `require` works. The local-only `phpunit.documents.local.xml` configuration omits the redundant PHPUnit bootstrap because Pest has already loaded Composer; this avoids modifying vendor code or filesystem permissions.
