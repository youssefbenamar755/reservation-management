"""Validate a local smoke capture, without transmitting email or opening a mailbox."""
import hashlib
import io
import json
import sys
from email import policy
from email.parser import BytesParser
from pathlib import Path
from pypdf import PdfReader

source = Path(sys.argv[1])
report = json.loads(source.read_text(encoding="utf-8"))
message = BytesParser(policy=policy.default).parsebytes(Path(report["capture_path"]).read_bytes())
attachments = list(message.iter_attachments())
assert len(attachments) == 1
data = attachments[0].get_payload(decode=True)
digest = hashlib.sha256(data).hexdigest()
assert digest == report["pdf_sha256"]
assert data == Path(report["pdf_path"]).read_bytes()
pdf = PdfReader(io.BytesIO(data), strict=True)
assert len(pdf.pages) == 1
text = pdf.pages[0].extract_text()
assert "fictional" in text.lower() or "sample" in text.lower()
result = {"scope": "Local captured MIME only; not Gmail receipt", "attachments": 1,
          "bytes_match_approved_source": True, "sha256": digest, "strict_pdf_open": True,
          "pages": len(pdf.pages), "text": text, "passed": True}
source.with_name("captured-pdf-check.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
print(json.dumps(result, indent=2))
