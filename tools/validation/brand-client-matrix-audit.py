"""Read-only MIME/PDF review, plus safe review records. Never sends or accesses credentials."""
from pathlib import Path
from email import policy
from email.parser import BytesParser
from email.utils import getaddresses
from io import BytesIO
import hashlib
import json
import sys
import warnings
from pypdf import PdfReader

root = Path(sys.argv[1]).resolve()
matrix_path = root / 'brand-matrix.json'
matrix = json.loads(matrix_path.read_text(encoding='utf-8'))
profiles = json.loads((root / 'brand-profiles.json').read_text(encoding='utf-8'))
allowed = ['youssefbenamar755@gmail.com', 'benamar-y14@outlook.com']
expected = {8: 'contact@reservationpourvisa.com', 9: 'contact@pre-reservation.fr', 10: 'contact@reservationvisa.com',
            11: 'contact@dummytickets.flights', 12: 'support@confirmedtkt.com'}
frozen_profiles = {entry['source_website_id']: entry['profile'] for entry in profiles['profiles']}
checks = []
def check(label, value):
    if not value:
        raise RuntimeError('Pre-send review failed: ' + label)
    checks.append(label)

check('fixed format and allowlist', matrix['format'] == 'wphub-brand-client-matrix-v1' and matrix['recipients'] == allowed)
check('frozen profile hash', matrix['profile_sha256'] == hashlib.sha256((root / 'brand-profiles.json').read_bytes()).hexdigest())
check('exact twenty records', len(matrix['records']) == 20 and len({r['id'] for r in matrix['records']}) == 20)
check('complete brand-language-kind matrix', {(r['source_website_id'], r['language'], r['kind']) for r in matrix['records']} ==
      {(site, language, kind) for site in expected for language in ['en', 'fr'] for kind in ['acknowledgement', 'delivery']})
summary = []
pdf_instances = 0
for record in matrix['records']:
    label = f"{record['source_website_id']}/{record['language']}/{record['kind']}"
    profile = frozen_profiles[record['source_website_id']]
    check(label + ' exact same-brand frozen profile', record['brand'] == profile['brand_name'] and
          all(record[key] == profile[key] for key in ['logo_url', 'sender_name', 'sender_email', 'reply_to']))
    path = (root / record['mime_path']).resolve()
    check(label + ' private fixed MIME path', path == root / 'brand-mail' / (record['id'] + '.eml'))
    raw = path.read_bytes()
    message = BytesParser(policy=policy.default).parsebytes(raw)
    check(label + ' exact immutable MIME hash', hashlib.sha256(raw).hexdigest() == record['mime_sha256'])
    check(label + ' message identity', str(message['Message-ID']) == f"<{record['id']}@wphub-validation.invalid>")
    check(label + ' one approved recipient', getaddresses(message.get_all('To', [])) == [('', allowed[0 if record['language'] == 'en' else 1])])
    check(label + ' From identity', getaddresses(message.get_all('From', [])) == [(record['sender_name'], expected[record['source_website_id']])])
    check(label + ' Reply-To', getaddresses(message.get_all('Reply-To', [])) == [('', record['reply_to'])])
    check(label + ' no hidden recipients', all(message.get(name) is None for name in ['Cc', 'Bcc', 'Resent-To', 'Resent-Cc', 'Resent-Bcc']))
    check(label + ' fictional subject', str(message['Subject']) == record['subject'] and record['subject'].startswith('[TEST '))
    html = message.get_body(preferencelist=('html',)).get_content()
    text = message.get_body(preferencelist=('plain',)).get_content()
    # SMTP MIME canonicalizes line endings; compare normalized text with saved reviewed source.
    normalized = lambda value: value.replace('\r\n', '\n').rstrip('\n')
    expected_html = path.with_suffix('.html').read_text(encoding='utf-8')
    expected_text = path.with_suffix('.txt').read_text(encoding='utf-8')
    check(label + ' exact semantic HTML and text source', normalized(html) == normalized(expected_html) and normalized(text) == normalized(expected_text))
    check(label + ' valid decoded Unicode', '\ufffd' not in html and '\ufffd' not in text and '\ufffd' not in record['subject'])
    check(label + ' same-brand logo reference', record['logo_url'] in html)
    check(label + ' brand survives images blocked', record['brand'] in text)
    check(label + ' long fictional name exercised', 'Morgan-Van-Der-Linden' in text)
    check(label + ' installation sender not leaked into content', 'fixture-sender@example.invalid' not in html and 'fixture-sender@example.invalid' not in text)
    if record['language'] == 'fr' and record['kind'] == 'acknowledgement':
        check(label + ' localized additional content', 'Thanks again!' not in text and 'Merci pour votre confiance.' in text)
    attachments = list(message.iter_attachments())
    wanted_count = (1 if record['language'] == 'en' else 2) if record['kind'] == 'delivery' else 0
    check(label + ' attachment count', len(attachments) == len(record['attachments']) == wanted_count)
    for part, expected_pdf in zip(attachments, record['attachments']):
        data = part.get_payload(decode=True)
        check(label + ' attachment identity/hash ' + expected_pdf['name'], part.get_filename() == expected_pdf['name']
              and len(data) == expected_pdf['size'] and hashlib.sha256(data).hexdigest() == expected_pdf['sha256']
              and data == (root / 'brand-mail' / expected_pdf['name']).read_bytes())
        with warnings.catch_warnings(record=True) as caught:
            warnings.simplefilter('always')
            pdf = PdfReader(BytesIO(data), strict=True)
            page_text = ''.join(page.extract_text() for page in pdf.pages)
            check(label + ' strict harmless PDF ' + expected_pdf['name'], not caught and not pdf.is_encrypted and len(pdf.pages) == 1
                  and 'FICTIONAL EMAIL VALIDATION' in page_text and 'NOT VALID FOR TRAVEL' in page_text)
        pdf_instances += 1
    summary.append({key: record[key] for key in ['id', 'source_website_id', 'brand', 'kind', 'language', 'recipient', 'sender_email', 'reply_to', 'logo_url', 'subject']}
                   | {'attachment_count': wanted_count, 'mime_sha256': record['mime_sha256']})
report = {'status': 'passed', 'matrix_sha256': hashlib.sha256(matrix_path.read_bytes()).hexdigest(), 'checks_passed': len(checks),
          'records': 20, 'strict_pdf_instances': pdf_instances, 'emails_sent_by_audit': 0, 'operator_logo_and_manifest_approval': 'pending separate brand-send-approval.json',
          'client_verification': {'Gmail': 'pending received evidence', 'Outlook': 'pending received evidence', 'mobile': 'pending actual client views', 'images_blocked': 'pending actual client views'},
          'acknowledgement_scope': 'Actual Woo paid-processing capture bridged as MIME through Gmail; stock SMTP is not tested.',
          'acknowledgement_runtime': matrix['acknowledgement_runtime'], 'acknowledgement_hpos': matrix['acknowledgement_hpos'],
          'acknowledgement_email_improvements': matrix['acknowledgement_email_improvements'],
          'matrix': summary, 'checks': checks}
(root / 'brand-pre-send-review.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
print(json.dumps({key: value for key, value in report.items() if key not in ['checks', 'matrix']}, indent=2))
