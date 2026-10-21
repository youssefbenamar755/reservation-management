"""Compare only official-UI exported test EMLs to the reviewed private matrix. No network or credentials."""
from pathlib import Path
from email import policy
from email.parser import BytesParser
from email.utils import getaddresses
from io import BytesIO
from html.parser import HTMLParser
import hashlib
import json
import re
import sys
import warnings
from pypdf import PdfReader

root = Path(sys.argv[1]).resolve()
received_dir = (root / 'received').resolve()
matrix = json.loads((root / 'brand-matrix.json').read_text(encoding='utf-8'))
review = json.loads((root / 'brand-pre-send-review.json').read_text(encoding='utf-8'))
if review['matrix_sha256'] != hashlib.sha256((root / 'brand-matrix.json').read_bytes()).hexdigest():
    raise RuntimeError('Reviewed matrix changed')
expected = {record['subject']: record for record in matrix['records']}
results = []
seen = set()
class BodyText(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.ignored = []
        self.parts = []

    def handle_starttag(self, tag, attrs):
        if tag in {'style', 'script', 'head'}:
            self.ignored.append(tag)

    def handle_endtag(self, tag):
        if self.ignored and self.ignored[-1] == tag:
            self.ignored.pop()

    def handle_data(self, data):
        if not self.ignored and data.strip():
            self.parts.append(data.strip())

def visible_html(value):
    parser = BodyText()
    parser.feed(value)
    parser.close()
    return re.sub(r'\s+', ' ', ' '.join(parser.parts)).strip()

for filename in sorted(received_dir.glob('*.eml')):
    received = BytesParser(policy=policy.default).parsebytes(filename.read_bytes())
    subject = str(received['Subject'])
    if subject not in expected:
        raise RuntimeError('The received directory contains a message outside the exact approved matrix')
    record = expected[subject]
    if record['id'] in seen:
        raise RuntimeError('Duplicate received export for a matrix ID; review which file is authoritative')
    seen.add(record['id'])
    source = BytesParser(policy=policy.default).parsebytes((root / record['mime_path']).read_bytes())
    checks = {}
    for name in ['From', 'To', 'Reply-To']:
        checks[name.lower()] = getaddresses(received.get_all(name, [])) == getaddresses(source.get_all(name, []))
    checks['subject'] = str(received['Subject']) == str(source['Subject'])
    checks['no_hidden_recipients'] = all(received.get(name) is None for name in ['Cc', 'Bcc', 'Resent-To', 'Resent-Cc', 'Resent-Bcc'])
    received_html = received.get_body(preferencelist=('html',))
    source_html = source.get_body(preferencelist=('html',))
    checks['html_visible_text_matches'] = bool(received_html and source_html and visible_html(received_html.get_content()) == visible_html(source_html.get_content()))
    attachments = list(received.iter_attachments())
    checks['attachment_count'] = len(attachments) == len(record['attachments'])
    files = {part.get_filename(): part for part in attachments}
    pdfs = []
    for document in record['attachments']:
        part = files.get(document['name'])
        data = part.get_payload(decode=True) if part else b''
        exact = bool(part and len(data) == document['size'] and hashlib.sha256(data).hexdigest() == document['sha256'])
        parsed = False
        if exact:
            with warnings.catch_warnings(record=True) as caught:
                warnings.simplefilter('always')
                pdf = PdfReader(BytesIO(data), strict=True)
                content = ''.join(page.extract_text() for page in pdf.pages)
                parsed = not caught and len(pdf.pages) == 1 and not pdf.is_encrypted and 'FICTIONAL EMAIL VALIDATION' in content and 'NOT VALID FOR TRAVEL' in content
        checks['pdf_' + document['name']] = exact and parsed
        pdfs.append({'name': document['name'], 'sha256': hashlib.sha256(data).hexdigest(), 'matches_selected': exact, 'strict_opened': parsed})
    results.append({'id': record['id'], 'source_website_id': record['source_website_id'], 'brand': record['brand'], 'language': record['language'],
                    'kind': record['kind'], 'recipient': record['recipient'], 'received_file': filename.name, 'passed': all(checks.values()), 'checks': checks,
                    'pdfs': pdfs, 'provider_message_id_preserved': str(received['Message-ID']) == str(source['Message-ID']),
                    'date_header_preserved': str(received['Date']) == str(source['Date']),
                    'plaintext_present': received.get_body(preferencelist=('plain',)) is not None})

report = {'status': 'passed' if len(results) == 20 and all(row['passed'] for row in results) else 'partial-or-review-required',
          'reviewed_matrix_sha256': review['matrix_sha256'], 'received_exports_checked': len(results), 'expected_records': 20,
          'records_missing': [row['id'] for row in matrix['records'] if row['id'] not in seen], 'results': results,
          'limits': ['Only files exported from the actual clients qualify; this script does not fetch mail.',
                     'Header/PDF byte checks are distinct from screenshot rendering, blocked-image and mobile verification.',
                     'Providers may replace Message-ID/Date; HTML comparison is visible text, not HTML byte equality.',
                     'Plaintext presence is recorded without claiming providers preserve its formatting.'], 'emails_sent': 0}
(root / 'brand-received-integrity.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
print(json.dumps({key: value for key, value in report.items() if key not in ['results', 'records_missing']}, indent=2))
