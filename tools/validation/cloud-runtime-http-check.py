"""One-shot HTTPS checks for the isolated final-runtime Cloud fixture.

Preparation only: this file does not run until invoked with an explicit approved
Cloud origin and a private fixture JSON outside the repository/public folders.
Use cloud-runtime-probe.php --phase=seed first. The private JSON contains its
safe IDs and marker, plus owner={email,password}, and independently observed
preflight exactly as documented in EXPECTED_PREFLIGHT below. Never pass the
password as a shell argument or print private JSON/session cookies.

Start this runner BEFORE the operator invokes --phase=deadline-lock in Cloud.
It requires a fresh unarmed fixture, observes the lock-ready transition, makes
one send POST, then one manual-sync POST. No retries of either write, no
redirects, no proxies, verified TLS, no public test routes or browser control.
Afterward run cloud-runtime-probe.php --phase=verify and invalidate the test
owner's password/sessions. This runner logs out in finally but does not change
Cloud credentials. Existing evidence is refused to prevent accidental replay.

Example:
python tools/validation/cloud-runtime-http-check.py \
  --expected-origin https://APP.laravel.cloud \
  --fixture C:/private/runtime-fixture.private.json \
  --output C:/evidence/final-runtime-http.json
"""

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import runpy
import sys
import time

# Reuse the already exercised verified-TLS/session/CSRF implementation. Loading
# via run_path does not invoke its __main__ block or make any HTTP request.
_helpers = runpy.run_path(str(Path(__file__).with_name("cloud-http-upload-check.py")))
Session = _helpers["Session"]
CheckFailure = _helpers["CheckFailure"]
require = _helpers["require"]

MARKER = "wphub-cloud-final-runtime-v1"
READY = "Fictional staging deadline lock ready; submit the approved probe once."
EXPECTED_PREFLIGHT = {
    "app_env": "staging",
    "validation_capture": True,
    "schema": "documents_staging",
    "document_disk": "documents_private",
    "no_real_credentials": True,
    "store_origins": [],
    "jobs": 0,
    "deadline_send_attempts": 0,
    "retry_sync_attempts": 0,
    "runtime_profile_enabled": True,
}


def saved(session, path, delivery_id):
    response, body, _ = session.request("GET", path)
    require(response["status"] == 200 and isinstance(body, dict),
            "Saved fictional delivery is unavailable")
    preview, outcome = body.get("preview"), body.get("delivery")
    require(isinstance(preview, dict) and isinstance(outcome, dict)
            and preview.get("id") == delivery_id and outcome.get("id") == delivery_id,
            "Saved delivery identity mismatch")
    require("private" in response["cache_control"] and "no-store" in response["cache_control"],
            "Saved delivery response must be private and no-store")
    return response, preview, outcome


def run(args):
    origin = args.expected_origin
    require(re.fullmatch(r"https://[a-z0-9-]+\.laravel\.cloud", origin),
            "An exact approved HTTPS Laravel Cloud origin is required")
    require(15 <= args.wait_seconds <= 180, "Lock readiness wait must be 15–180 seconds")
    private = Path(args.fixture).resolve()
    output = Path(args.output).resolve()
    repo = Path(__file__).resolve().parents[2]
    require(private.name.endswith((".private.json", "-private.json"))
            and private.is_file() and private.stat().st_size < 32768,
            "Use a small existing private fixture JSON file")
    require(not private.is_relative_to(repo)
            and "public" not in [part.lower() for part in private.parts],
            "Private credentials must be outside repository/public paths")
    require(output != private and output.parent.is_dir() and not output.exists(),
            "Use a new evidence file; an uncertain run must never be replayed")
    fixture = json.loads(private.read_text(encoding="utf-8-sig"))
    require(fixture.get("origin") == origin and fixture.get("probe") == MARKER
            and fixture.get("phase") == "seed" and fixture.get("ok") is True,
            "Exact successful seed origin/marker/phase required")
    require(fixture.get("preflight") == EXPECTED_PREFLIGHT,
            "Exact separately observed staging preflight required")
    require(fixture.get("ready_message") == READY, "Expected deployment ready marker changed")
    account = fixture.get("owner", {})
    require(set(account) == {"email", "password"}
            and account["email"] == "cloud-runtime-owner@example.invalid"
            and isinstance(account["password"], str) and len(account["password"]) >= 24,
            "Exact fictional owner and private ephemeral password required")
    for key in ("owner_id", "deadline_order_id", "retry_order_id"):
        require(type(fixture.get(key)) is int and fixture[key] > 0,
                "Positive observed owner/order IDs required")
    require(fixture["deadline_order_id"] != fixture["retry_order_id"], "Distinct fictional orders required")
    for key in ("deadline_delivery_id", "retry_delivery_id"):
        require(re.fullmatch(r"[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}", fixture.get(key, "")),
                "Exact observed fictional delivery UUID required")
    require(fixture["deadline_delivery_id"] != fixture["retry_delivery_id"], "Distinct delivery attempts required")
    deadline_order = f'/orders/{fixture["deadline_order_id"]}'
    retry_order = f'/orders/{fixture["retry_order_id"]}'
    deadline_path = deadline_order + "/document-deliveries/" + fixture["deadline_delivery_id"]
    retry_path = retry_order + "/document-deliveries/" + fixture["retry_delivery_id"]
    allowed = {("GET", "/login"), ("POST", "/login"), ("POST", "/logout"),
               ("GET", deadline_order), ("GET", retry_order),
               ("GET", deadline_path), ("GET", retry_path),
               ("POST", deadline_path + "/send"), ("POST", retry_path + "/sync")}
    session = Session(origin, allowed)
    report = {"ok": False, "probe": "wphub-cloud-runtime-https-v1", "origin": origin,
              "checked_at": datetime.now(timezone.utc).isoformat(), "state": "started",
              "send_posts_attempted": 0, "sync_posts_attempted": 0, "checks": {},
              "limits": [
                  "Real HTTPS session/CSRF/controller responses with a genuine database lock; no mocked application transport.",
                  "Capture-only fictional fixture: no real provider acceptance or remote store completion is claimed.",
                  "The deliberate send must fail before submission; any unexpected outcome stops all remaining writes.",
                  "Worker consumption/counters and absence of captures must also pass the independent Cloud verify phase.",
                  "No concurrent uploads or PHP-FPM memory measurement is part of this runner.",
              ]}
    with output.open("x", encoding="utf-8") as evidence:
        try:
            os.chmod(output, 0o600)
        except OSError:
            pass

        def save():
            evidence.seek(0)
            json.dump(report, evidence, indent=2)
            evidence.write("\n")
            evidence.truncate()
            evidence.flush()
            os.fsync(evidence.fileno())

        save()
        try:
            report["checks"]["owner_login"] = session.login(account)
            website_id = None
            for path, order_id, wp_id, purpose in (
                (deadline_order, fixture["deadline_order_id"], 900004, "deadline-before-capture"),
                (retry_order, fixture["retry_order_id"], 900005, "queue-validation-failure-only"),
            ):
                response, page, _ = session.request("GET", path, inertia=True)
                require(response["status"] == 200 and isinstance(page, dict), "Live fictional order unavailable")
                props = page.get("props", {})
                actual = props.get("order", {})
                user = props.get("auth", {}).get("user", {})
                website = actual.get("website", {})
                payload = actual.get("payload", {})
                require(actual.get("id") == order_id and actual.get("wp_order_id") == wp_id
                        and actual.get("status") == "processing"
                        and actual.get("customer_email") == "cloud-probe-recipient@example.invalid"
                        and payload.get("runtime_probe") == MARKER and payload.get("purpose") == purpose,
                        "Live order does not match the exact fictional runtime fixture")
                require(user.get("id") == fixture["owner_id"] and user.get("email") == account["email"]
                        and not user.get("is_admin") and website.get("user_id") == user.get("id")
                        and website.get("id") == actual.get("website_id") and website.get("status") == "paused"
                        and website.get("base_url") == "https://runtime.example.invalid" and props.get("notesAvailable") is False,
                        "Live fictional owner/paused credential-free website identity mismatch")
                website_id = website_id or website["id"]
                require(website_id == website["id"], "Fictional orders must belong to the same observed site")
                if wp_id == 900004:
                    require("deadline_lock" not in payload, "Deadline was already armed; start this caller before the Cloud lock command")
            report["checks"]["live_fixture_identity"] = True
            _, preview, current = saved(session, deadline_path, fixture["deadline_delivery_id"])
            require(current.get("status") == "prepared" and current.get("message") != READY
                    and preview.get("mode") == "partial" and preview.get("will_complete") is False
                    and preview.get("recipient") == "cloud-probe-recipient@example.invalid"
                    and preview.get("sender", {}).get("email") == "cloud-runtime-sender@example.invalid",
                    "Fresh exact prepared partial deadline delivery required")
            _, preview, current = saved(session, retry_path, fixture["retry_delivery_id"])
            require(current.get("status") == "sent" and current.get("captured") is True
                    and current.get("sync_status") == "pending" and current.get("sync_error") is None
                    and preview.get("mode") == "complete" and preview.get("attachments") == [],
                    "Fresh explicitly synthetic capture-only retry fixture required")
            report["state"] = "awaiting_cloud_deadline_lock_ready_transition"
            save()
            print(json.dumps({"state": report["state"], "evidence": str(output)}), flush=True)
            wait_started = time.monotonic()
            reads = 0
            while True:
                response, _, current = saved(session, deadline_path, fixture["deadline_delivery_id"])
                reads += 1
                require(current.get("status") == "prepared", "Deadline fixture changed before the single send")
                if current.get("message") == READY:
                    require(response["elapsed_seconds"] < 2,
                            "Ready-marker response was delayed; no send attempted because lock timing is uncertain")
                    break
                require(time.monotonic() - wait_started < args.wait_seconds,
                        "Cloud lock marker was not observed within the bounded wait; no send attempted")
                time.sleep(0.5)
            report["checks"]["lock_ready_transition"] = {"read_count": reads,
                "wait_seconds": round(time.monotonic() - wait_started, 3), "last_get_seconds": response["elapsed_seconds"]}
            report["state"] = "deadline_send_post_started_no_retry"
            report["send_posts_attempted"] = 1
            save()
            response, data, _ = session.request("POST", deadline_path + "/send",
                b'{"confirmed":true}', "application/json")
            report["checks"]["deadline_send"] = response
            outcome = data.get("delivery", {}) if isinstance(data, dict) else {}
            require(response["status"] == 200 and outcome.get("id") == fixture["deadline_delivery_id"]
                    and outcome.get("status") == "failed" and "No email was submitted" in str(outcome.get("message", ""))
                    and outcome.get("sent_at") is None and not outcome.get("captured") and outcome.get("gmail_url") is None,
                    "Expected definite no-submission failure was not returned; inspect Cloud state without retry")
            require(20 <= response["elapsed_seconds"] < 30,
                    "Observed deadline request was outside the intended 20–30 second contention window")
            report["checks"]["deadline_send"].update({"delivery_status": "failed", "no_submission_message": True})
            _, _, current = saved(session, deadline_path, fixture["deadline_delivery_id"])
            require(current.get("status") == "failed" and current.get("sent_at") is None
                    and "No email was submitted" in str(current.get("message", "")), "Saved deadline outcome changed")
            report["checks"]["saved_definite_failure"] = True
            report["state"] = "manual_sync_post_started_no_retry"
            report["sync_posts_attempted"] = 1
            save()
            response, data, _ = session.request("POST", retry_path + "/sync", b"{}", "application/json")
            report["checks"]["manual_sync"] = response
            outcome = data.get("delivery", {}) if isinstance(data, dict) else {}
            require(response["status"] == 202 and response["elapsed_seconds"] < 10
                    and outcome.get("id") == fixture["retry_delivery_id"] and outcome.get("status") == "sent"
                    and outcome.get("captured") is True and outcome.get("sync_status") == "pending",
                    "Manual retry did not promptly acknowledge the existing capture-only pending state")
            report["checks"]["manual_sync"].update({"queued_http": 202, "capture_fixture_only": True})
            wait_started = time.monotonic()
            while True:
                _, _, current = saved(session, retry_path, fixture["retry_delivery_id"])
                require(current.get("status") == "sent" and current.get("captured") is True
                        and current.get("sync_status") in ("pending", "syncing"), "Unexpected retry delivery transition")
                if current.get("sync_status") == "pending" and current.get("sync_error"):
                    break
                require(time.monotonic() - wait_started < 15, "Worker validation outcome not observed; run independent Cloud verification")
                time.sleep(0.5)
            report["checks"]["worker_validation_outcome_visible"] = True
            report["ok"] = True
            report["state"] = "http_checks_passed_independent_cloud_verify_required"
        except Exception as error:
            report["state"] = "stopped_no_retry"
            report["error_class"] = type(error).__name__
            report["error"] = str(error) if isinstance(error, CheckFailure) else "Operation failed; raw exception omitted to protect private credentials/session data"
        finally:
            if session.authenticated:
                try:
                    response, _, _ = session.request("POST", "/logout", b"", "application/x-www-form-urlencoded")
                    report["checks"]["logout_http"] = response["status"]
                    if response["status"] not in (200, 204, 302, 303):
                        report["ok"] = False
                        report["state"] = "logout_not_confirmed_operator_must_invalidate_fixture_session"
                except Exception:
                    report["checks"]["logout_http"] = "unconfirmed; operator must invalidate fixture credentials/session"
                    report["ok"] = False
            save()
    print(json.dumps({"ok": report["ok"], "state": report["state"], "evidence": str(output)}))
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--expected-origin", required=True)
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--wait-seconds", type=int, default=120)
    try:
        sys.exit(run(parser.parse_args()))
    except Exception as error:
        print(json.dumps({"ok": False, "stage": "local_input", "error_class": type(error).__name__,
                          "error": str(error) if isinstance(error, CheckFailure) else "Private input/output preparation failed; details omitted"}))
        sys.exit(1)
