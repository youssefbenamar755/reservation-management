"""Actual HTTPS upload check; preparation only until an operator explicitly runs it.

Usage: python tools/validation/cloud-http-upload-check.py --fixture /private/http.private.json --output /private/new-evidence.json
The private input is prepared by a separate approved Cloud CLI action, never here:
{
  "origin": "https://reservation-management-documents-staging-o3nliw.laravel.cloud",
  "owner": {"email": "cloud-probe-owner@example.invalid", "password": "EPHEMERAL"},
  "other_owner": {"email": "cloud-probe-other@example.invalid", "password": "EPHEMERAL"},
  "order_id": 3, "wp_order_id": 900003, "cross_order_id": 1, "website_id": 1,
  "intended_customer_email": "cloud-probe-recipient@example.invalid",
  "fixture_marker": "wphub-cloud-http-upload-v1",
  "preflight": {"app_env": "staging", "validation_capture": true,
    "schema": "documents_staging", "document_disk": "documents_private",
    "no_real_credentials": true, "existing_documents": 0}
}
Set order.payload.staging_http_upload_probe to fixture_marker. Both users must
be fictional non-admins with no 2FA; owner must own the paused .invalid website.
The cross-order is existing same-owner fictional order 900001. Save passwords
only in the private input; never put them on the command line or in evidence.

Routes: GET /login, POST /login (email/password + X-XSRF-TOKEN cookie),
GET /orders/{database_id} (X-Inertia); multipart POST
/orders/{database_id}/document-deliveries/documents with files[], approved=1,
intended_customer_email. Reads use /documents/{uuid}; no prepare/send/sync calls.
No redirects, retries, unverified TLS, proxies, asset fetching or browser access.
An existing output is refused. After any uncertain POST, inspect Cloud state
before authorizing a new fixture/run; never blindly retry an upload.
"""

import argparse
import hashlib
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import re
import ssl
import sys
import time
from urllib.error import HTTPError
from urllib.parse import unquote, urlencode
from urllib.request import (Request, build_opener, HTTPCookieProcessor,
                            HTTPRedirectHandler, HTTPSHandler, ProxyHandler)
import uuid

ORIGIN = "https://reservation-management-documents-staging-o3nliw.laravel.cloud"
MARKER = "wphub-cloud-http-upload-v1"
MIB5 = 5 * 1024 * 1024


class CheckFailure(Exception):
    pass


def require(condition, label):
    if not condition:
        raise CheckFailure(label)


def pdf_bytes(target):
    """Valid one-page PDF; padding is inside a stream comment, with exact xref."""
    padding = target - 1000
    for _ in range(5):
        stream = (b"BT /F1 18 Tf 40 780 Td (FICTIONAL HTTPS upload sample) Tj "
                  b"0 -30 Td /F1 12 Tf (Not a reservation or travel document.) Tj ET\n%"
                  + b" " * padding + b"\n")
        objects = [b"<< /Type /Catalog /Pages 2 0 R >>",
                   b"<< /Type /Pages /Count 1 /Kids [3 0 R] >>",
                   b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
                   f"<< /Length {len(stream)} >>\nstream\n".encode() + stream + b"endstream",
                   b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"]
        result, offsets = b"%PDF-1.4\n", []
        for number, body in enumerate(objects, 1):
            offsets.append(len(result))
            result += f"{number} 0 obj\n".encode() + body + b"\nendobj\n"
        xref = len(result)
        result += b"xref\n0 6\n0000000000 65535 f \n"
        result += b"".join(f"{offset:010d} 00000 n \n".encode() for offset in offsets)
        result += f"trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
        if len(result) == target:
            return result
        padding += target - len(result)
    raise CheckFailure("Could not construct exact-size fictional PDF")


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class InertiaPageParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.page = None

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if "data-page" in attributes:
            self.page = json.loads(attributes["data-page"])


class Session:
    def __init__(self, origin, allowed):
        self.origin, self.allowed = origin, allowed
        self.cookies = http.cookiejar.CookieJar()
        self.opener = build_opener(ProxyHandler({}), NoRedirect(),
                                  HTTPSHandler(context=ssl.create_default_context()),
                                  HTTPCookieProcessor(self.cookies))
        self.authenticated = False
        self.version = None

    def csrf(self):
        values = [unquote(cookie.value) for cookie in self.cookies if cookie.name == "XSRF-TOKEN"]
        require(len(values) == 1 and values[0], "One current session CSRF cookie is required")
        return values[0]

    def request(self, method, path, body=None, content_type=None, inertia=False, limit=2 * 1024 * 1024):
        require((method, path) in self.allowed, "Request outside exact approved route allowlist")
        headers = {"Accept": "application/json", "User-Agent": "WPHub-Fictional-HTTPS-Validation/1.0"}
        if inertia:
            require(isinstance(self.version, str), "Observed Inertia asset version required")
            headers.update({"X-Inertia": "true", "X-Inertia-Version": self.version,
                            "X-Requested-With": "XMLHttpRequest"})
        if method == "POST":
            headers.update({"X-XSRF-TOKEN": self.csrf(), "Origin": self.origin,
                            "Referer": self.origin + "/login"})
        if content_type:
            headers["Content-Type"] = content_type
        request = Request(self.origin + path, data=body, headers=headers, method=method)
        started = time.monotonic()
        try:
            response = self.opener.open(request, timeout=30)
        except HTTPError as error:
            response = error
        with response:
            data = response.read(limit + 1)
            require(len(data) <= limit, "Unexpected oversized HTTP response")
            result = {"status": response.status, "content_type": response.headers.get("Content-Type", ""),
                      "cache_control": response.headers.get("Cache-Control", ""),
                      "elapsed_seconds": round(time.monotonic() - started, 3), "response_bytes": len(data)}
        try:
            decoded = json.loads(data) if "application/json" in result["content_type"] else None
        except (ValueError, UnicodeDecodeError):
            decoded = None
        return result, decoded, data

    def login(self, account):
        response, _, html = self.request("GET", "/login")
        require(response["status"] == 200, "Login page unavailable; no credential POST attempted")
        parser = InertiaPageParser()
        parser.feed(html.decode("utf-8"))
        require(isinstance(parser.page, dict) and isinstance(parser.page.get("version"), str), "Login page must expose its actual Inertia version")
        self.version = parser.page["version"]
        response, data, _ = self.request("POST", "/login", urlencode(account).encode(), "application/x-www-form-urlencoded")
        require(response["status"] == 200 and isinstance(data, dict) and data.get("two_factor") is False,
                "Fictional login failed or requires manual 2FA; no retry attempted")
        self.authenticated = True
        return response


def multipart(files, customer):
    boundary = "wphub-fixture-" + uuid.uuid4().hex
    parts = []
    for name, value in [("approved", "1"), ("intended_customer_email", customer)]:
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    for name, content in files:
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="files[]"; filename="{name}"\r\nContent-Type: application/pdf\r\n\r\n'.encode() + content + b"\r\n")
    parts.append(f"--{boundary}--\r\n".encode())
    return b"".join(parts), "multipart/form-data; boundary=" + boundary


def classify(response, data):
    """Retain only safe classifications/field names, never bodies, tokens or headers."""
    fields = data.get("errors", {}) if isinstance(data, dict) else {}
    fields = fields if isinstance(fields, dict) else {}
    messages = " ".join(str(message).lower() for values in fields.values()
                        for message in (values if isinstance(values, list) else [values]))
    result = dict(response, validation_fields=sorted(str(key) for key in fields))
    result["reached_laravel_field_validation"] = response["status"] == 422 and bool(fields)
    if response["status"] == 200 and isinstance(data, dict) and isinstance(data.get("documents"), list):
        result["classification"] = "documents_accepted"
    elif response["status"] == 422 and "failed to upload" in messages:
        result["classification"] = "php_uploaded_file_error_seen_by_laravel"
    elif response["status"] == 422 and "files" in fields and "10 mib" in messages:
        result["classification"] = "application_aggregate_size_validation"
    elif response["status"] == 422 and any(str(key).startswith("files.") for key in fields) and "5120" in messages:
        result["classification"] = "application_single_file_size_validation"
    elif response["status"] == 422 and fields:
        result["classification"] = "laravel_field_validation_other"
    elif response["status"] == 413:
        message = str(data.get("message", "")) if isinstance(data, dict) else ""
        result["classification"] = ("laravel_post_size_middleware" if "POST data is too large" in message
                                    else "request_too_large_layer_unconfirmed")
    elif response["status"] in (401, 403, 419, 429):
        result["classification"] = "authentication_csrf_access_or_rate_limit"
    else:
        result["classification"] = "unexpected_http_response"
    return result


def run(args):
    private = Path(args.fixture).resolve()
    output = Path(args.output).resolve()
    repo = Path(__file__).resolve().parents[2]
    require(private.name.endswith((".private.json", "-private.json")) and private.is_file() and private.stat().st_size < 32768,
            "Use a small existing .private.json or -private.json credential file")
    require(not private.is_relative_to(repo) and "public" not in private.parts,
            "Credential file must be outside repository/public paths")
    require(output != private and output.parent.is_dir() and not output.exists(),
            "Use a new output file; never blindly rerun an uncertain upload")
    fixture = json.loads(private.read_text(encoding="utf-8-sig"))
    require(fixture.get("origin") == ORIGIN and fixture.get("fixture_marker") == MARKER, "Exact approved staging origin/marker required")
    require(fixture.get("wp_order_id") == 900003, "Separate fictional order 900003 required")
    preflight = fixture.get("preflight", {})
    require(preflight == {"app_env": "staging", "validation_capture": True, "schema": "documents_staging",
                          "document_disk": "documents_private", "no_real_credentials": True, "existing_documents": 0},
            "Exact separately observed staging preflight required")
    for key in ("owner", "other_owner"):
        account = fixture.get(key, {})
        require(set(account) == {"email", "password"} and re.fullmatch(r"[a-z0-9.+_-]+@example\.invalid", account["email"])
                and isinstance(account["password"], str) and len(account["password"]) >= 20, "Fictional credentials required")
    require(fixture["owner"]["email"] != fixture["other_owner"]["email"], "Owners must be different")
    require(re.fullmatch(r"[a-z0-9.+_-]+@example\.invalid", fixture.get("intended_customer_email", "")), "Fictional customer required")
    for key in ("order_id", "cross_order_id", "website_id"):
        require(type(fixture.get(key)) is int and fixture[key] > 0, "Positive database IDs required")
    require(fixture["order_id"] != fixture["cross_order_id"], "Distinct cross-order required")
    order_path = f'/orders/{fixture["order_id"]}'
    cross_path = f'/orders/{fixture["cross_order_id"]}'
    upload_path = order_path + "/document-deliveries/documents"
    allowed = {("GET", "/login"), ("POST", "/login"), ("POST", "/logout"),
               ("GET", order_path), ("GET", cross_path), ("POST", upload_path)}
    owner, other, anonymous = (Session(ORIGIN, allowed) for _ in range(3))
    report = {"ok": False, "probe": MARKER, "origin": ORIGIN, "order_wp_id": 900003,
              "state": "started", "checks": {}, "mail_endpoints_called": 0, "queue_endpoints_called": 0,
              "limits": ["Actual HTTPS multipart/session route check; no UI, email, concurrency or slow-provider test.",
                         "A 413 response or PHP upload error does not prove application file-size validation passed.",
                         "Cloud operator must independently verify private storage, document counts and zero deliveries/jobs afterward."]}
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
            report["checks"]["owner_login"] = owner.login(fixture["owner"])
            report["checks"]["other_owner_login"] = other.login(fixture["other_owner"])
            response, page, _ = owner.request("GET", order_path, inertia=True)
            require(response["status"] == 200 and isinstance(page, dict), "Order identity page unavailable")
            props = page.get("props", {})
            actual = props.get("order", {})
            user = props.get("auth", {}).get("user", {})
            website = actual.get("website", {})
            require(actual.get("id") == fixture["order_id"] and actual.get("wp_order_id") == 900003
                    and actual.get("website_id") == fixture["website_id"] and actual.get("status") == "processing"
                    and actual.get("customer_email") == fixture["intended_customer_email"]
                    and actual.get("payload", {}).get("staging_http_upload_probe") == MARKER,
                    "Actual order is not the approved fictional HTTP fixture")
            require(user.get("email") == fixture["owner"]["email"] and not user.get("is_admin")
                    and website.get("user_id") == user.get("id") and website.get("status") == "paused"
                    and website.get("base_url") == "https://cloud-probe.example.invalid" and props.get("notesAvailable") is False,
                    "Actual fictional owner/paused credential-free website check failed")
            response, cross, _ = owner.request("GET", cross_path, inertia=True)
            cross = cross.get("props", {}).get("order", {}) if isinstance(cross, dict) else {}
            require(response["status"] == 200 and cross.get("wp_order_id") == 900001
                    and cross.get("website_id") == fixture["website_id"]
                    and cross.get("payload", {}).get("staging_probe") == "wphub-cloud-documents-v1", "Known same-owner cross-order required")
            report["checks"]["live_fixture_identity"] = True

            # Small invalid control proves session/CSRF/route validation independently of body size.
            body, content_type = multipart([], fixture["intended_customer_email"])
            response, data, _ = owner.request("POST", upload_path, body, content_type)
            control = classify(response, data)
            report["checks"]["empty_files_control"] = control
            require(response["status"] == 422 and "files" in control["validation_fields"], "Controller validation control failed; no large upload attempted")
            save()

            boundary, small, single_excess = pdf_bytes(MIB5), pdf_bytes(1024), pdf_bytes(MIB5 + 1)
            tests = [("aggregate_10_mib", [("Fictional HTTPS A.pdf", boundary), ("Fictional HTTPS B.pdf", boundary)]),
                     ("single_over_5_mib", [("Fictional oversized.pdf", single_excess)]),
                     ("aggregate_over_10_mib", [("Fictional A.pdf", boundary), ("Fictional B.pdf", boundary), ("Fictional excess.pdf", small)])]
            accepted = []
            for label, files in tests:
                body, content_type = multipart(files, fixture["intended_customer_email"])
                report["state"] = label + "_post_started_no_automatic_retry"
                report["checks"][label] = {"request_bytes": len(body), "file_bytes": [len(content) for _, content in files]}
                save()
                response, data, _ = owner.request("POST", upload_path, body, content_type)
                report["checks"][label].update(classify(response, data))
                if label == "aggregate_10_mib" and response["status"] == 200:
                    accepted = data.get("documents", []) if isinstance(data, dict) else []
                    require(len(accepted) == 2, "Successful upload returned unexpected document count")
                    for index, document in enumerate(accepted, 1):
                        require(isinstance(document, dict) and re.fullmatch(r"[a-f0-9-]{36}", document.get("id", ""))
                                and document.get("size") == MIB5 and document.get("sha256") == hashlib.sha256(boundary).hexdigest()
                                and document.get("approved") is True and document.get("version") == index,
                                "Uploaded PDF identity/hash/approval/version mismatch")
                    report["approved_documents"] = [{key: document[key] for key in ("id", "size", "sha256", "version", "approved")} for document in accepted]
                elif label != "aggregate_10_mib" and response["status"] == 200:
                    # Preserve only returned IDs before stopping; never delete an unexpected upload.
                    report["unexpected_document_ids"] = [row.get("id") for row in (data.get("documents", []) if isinstance(data, dict) else []) if isinstance(row, dict)]
                    save()
                    raise CheckFailure("Oversize request unexpectedly accepted; inspect fictional order without retry")
                save()

            reads = []
            for document in accepted:
                path = upload_path + "/" + document["id"]
                cross_read = cross_path + "/document-deliveries/documents/" + document["id"]
                public = f'/storage/order-documents/{fixture["website_id"]}/{fixture["order_id"]}/{document["id"]}.pdf'
                allowed.update({("GET", path), ("GET", cross_read), ("GET", public)})
                response, _, content = owner.request("GET", path, limit=MIB5 + 1024)
                require(response["status"] == 200 and hashlib.sha256(content).hexdigest() == document["sha256"]
                        and "private" in response["cache_control"] and "no-store" in response["cache_control"], "Owner PDF HTTPS read/hash/cache check failed")
                anon, _, _ = anonymous.request("GET", path)
                denied, _, _ = other.request("GET", path)
                cross_result, _, _ = owner.request("GET", cross_read)
                public_result, _, _ = anonymous.request("GET", public)
                require(anon["status"] == 401 and denied["status"] == 403 and cross_result["status"] == 404
                        and public_result["status"] in (403, 404), "Unauthorized HTTPS document access was not rejected")
                reads.append({"id": document["id"], "owner_sha256_matches": True, "owner_http": 200,
                              "anonymous_http": 401, "other_owner_http": 403, "cross_order_http": 404,
                              "guessed_public_http": public_result["status"], "cache_control": response["cache_control"]})
            report["checks"]["https_document_reads"] = reads or {"skipped": "Boundary upload was not accepted; no document IDs to read"}
            checks = report["checks"]
            report["ok"] = (len(accepted) == 2 and len(reads) == 2
                            and checks["single_over_5_mib"]["classification"] == "application_single_file_size_validation"
                            and checks["aggregate_over_10_mib"]["classification"] == "application_aggregate_size_validation")
            report["state"] = "passed" if report["ok"] else "failed_or_blocked_see_http_layer_classification"
        except Exception as error:
            report["state"] = "stopped_no_retry"
            report["error_class"] = type(error).__name__
            report["error"] = str(error) if isinstance(error, CheckFailure) else "Operation failed; raw exception omitted to protect session/credential data"
        finally:
            for label, session in (("owner", owner), ("other_owner", other)):
                if session.authenticated:
                    try:
                        response, _, _ = session.request("POST", "/logout", b"", "application/x-www-form-urlencoded")
                        report["checks"][label + "_logout_http"] = response["status"]
                    except Exception:
                        report["checks"][label + "_logout_http"] = "not confirmed; operator should invalidate fixture credentials"
            save()
    print(json.dumps({"ok": report["ok"], "state": report["state"], "evidence": str(output)}))
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--output", required=True)
    try:
        sys.exit(run(parser.parse_args()))
    except Exception as error:
        print(json.dumps({"ok": False, "stage": "local_input", "error_class": type(error).__name__,
                          "error": str(error) if isinstance(error, CheckFailure) else "Private input or output preparation failed; details omitted"}))
        sys.exit(1)
