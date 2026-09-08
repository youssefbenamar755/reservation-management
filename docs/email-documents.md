# Email order documents through Gmail

Open **Settings → Email** to configure the integration. It sends only after a user reviews an order email and clicks **Send**. Upload the final PDFs downloaded from the booking system; this feature does not generate or change reservation documents.

## Google application setup

1. In the [Google Cloud console](https://console.cloud.google.com/), select a project and enable the **Gmail API**.
2. Configure Google Auth Platform branding/audience and the consent screen for this application. Request `https://www.googleapis.com/auth/gmail.send` and `https://www.googleapis.com/auth/gmail.settings.basic`. The app uses the latter to read verified sender aliases; it does not read the inbox or change Gmail settings.
3. Create an OAuth client with type **Web application**. Add the exact callback displayed in WP Hub's Email settings. For this deployment it is `https://wphub.website/settings/email/callback`.
4. Enter the client ID and client secret in the administrator section of Email settings. Secrets are encrypted in the database and never returned in page props or stored in the repository.
5. Click **Connect Gmail** and complete Google's account selection and consent. Each WP Hub user connects their own Gmail account.
6. Select a verified sender for each website and save its subject, message, and plain-text signature. Supported placeholders are `{{customer_name}}`, `{{order_number}}`, and `{{website_name}}`.

Google controls consent and verification requirements. An external OAuth app left in Testing can require frequent reconnection; use the appropriate publishing/verification configuration for continuing use. See Google's [web-server authorization guide](https://developers.google.com/identity/protocols/oauth2/web-server) and [scope reference](https://developers.google.com/workspace/gmail/api/auth/scopes). The Gmail password is not entered in WP Hub.

## Sending

1. Open an order and choose **Email documents**.
2. Check the prefilled recipient, subject, message and sender. Add the PDF files downloaded from the booking system (up to five files, 5 MiB each and 10 MiB total, subject to server upload limits).
3. Prepare the preview. Check the recipient and complete attachment list, then click **Send**. Returning to edit requires a new preview.
4. Consult the order's email history for the result. `Sent` means Gmail accepted the email; it is not a delivery or read receipt. A known rejection can be retried explicitly. For an uncertain result, check Gmail Sent before taking further action.

Only the saved, immutable preview is sent. Requests cannot override the From address or attachments at send time. Identical content is protected against duplicate preparation/sending. No timer polls Gmail and no automatic send retry runs.

## Storage and operations

Prepared messages and uploaded attachments are encrypted in a bounded database snapshot, so pending uploads do not depend on an individual Laravel Cloud instance's filesystem. Preview attachments expire after 24 hours; successfully sent attachment snapshots are removed immediately. Metadata remains in the order's email history. The daily `emails:prune-previews` command clears expired attachment snapshots. Keep the existing Laravel scheduler enabled.

Changing Google application credentials or disconnecting/reconnecting a mailbox invalidates old previews. Changing the website's sender requires a new preview. Credentials and file contents must not be included in diagnostic logs.

Validation uses fake Google HTTP responses and synthetic customer PDFs. Do not use real customer email delivery as a deployment smoke test.

## Connection troubleshooting

If Google consent returns to WP Hub's login page, verify `SESSION_SAME_SITE=lax` in the deployment's effective session configuration. `Strict` blocks the session on Google's top-level cross-site GET callback. With the cookie session driver, both the session identifier cookie and its payload cookie must be Lax; changing only one cookie is insufficient. Keep `SESSION_SECURE_COOKIE=true` on HTTPS, HttpOnly enabled, and the existing session driver.

After updating deployment configuration and rebuilding its configuration cache, open WP Hub, sign in if needed, then start **Connect Gmail** again so the browser receives fresh cookies and a new one-time OAuth state. Do not reuse an old callback URL. Authentication, CSRF protection, the ten-minute state lifetime, and PKCE remain required. See the [browser's SameSite cookie rules](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Set-Cookie#samesitesamesite-value).
