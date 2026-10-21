"""Read local MIME/PDF drafts; no network or sending support."""

import hashlib
import io
import json
from email import policy
from email.parser import BytesParser
from pathlib import Path
import re
import sys

from pypdf import PdfReader


def luminance(color):
    channels = [int(color[i:i + 2], 16) / 255 for i in (1, 3, 5)]
    linear = [channel / 12.92 if channel <= 0.04045 else ((channel + 0.055) / 1.055) ** 2.4 for channel in channels]
    return sum(channel * weight for channel, weight in zip(linear, (0.2126, 0.7152, 0.0722)))


def contrast(first, second):
    light, dark = sorted((luminance(first), luminance(second)), reverse=True)
    return round((light + 0.05) / (dark + 0.05), 2)


root = Path(sys.argv[1]).resolve()
manifest = json.loads((root / "manifest.json").read_text(encoding="utf-8"))
result = {
    "scope": "Local exported MIME and fictional PDF validation only. No Gmail/Outlook receipt or WooCommerce test.",
    "software": {"python": sys.version.split()[0], "pypdf": __import__("pypdf").__version__},
    "checks": [],
    "colour_diagnostics": [],
}
sample_total = attachment_total = 0
pdfs = set()
for site in manifest["websites"]:
    identity = site["same_site_registry_identity"]
    result["colour_diagnostics"].append({
        "website_id": site["website_id"],
        "accent_colour": identity["accent_color"],
        "accent_on_white_contrast": contrast(identity["accent_color"], "#ffffff"),
        "accent_suitable_for_normal_text": contrast(identity["accent_color"], "#ffffff") >= 4.5,
        "actual_footer_link_colour": site["diagnostic_values_not_configuration"]["text_color"],
        "actual_footer_link_on_white_contrast": contrast(site["diagnostic_values_not_configuration"]["text_color"], "#ffffff"),
        "note": "Observed legacy accent remains decorative. Fixed renderer uses configured text colour and underline for footer links. Diagnostic neutral colours do not prove production brand readiness.",
    })
    for sample in site["samples"]:
        mime_bytes = (root / sample["mime"]).read_bytes()
        assert hashlib.sha256(mime_bytes).hexdigest() == sample["mime_sha256"]
        message = BytesParser(policy=policy.default).parsebytes(mime_bytes)
        assert not message.defects
        assert message["To"].addresses[0].addr_spec == "fixture@example.invalid"
        assert message["From"].addresses[0].addr_spec == sample["from"]
        assert message["Reply-To"].addresses[0].addr_spec == sample["reply_to"]
        assert message["Cc"] is None and message["Bcc"] is None
        assert message["X-WPHub-Preview-Status"] == "unapproved-local-fixture-not-sent"
        assert str(message["Subject"]) == sample["subject"]
        assert identity["brand_name"] in str(message["Subject"])
        text = message.get_body(preferencelist=("plain",)).get_content()
        html = message.get_body(preferencelist=("html",)).get_content()
        assert "fixture@example.invalid" in text if not identity["sender"] else True
        assert "BROUILLON NON APPROUVÉ" in text or "UNAPPROVED DRAFT" in text
        assert "TYPE DE SERVICE À CONFIGURER" in text or "SERVICE LABEL REQUIRED" in text or sample["kind"] == "acknowledgement"
        assert "Alexandria Éléonore Morgan-Van-Der-Linden de la Fontaine" in text
        assert "download" not in " ".join(re.findall(r"<a[^>]*>(.*?)</a>", html, flags=re.S)).lower()
        assert re.findall(r'<img[^>]*src="([^"]+)"', html) == [identity["logo_url"]]
        links = re.findall(r'<a[^>]*style="([^"]+)"', html)
        assert links
        for style in links:
            assert "text-decoration:underline" in style
            link_colour = re.search(r"(?:^|;)color:(#[0-9a-fA-F]{6})", style).group(1)
            assert contrast(link_colour, "#ffffff") >= 4.5
        blocked = (root / sample["images_blocked_html"]).read_text(encoding="utf-8")
        assert "<img" not in blocked.lower()
        assert identity["brand_name"] in blocked
        attachments = list(message.iter_attachments())
        assert len(attachments) == len(sample["attachments"])
        for part, expected in zip(attachments, sample["attachments"]):
            assert part.get_content_type() == "application/pdf"
            assert part.get_filename() == expected["name"]
            pdf_bytes = part.get_payload(decode=True)
            assert len(pdf_bytes) == expected["size"]
            assert hashlib.sha256(pdf_bytes).hexdigest() == expected["sha256"]
            path = root / f'website-{site["website_id"]}' / expected["name"]
            assert path.read_bytes() == pdf_bytes
            reader = PdfReader(io.BytesIO(pdf_bytes), strict=True)
            assert not reader.is_encrypted and len(reader.pages) == 1
            extracted = reader.pages[0].extract_text()
            assert "NOT VALID FOR TRAVEL" in extracted
            assert "FICTIONAL VALIDATION DOCUMENT" in extracted
            assert f'FICTIONAL-{site["website_id"]}-1042' in extracted
            assert "Alexandria" in extracted and "Fontaine" in extracted
            pdfs.add(str(path))
            attachment_total += 1
        sample_total += 1
        result["checks"].append({"mime": sample["mime"], "result": "passed", "attachment_count": len(attachments),
            "verified": ["same-site sender/reply/logo/brand", "fictional-recipient-only", "draft warning", "long customer name",
                "multipart HTML/plain text", "images-blocked readable identity", "attachment filename/size/SHA256 exact bytes",
                "strict PDF parse and single-page text extraction"]})
result["totals"] = {"mime_exports_passed": sample_total, "attachment_instances_verified": attachment_total, "unique_pdfs_opened": len(pdfs)}
(root / "verification.json").write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
print(json.dumps(result["totals"]))
