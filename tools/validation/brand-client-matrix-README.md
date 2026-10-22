# Isolated actual-client brand matrix

This validation tooling does not deploy, activate live profiles, install on live stores, or send during setup. It uses the five saved disabled local profiles and their own verified sender aliases. Canada is excluded because its sender is missing; another brand's sender is never substituted.

The fixed matrix has **20 messages**: five brands × French/English × acknowledgement/document delivery. English goes only to the approved Gmail inbox with one PDF; French goes only to the approved Outlook inbox with two PDFs. Acknowledgements have no attachments. This is the smallest matrix that covers both kinds and both languages for every configured brand. It does not prove every brand/language combination in both clients; that would require 40 messages.

Actual WooCommerce paid-processing transitions generate the acknowledgement HTML, plain content, sender and Reply-To in a fresh local database. The MU plugin intercepts `wp_mail` before delivery. The immutable reviewed MIME then uses the approved isolated Gmail connection. This proves Woo content plus Gmail API delivery when actually sent and received; it does **not** verify stock WordPress SMTP or each live store's mail plugin. Document messages use the real app renderer with harmless generated PDFs. Prior end-to-end store/delivery/completion tests remain separate evidence.

## Prepare without sending

From the repository, with the known local PHP/Python executables:

```powershell
& 'C:/xampp/php/php.exe' -d disable_functions=mail tools/validation/local-fixture.php --create=C:/Users/AERO/Documents/Codex/2026-09-06/i/outputs
& 'C:/xampp/php/php.exe' -d disable_functions=mail,is_readable tools/validation/brand-client-matrix.php --fixture=NEW_PRIVATE_LOCAL_FIXTURE --phase=snapshot
& 'C:/Users/AERO/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe' tools/validation/prepare-brand-runtime-packages.py --with-french
& ./tools/validation/run-brand-ack-capture.ps1 -Profiles NEW_PRIVATE_LOCAL_FIXTURE/brand-profiles.json -WordPressPackage C:/Users/AERO/Documents/Codex/2026-09-06/i/outputs/woo-integration/wordpress-7.1.3.zip -WooCommercePackage C:/Users/AERO/Documents/Codex/2026-09-06/i/outputs/woo-integration/woocommerce.11.2.1.zip
& 'C:/xampp/php/php.exe' -d disable_functions=mail,is_readable tools/validation/brand-client-matrix.php --fixture=NEW_PRIVATE_LOCAL_FIXTURE --phase=prepare --ack-captures=NEW_PRIVATE_WOO_FIXTURE/brand-ack-captures.json
& 'C:/Users/AERO/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe' tools/validation/brand-client-matrix-audit.py NEW_PRIVATE_LOCAL_FIXTURE
```

Snapshot reads only the exact already approved donor fixture using SQLite query-only mode. It privately decrypts and re-encrypts that isolated OAuth credential into the new fixture. Credentials never appear in source or reports. No donor bootstrap or update occurs. This is an access-scope claim, not a measured donor hash comparison. The target defaults to capture and leaves every workflow disabled, with no jobs and an empty store-origin allowlist.

The package helper accepts only the exact official versions, checks embedded version strings, records SHA256 hashes and compares WordPress's published SHA1. With `--with-french`, it resolves the official French core/Woo translation packages and records their hashes. The capture requires those catalogues and selects the corresponding locale for each order. The capture runner binds only its new MariaDB instance on loopback 33177, verifies its datadir, disables gateways/cron/webhooks/external HTTP/native mail, enables HPOS with compatibility sync off and Woo `email_improvements`, and stops only the process it started. It records actual runtime versions. PHP 8.2.12 is not PHP 8.5.11 compatibility proof. A stopped partial run remains private for inspection; do not reset and resend its records.

The original donor fixture and its browser server are not changed. Snapshot and preparation are one-time actions. No automatic cleanup deletes failed evidence. Raw MIME, PDFs, tokens and fixture databases remain outside source/release ZIPs.

For the broader companion regression on these newer packages, run the following separately after the acknowledgement runner has stopped its database:

```powershell
& ./tools/validation/run-brand-runtime-compatibility.ps1 -WordPressPackage C:/Users/AERO/Documents/Codex/2026-09-06/i/outputs/woo-integration/wordpress-7.1.3.zip -WooCommercePackage C:/Users/AERO/Documents/Codex/2026-09-06/i/outputs/woo-integration/woocommerce.11.2.1.zip
```

This creates another fresh datadir, validates the owned database process and exact datadir/port before creating databases, and runs the real companion suite in separate HPOS and legacy databases. Its notifications remain capture-only to the fixed `.invalid` harness address. Receipt, transition, transaction and mail-hook checks use fictional provider proofs; they are separate from the actual Gmail transport matrix.

## Review and explicit send gate

Review `brand-pre-send-review.json`, every MIME/header/body, actual logo visibility from outside Morocco, and the exact twenty-record manifest. Audit verifies saved MIME hashes, recipient/sender/Reply-To, absence of CC/BCC, separate HTML/plain content, long names, counts, and strict PDF parsing/hash matching. It does not claim visual client review.

Only after the operator confirms both logo reachability and the manifest, create a separate `brand-send-approval.json` in the new private fixture:

```json
{"matrix_sha256":"THE_REVIEWED_MATRIX_SHA256","manifest_reviewed":true,"foreign_logo_fetch_passed":true}
```

This is an explicit operator checkpoint; the preparation/audit tool never creates it. Then invoke one exact immutable ID:

```powershell
& 'C:/xampp/php/php.exe' -d disable_functions=mail,is_readable tools/validation/brand-client-matrix.php --fixture=NEW_PRIVATE_LOCAL_FIXTURE --phase=send --record=EXACT_REVIEWED_UUID
```

There is no send-all or retry argument. Each invocation verifies the reviewed hashes, locks the ledger, records an attempt before network access, checks the genuine Gmail sender alias again, then submits the already reviewed bytes. A record that has started can never be retried by this tool, including a definite failure. Uncertain outcomes remain review-only. A successful provider ID is acceptance evidence, not receipt proof. Native PHP mail stays disabled and only the fixed two addresses are allowed; CC/BCC are rejected.

## Received-client checks

For each received message, record client/version, exact subject/brand/kind/language, From, Reply-To and attachment count. Inspect desktop plus a genuinely narrow client view, then block images in the client and confirm readable brand/body text remains. Do not label a local HTML screenshot or browser resizing as a native mobile-app check. Record actual viewport and whether the client supports blocked images.

Open all received PDFs. Export each received message through the client's official UI where available. Compare decoded PDF filename, size and SHA256 to the immutable matrix and strict-open the received PDFs; email providers may replace Message-ID/Date or normalize HTML/plain formatting. Separate exact attachment-byte proof from semantic body equivalence. Gmail user confirmation is useful but is not a received-byte hash comparison.

Log remaining limitations explicitly: another language/client pairing, device/client combinations not exercised, any untranslated Woo stock strings, live SMTP plugins, other theme/MU hooks, and the actual production PHP/runtime. Do not infer production readiness from this matrix alone.
