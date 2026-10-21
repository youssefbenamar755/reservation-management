"""Read-only strict PDF/hash check for the fresh storage-worker report."""
import hashlib
import json
import sys
from pathlib import Path
from pypdf import PdfReader

evidence = Path(sys.argv[1]).resolve()
report = json.loads(evidence.read_text(encoding="utf-8"))
results = []
for doc in report["phases"]["backup"]["documents"]:
    for location, root in [("original", report["run_directory"]), ("restored", report["restored_directory"])]:
        pdf = Path(root) / "storage/app/private" / doc["path"]
        data = pdf.read_bytes()
        reader = PdfReader(pdf, strict=True)
        text = "\n".join(page.extract_text() or "" for page in reader.pages)
        assert len(data) == doc["size"]
        assert hashlib.sha256(data).hexdigest() == doc["sha256"]
        assert len(reader.pages) == 1
        assert "FICTIONAL local storage/restore sample" in text
        assert "Not a reservation or travel document." in text
        results.append({"document": doc["id"], "location": location, "pages": 1, "size": len(data), "sha256_matches": True, "fictional_text_verified": True})
output = {"result": "passed", "checked_pdf_instances": len(results), "checks": results, "visual_layout_verified_by_this_script": False}
(evidence.parent / "pdf-integrity.json").write_text(json.dumps(output, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"result": "passed", "pdf_instances": len(results)}))
