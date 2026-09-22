# WP Hub user guide

Last reviewed: 22 September 2026. This guide describes the features currently implemented in WP Hub. Menu items and available actions depend on your account and the connected services.

## Start here

WP Hub brings your WordPress websites, WooCommerce orders, Fluent Forms entries, customer records, email workflows, and Google reports into one workspace. Each record belongs to a website. Select the website first whenever you need to work on one brand.

### Your first session

1. Open your WP Hub address and sign in. Ask your administrator for an account if you do not have one.
2. Open your name at the bottom of the sidebar, then **Settings**. Check your profile, change your initial password, and configure **Two-Factor Auth** if available.
3. Open [Websites](/websites). Confirm that the websites you expect are listed. A new account does not automatically inherit another user's websites or Google connections.
4. Open [Dashboard](/dashboard). Choose a website and a reporting period to see the scope of the figures.
5. Open [Orders](/orders), then an order number. Check its website, customer, items, and status before taking action.
6. If you send documents, complete **Settings → Email**. If you send campaigns, complete **Settings → Marketing**. For website traffic, use **Settings → Traffic & SEO**. These are separate connections.

**Already connected?** Start with the daily workflow chapter. **Setting up a new installation?** Follow the owner guide as well.

### Navigation basics

- The sidebar opens each feature. Collapse it when you need more space; on a small screen, use the sidebar toggle.
- The bell opens notifications. Your name opens account options and Settings.
- Website and date filters change the scope of a page. Check them before comparing numbers across pages.
- Use **Reset filters** when a search appears empty. Pagination displays only part of the matching data; totals can cover all matching pages.
- Dates follow the timezone label on each page. Google reports can use a different reporting timezone from orders.

## Daily workflow

Use this sequence to work through new reservations without losing track of an email or an order.

1. Review [Useful alerts](/alerts) for failed imports, document-email problems, and orders needing attention.
2. Open [Order work queue](/order-work-queue), select the website, and review waiting orders before preparing documents.
3. Open the order or linked submission. Verify the customer, itinerary, traveller details, payment state, and purchased service.
4. Prepare the reservation in your booking system and download the final PDFs. WP Hub's document email workflow accepts those files.
5. Choose **Email documents**, review the recipient and sender, upload the PDFs, prepare a preview, then explicitly send it.
6. Check the email result. If Gmail accepted the message and your fulfilment work is complete, update the order status as appropriate.
7. Check the next item in the queue. A completed order leaves the active work queue but remains in Orders.
8. At the end of the day, check unresolved alerts and **Settings → Action history** for failed or uncertain actions.

Sending an email and completing an order are separate actions. Do not assume that one automatically performs the other.

## Dashboard overview

Open [Dashboard](/dashboard) for a summary of activity across the selected websites.

1. Select one website or all websites.
2. Choose **Today**, **7 days**, **30 days**, or **This month** as needed.
3. Review the order, submission, customer, and revenue information. Read each card's description to understand the metric.
4. Use the attention area to review pending, on-hold, and processing orders.
5. Check activity trends, order-status breakdowns, website performance, recent orders, and recent entries.
6. Open an order or entry from its table to investigate the underlying record.
7. Where a status control is available in the recent-orders table, select the intended status and review the result of the update.

Revenue and customer counts are not the same as profit or unique people across all platforms. Currency labels matter: do not add different currencies as though they were converted into a single currency.

## Connect a website

You need access to the WordPress installation and permission to create its integration credentials.

1. Open [Websites](/websites) and choose **Add Website**.
2. Enter the website name, its full HTTPS base URL, and its timezone.
3. For WooCommerce, enter the **Consumer Key** and **Consumer Secret**. In WordPress, these are managed under **WooCommerce → Settings → Advanced → REST API**. Use an account allowed to manage orders; updating statuses requires Read/Write access.
4. For Fluent Forms, enter the WordPress username and an **Application Password** for the integration. The site's REST API and Fluent Forms endpoints must be available to that user.
5. Choose **Create Website**. Open the website's edit page, check that its status is active, and use each **Test Connection** button.
6. Save credential changes before testing them. Blank credential fields on the edit page retain existing saved secrets where indicated.
7. Configure webhooks as described below, then import existing orders or form entries using the available sync actions.

### WooCommerce live order updates

1. On the website edit page, copy its WooCommerce webhook URL and obtain its webhook secret through the protected reveal action if needed.
2. In **WooCommerce → Settings → Advanced → Webhooks**, create an active webhook for **Order created** using that URL and secret.
3. Create a second active webhook for **Order updated** using the same website's URL and secret.
4. Check delivery using a controlled order event, then review [Website health](/website-health) in WP Hub.

The secret is required by WP Hub even if WooCommerce labels it optional. Do not substitute another website's secret. See [WooCommerce's webhook settings](https://woocommerce.com/document/configuring-woocommerce-settings/advanced/) and [REST API credential guide](https://woocommerce.com/document/woocommerce-rest-api/).

### Fluent Forms submissions

1. Copy the Fluent Forms webhook URL from the website edit page, including its token when revealed.
2. Configure the relevant form's webhook integration to send to that URL.
3. Have the integration include the submission ID and form ID in the structure expected by WP Hub. The owner guide contains the payload contract; a generic webhook containing only customer fields is insufficient.
4. Submit a controlled test entry and confirm that it appears under the correct website and form.
5. Use schema sync if fields appear with technical names instead of their form labels.

Webhook URLs containing tokens are credentials. Keep them in the integration settings, not in shared screenshots or documentation.

## Orders and transaction search

Open [Orders](/orders) to find and review imported WooCommerce orders.

1. Choose the website or leave **All websites** selected.
2. Set the order period using the quick buttons or **Custom range**. The period applies to both the table and summary cards.
3. Search by order number, customer name, email, or a full or partial **transaction ID**. Transaction ID matching ignores letter case and uses the ID saved with the WooCommerce order.
4. Combine the search with an order-status filter, sort order, and rows-per-page setting.
5. Open an order number to see customer information, purchased items, linked form information, notes, and available document actions.
6. To change a status, use the status control in the Orders table, Dashboard table, or a linked submission's order section. On the order detail page, select **Order Status** and choose **Save Changes**.
7. Wait for the success or error response. A status update is sent to WooCommerce and can trigger that website's normal WooCommerce emails or automations.

### Refresh versus sync

**Refresh view** reloads the data already held by WP Hub. **Sync from WooCommerce** requests order data from the source website. Live updates refresh the view when order events arrive; the sync button remains available for recovery. Check the connection indicator if updates stop.

An order without a stored transaction ID cannot be found by transaction ID. Try its order number or customer email, then verify the payment details in WooCommerce. Transaction search is supported on the Orders page; the work queue's search currently covers order number, name, and email.

## Order work queue

The [Order work queue](/order-work-queue) is a working list for pending, on-hold, and processing orders. It is different from Laravel's background job queue.

1. Select the website and search for an order, customer name, or email if needed.
2. Choose a stage to focus on the next action. Sort the list using the available order controls.
3. Open the order, linked submission, or email action and review the context before continuing.
4. Return to the queue and use **Refresh queue** if the live connection is unavailable.

| Stage | Meaning | Next action |
| --- | --- | --- |
| Waiting | A pending-payment or on-hold order needs review. | Verify payment and requirements. |
| To prepare | The order needs a document email, or its previous preview expired. | Review details and prepare a fresh email preview. |
| Ready to send | A valid saved email preview exists. | Review the recipient and attachments, then send. |
| Sending | The send result is still being checked. | Wait and check the result before trying again. |
| Sent | Gmail accepted a document email. | Review whether fulfilment is complete and update the order separately. |
| Needs attention | An email failed or its result is uncertain. | Inspect the error or verify Gmail Sent before another attempt. |

Stages reflect the latest relevant email for your user and the order's status. Another user's Gmail history is not automatically your history. Completed, cancelled, refunded, and failed orders are outside this active queue; find them in Orders.

## Submissions and entry details

Open [Submissions](/submissions) to browse Fluent Forms data by website and form.

1. Choose the website and locate the form.
2. Open its entries list, then the entry you need to review.
3. Read the structured sections for traveller, flight, hotel, contact, payment, and other supplied information. Sections depend on the form data available.
4. If labels are missing or a form changed, use its **Sync schema** action and reload the entry.
5. Review the linked WooCommerce order section. When an order is linked and you have permission, you can change its status or choose **Email documents** from here.
6. If there is no linked order, use **View website orders** and locate it by customer or order information. WP Hub does not offer document sending from an unrelated entry without an order context.
7. Where flight details and the required integration are available, the entry offers PNR generation, ticket-code generation, copy actions, and PDF downloads. Review the result before using it; availability depends on the configured booking integration and complete input.
8. Use raw data only when troubleshooting field mapping. It may contain customer information.

Deleting an entry or all entries of a form removes stored WP Hub submission records. Read the confirmation carefully; deletion is not a way to cancel a WooCommerce order or a booking with an external provider.

## Customers and CSV exports

The [Customers](/customers) page summarizes imported order customers. The Marketing audience additionally includes eligible contact records discovered from Fluent Forms.

1. Select one website or all websites, then apply the available customer search and sorting controls.
2. Open a customer to review their orders, spending, and activity within the selected website scope.
3. Where a usable phone number is available, click it to open WhatsApp. Check that the number includes the correct country code.
4. To export, choose the intended website scope and filters before using the export action. Save the resulting CSV and check its headings and website information before using it elsewhere.
5. To export all websites you can access, clear the website selection first. An export follows the matching scope, not just the currently visible table page.

The same email can occur on several websites. Check the website context before contacting a person. A customer CSV is not automatically a list of marketing subscribers.

## Email documents through Gmail

This workflow sends individual order documents. Marketing campaigns use the separate Brevo connection.

### Connect your mailbox

1. Open [Settings → Email](/settings/email). If the Google application is not configured, ask an administrator to complete the application setup.
2. Choose **Connect Gmail**, select your Google account, and review Google's permissions.
3. Return to WP Hub and confirm that the connection is displayed.
4. Select a website and choose its verified **Sender address**. These addresses come from the connected Gmail account's send-as aliases.
5. Save the website's subject template, message template, and signature. Supported placeholders include `{{customer_name}}`, `{{order_number}}`, and `{{website_name}}`.
6. If you add or verify an alias in Gmail, refresh sender addresses in WP Hub before selecting it.

### Send PDFs for an order

1. Open an order, its linked submission, or its action in the work queue and choose **Email documents**.
2. Check the sender, recipient, subject, and message. Upload the final PDFs you downloaded from the booking system.
3. You can attach up to five PDFs, with a limit of 5 MiB per file and 10 MiB combined, subject to hosting upload limits.
4. Decide whether to enable **Track opens** for this message.
5. Prepare the preview. Review the complete attachment list and message before choosing **Send**.
6. If you edit anything, prepare a new preview. Only the saved preview is sent.
7. Review the email history for the result. **Sent** means Gmail accepted the message; it does not prove delivery or reading.

Saved previews expire after 24 hours. Sent attachment snapshots are removed; keep your original booking files in your normal document storage. For a failed send, inspect the error before explicitly retrying. For an uncertain send, check Gmail Sent first to avoid sending a duplicate.

## Email open tracking

Open tracking is optional for document emails and applies only when enabled before preparing that email.

1. Enable **Track opens** in the email composer.
2. Prepare the preview and verify that tracking is enabled for the saved message.
3. Send the email and later check its history for **Open detected** and the first detected time.

Detection means that a small image was requested. Images blocked by the email app can prevent detection. Privacy services, scanners, forwarded copies, or opening your own message in Gmail Sent can trigger it. Image caching can hide repeat opens. Attached PDF opens are not tracked.

Use this as a follow-up signal, not as proof that someone read or received a document. Earlier untracked emails cannot be retroactively tracked. Campaign open and click metrics are reported separately through Brevo.

## Analytics and business totals

Open [Analytics](/analytics) to review imported business activity over a period.

1. Select the website scope and a preset or custom date range.
2. Review the displayed revenue, order, customer, and submission metrics and their labels.
3. Compare trends over time, order statuses, and website breakdowns to identify where activity changed.
4. Open the relevant Orders or Submissions view to investigate the records behind a change.
5. If numbers look unexpected, align the website, period, status definition, and currency before comparing pages.

This page reports WP Hub's stored business records. Visitor acquisition and search keywords are on Traffic & SEO. Figures can differ from a payment-provider settlement statement because statuses, periods, refunds, currencies, and reporting definitions differ.

## Google traffic and search reports

### Connect and map each website

1. Open [Settings → Traffic & SEO](/settings/traffic).
2. Connect the Google account that has access to your GA4 properties and Search Console sites. It may be different from the Gmail sending account.
3. Refresh the available properties if necessary.
4. Select a WP Hub website, choose its **Google Analytics 4 property** and **Search Console site**, then save the mapping.
5. Repeat for each website. Connecting an account does not automatically choose the correct property for every domain.

### Read the reports

1. Open [Traffic & SEO](/traffic) and select the website and reporting range.
2. Review users, sessions, page/screen views, and engagement rate from GA4.
3. Use the source, page, country, and device breakdowns to understand acquisition and usage.
4. Review Search Console clicks, impressions, click-through rate, average position, queries, and pages.
5. Use the available refresh action to request updated reports. Requests can be queued; check report status, freshness, and website coverage instead of repeatedly clicking refresh.
6. Use the realtime section for its separate live-activity snapshot where available. It is not the same as the complete-day report.

The current date-range limit is 93 days. GA4 must already be collecting data on the website; this connection does not install a tracking tag. Search Console can omit or delay data, and query rows do not represent every keyword. User totals across multiple properties are not a deduplicated count of people across all websites. Read each report's coverage and timezone notes.

## SEO opportunities

Open [SEO opportunities](/seo) to find pages and queries worth reviewing using Search Console data.

1. Select a website with a saved Search Console mapping.
2. Choose the available date range and request a refresh if the report is missing or stale.
3. Filter opportunities by type and review the reason shown for each result.
4. Open a page's detail view to inspect its query data and the comparison period where available.
5. Make the actual content or metadata change in the website's WordPress editor, then compare later periods after Google has collected new data.

| Type | Current rule | What to review |
| --- | --- | --- |
| Near page one | Query has at least 100 impressions and average position above 10 through 20. | Relevance, useful content, and internal links. |
| Low CTR | Page has at least 100 impressions, average position 1–10, and CTR below 2%. | Search intent, title, and description. |
| Declining | A returned row loses at least 5 clicks and 20%, from at least 10 previous clicks. | Content changes, indexing, demand, and competing results. |

These are review rules, not guaranteed traffic gains. The report excludes the latest three days and uses Pacific-time Search Console dates. Results are bounded by returned API rows; an empty list does not mean every page has been assessed. WP Hub does not automatically publish SEO changes to WordPress.

## Website health and recovery

Use [Website health](/website-health) when expected orders or submissions do not appear.

1. Select the relevant website and inspect recent webhook events and their states.
2. Open a failed or delayed event to read its details.
3. Check that the website is active and its integration credentials are valid. For a 403 response, verify the correct WooCommerce signature secret or Fluent Forms token.
4. For a Fluent Forms validation error, check the submission/form identifiers and payload mapping.
5. After correcting the cause, use **Retry** where offered and inspect the resulting status.
6. If an order was never received, use Orders' WooCommerce sync fallback. If many events remain queued, ask the administrator to check background processing.

A quiet website may simply have no new events. A processed webhook confirms processing in WP Hub; it does not prove that every external service completed its work. Avoid repeated retries while a previous attempt is running.

## Useful alerts and notifications

Open [Useful alerts](/alerts) for grouped operational issues, including webhook processing, document emails, and aging processing orders.

1. Choose the website and alert filters.
2. Open the linked affected records and fix the underlying issue.
3. Use the check/refresh action to reassess the current state.
4. Choose **Snooze** to suppress the reminder for 24 hours when you intentionally defer it. Checks continue while snoozed.
5. Use **Resume** to restore the reminder earlier. Read the thresholds displayed on the page to understand why an alert exists.

The notification bell provides notices such as new orders, form submissions, and useful alerts. Open a notification to review it, then mark it read or mark all read. Marking a notice read does not resolve the underlying order or error.

## Marketing connection and audience

### Connect Brevo

1. Open [Settings → Marketing](/settings/marketing).
2. In Brevo, configure your business sender identities and authenticate the domains you will send from.
3. Create an API key and enter it directly in WP Hub. Choose **Connect Brevo** and check the listed verified senders.
4. If Brevo restricts API access by IP, authorize the application's outbound hosting IPs. The website's public Cloudflare IPs are not its outbound API addresses. Use the host's current network information and [Brevo's IP authorization instructions](https://help.brevo.com/hc/en-us/articles/5740111683858-Authorize-and-block-IP-addresses-for-API-security).

Connecting also configures the marketing event integration used to report campaign outcomes. An API connection alone does not verify every sender or remove Brevo account limits.

### Build an audience by website

1. Open [Marketing → Audience](/marketing/audience).
2. Use the discovery action to collect contacts from stored WooCommerce orders and Fluent Forms submissions. Review the website summary.
3. Select a website, then filter by source: **Fluent Forms contacts**, **Order customers**, **Form leads · no orders**, **Order customers · no forms**, or **Forms and orders**.
4. Refine by name/email, language, permission status, or the available customer segment.
5. Review a contact before recording marketing permission. Store the source and date when permission was actually given; discovery starts contacts with **No permission**.
6. For a CSV import, use the provided sample and columns: `email,name,locale,status,consent_source,consented_at`. The current limit is 5,000 rows / 2 MB. Review the import confirmation before submitting.

Permission is specific to the website. The same email can appear under multiple brands. A form submission or purchase alone does not mark a contact subscribed. Unsubscribed and provider-suppressed contacts remain excluded as appropriate; recording permission does not override Brevo suppression.

## Branded email templates

Open [Marketing → Templates](/marketing/templates) to create reusable messages for a website.

1. Choose the website and create a template or edit one already saved for that brand.
2. Enter a descriptive internal name and choose the language and layout. **Studio** provides a more visual layout; **Letter** provides a simpler message layout. Older templates may use Classic.
3. Select a verified sender and fill in the sender name and reply-to email.
4. Write the subject, inbox preview text, email heading, message, and signature.
5. Add a public HTTPS logo URL, brand accent color, and optional button text and HTTPS destination. Use assets belonging to that website.
6. Add optional heading/highlight content if it suits the message. The postal-address field is optional in the editor; include accurate sender details appropriate to the campaign's requirements.
7. Open the preview and inspect both **Desktop** and **Mobile** sizes. Check the logo, spacing, wording, and button destination.
8. Save the template. Use it to create a campaign draft when ready.

A logo URL must be reachable by recipients' email apps. Saving or previewing a template does not send email. Editing a template does not rewrite the content of an already-created campaign draft.

## Create and monitor a campaign

1. Confirm the Brevo connection and your website's verified sender.
2. From a saved template, create a campaign draft and give it a recognizable name.
3. Select the audience language, source, and customer segment. Segments include one completed order, repeat customers, and last order over 90 days ago.
4. Open the campaign and review the selected recipients, exclusions, website, sender, and saved message preview.
5. Use **Send test to my address** to send a real test to the Brevo account address shown. Check that inbox and inspect links and mobile rendering.
6. Choose **Send as soon as preparation finishes** or **Schedule for later**, set the date/time where needed, and read the final confirmation before submitting.
7. Monitor preparation and the final provider result from [Marketing](/marketing). Scheduling depends on the hosting scheduler being active.
8. Review delivery, estimated opens, clicks, bounces, unsubscribes, spam complaints, and skipped recipients as provider events arrive.

**Accepted by Brevo** means the provider accepted the campaign, not that every recipient received or read it. For **Needs verification**, check the campaign in Brevo before creating another send. Use cancellation only while the app offers it; messages already accepted by Brevo cannot be recalled. To revise a draft's message, edit its template and create a new draft.

## Settings and action history

Open your account menu, then **Settings**.

- **Profile:** maintain your name and email. Read the consequences before deleting an account.
- **Password:** change your sign-in password.
- **Two-Factor Auth:** follow the authenticator setup and confirmation steps, then store recovery codes securely.
- **Appearance:** choose the available theme preference.
- **Email:** connect Gmail and save website document-email defaults.
- **Marketing:** connect Brevo and review verified senders.
- **Traffic & SEO:** connect Google reporting and map website properties.
- **Action history:** inspect supported order-status requests, document-email actions, webhook retries, and alert snooze/resume actions.

### Investigate an action

1. Open [Settings → Action history](/settings/action-history).
2. Filter by website, actor, category, outcome, reference, or period.
3. Open the relevant linked record and compare the requested action with its result.
4. Treat **Pending** as unfinished and **Uncertain** as requiring verification, not success.

History covers the implemented tracked actions and their visibility rules. It is not an audit of every click, all Google/Brevo activity, or every historical change made directly in WordPress. Administrators additionally see **User Management** and **Updates**; see the owner guide before using these controls.

## Troubleshooting checklist

| Symptom | What to check first |
| --- | --- |
| No websites after signing in | Confirm the account and website ownership with your administrator. |
| An order cannot be found | Reset filters, verify the website, try order number/email, then sync if it is missing from WP Hub. |
| Live updates disconnected | Use Refresh view, then inspect Website health and ask the administrator to check broadcasting and background processing. |
| Google redirects to login | Sign in again and start a fresh connection from Settings. The administrator should inspect callback and session configuration if it repeats. |
| Google reports access blocked | Verify the chosen account, application test-user access, enabled APIs, and exact callback URL. |
| A Gmail sender is missing | Verify the alias in the connected Gmail account, refresh sender addresses, and save the website sender. |
| Document preview expired | Reattach the PDFs and prepare a new preview. |
| Email send is uncertain | Check Gmail Sent or the campaign in Brevo before another attempt. |
| A Google report is empty | Check website-property mapping, account access, date range, collection history, and coverage notes. |
| Campaign has no eligible recipients | Check the website, language, source, segment, recorded permission, and suppression state. |
| Campaign remains scheduled/preparing | Ask the administrator to check the scheduler and review the campaign error/state. |

When reporting a problem, give the page name, website, order or entry reference, approximate time, visible error, and steps to reproduce it. Do not include passwords, API keys, webhook tokens, raw customer payloads, or PDF attachments in a general support message.
