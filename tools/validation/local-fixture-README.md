# Isolated local Gmail validation fixture

This harness uses the current Laravel application and its normal login, authorization, Gmail settings and OAuth callback routes. It makes no production changes, does not copy production data or OAuth tokens, and does not start a worker or scheduler. It is not a deployment script.

## Create and inspect

Run from the repository root, using a private output parent outside `public` and `storage/app/public`:

```powershell
& 'C:/xampp/php/php.exe' -d disable_functions=mail tools/validation/local-fixture.php --create=C:/Users/AERO/Documents/Codex/2026-09-06/i/outputs
```

Creation allocates a new random folder, fresh SQLite database, independent APP_KEY, private storage and persistent database sessions. A clearly fictional operator, website, order and neutral brand profile are seeded. There are no WooCommerce credentials, Gmail application credentials, Gmail connections or verified sender claims. The workflow remains disabled. The fictional logo uses an intentionally unresolved `.invalid` URL and is not evidence of brand-logo rendering.

The exact approved recipients are `youssefbenamar755@gmail.com` and `benamar-y14@outlook.com`, each explicitly authorized by the user for fictional tests. Each email must have only one recipient and no CC/BCC. This fixed allowlist has no general recipient-input option. The fictional order retains its original Gmail customer address; approving the Outlook test address does not modify order data. Store origins are empty. Initial transport is capture. The existing application validation guard blocks unrelated HTTP services, inbound API webhooks, unrelated jobs and normal mail transport. The sample order is generated without source observers, preventing accidental marketing discovery. No real payment or remote order exists.

The folder contains:

- `fixture.json`: private APP_KEY and fixed safety settings. Never upload or print this file.
- `login-private.json`: local login credentials. Never upload or print this file.
- `fixture.sqlite`: isolated application state, including any later OAuth credentials encrypted with this fixture key.
- `storage/`: private document, capture, session/view and log files.
- `preflight.json`: non-secret evidence and counts.

The repository `.env` and configuration/route/event caches are never loaded. Provider-class discovery metadata (`packages.php` and `services.php`) is copied into the fixture cache only; these lists contain class names rather than environment values. This avoids a Windows sandbox `is_writable()` false negative without editing vendor code or weakening application checks. The harness registers Windows drive prefixes for Laravel's absolute cache path handling.

## Start the loopback server

Set `WPHUB_LOCAL_FIXTURE_PATH` to the exact generated folder. Start PHP bound to `127.0.0.1:8799`, but browse **http://localhost:8799** consistently. Do not use `php artisan serve` or `public/index.php` for this fixture; they load the usual environment.

```powershell
$env:WPHUB_LOCAL_FIXTURE_PATH = 'C:/absolute/path/to/generated/fixture'
$fixtureServer = Start-Process -FilePath 'C:/xampp/php/php.exe' -ArgumentList @('-d', 'disable_functions=mail', '-S', '127.0.0.1:8799', 'tools/validation/local-fixture-router.php') -WorkingDirectory (Get-Location).Path -WindowStyle Hidden -RedirectStandardOutput "$env:WPHUB_LOCAL_FIXTURE_PATH/server-stdout.private.log" -RedirectStandardError "$env:WPHUB_LOCAL_FIXTURE_PATH/server-stderr.private.log" -PassThru
$fixtureServer.Id
```

Keep both server logs private. They can contain OAuth callback code/state URLs. The router rejects a non-loopback caller or an unexpected Host header, never exposes its working directory or `/storage`, and serves only compiled build assets/icons directly. It disables SSR and browser off-origin connections; the existing browser bundle may otherwise contain production telemetry configuration. No authentication bypass is added. Sign in normally with the private local credentials, then use `/settings/email`.

## Configure fresh Google OAuth

Use a separate Google **Web application** OAuth client with the exact authorized redirect URI:

```text
http://localhost:8799/settings/email/callback
```

Enter that client's ID and secret directly into the local application settings. Do not copy a production Gmail token or connection. Use the normal Google consent flow; the connected account and verified sender addresses must be learned from Google. If the Google OAuth project is in Testing, its test-user list must include the account that authorizes the connection.

Capture transport blocks token exchange deliberately. Before attempting actual OAuth, explicitly change this isolated fixture to Gmail transport:

```powershell
& 'C:/xampp/php/php.exe' -d disable_functions=mail tools/validation/local-fixture.php --fixture=C:/absolute/path/to/generated/fixture --transport=gmail
& 'C:/xampp/php/php.exe' -d disable_functions=mail tools/validation/local-fixture.php --fixture=C:/absolute/path/to/generated/fixture --preflight
```

Each new HTTP request reads this state. Gmail mode allows only Google's token endpoint, verified sender lookup, and MIME sends to one of the two exact approved recipients. It does not authorize extra recipients or general outbound access. Changing transport itself performs no network request and sends nothing. Use the application's test-email action only after the genuine connection and sender selection are established. The test action generates explicitly fictional sample PDFs and leaves orders unchanged.

After tests, restore capture with `--transport=capture`. Stop only the known fixture-server process. Keep or securely archive private fixture state for the requested evidence; never include credentials, tokens, database or private logs in a release package.

## What the preflight proves

`--preflight` verifies the selected fresh database, both exact allowed recipients, blocked recipient, blocked service/store requests, normal authenticated callback route, disabled SSR, local session settings, and absence of seeded real connections. It exercises the real request guard directly and performs **zero network requests**. It does not prove actual Gmail consent/delivery, Outlook behavior, store HTTPS integration, or Cloud storage/worker persistence. Those checks need separate observed evidence.
