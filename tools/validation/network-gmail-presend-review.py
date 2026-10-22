"""Read-only review of saved fictional MIME/PDFs. Never connects or sends mail."""
from pathlib import Path
from email.parser import BytesParser
from email.utils import getaddresses
import email.policy, hashlib, io, json, logging, re, sys
from html.parser import HTMLParser
from pypdf import PdfReader

root=Path(sys.argv[1]).resolve()
if not (root/'FICTIONAL-ONLY').is_file():raise ValueError('Fictional fixture required')
prepared=json.loads((root/'gmail-bridge-prepared.json').read_text(encoding='utf-8'))
expected={'en':'youssefbenamar755@gmail.com','fr':'benamar-y14@outlook.com'}
checks=[]; messages={}
def check(value,label):
    checks.append({'check':label,'passed':bool(value)})
    if not value:raise AssertionError(label)
class TextOnly(HTMLParser):
    def __init__(self):super().__init__();self.parts=[];self.tags=[];self.links=[]
    def handle_data(self,data):self.parts.append(data)
    def handle_starttag(self,tag,attrs):
        self.tags.append(tag)
        self.links.extend(value for key,value in attrs if key in ['src','href'])
check(set(prepared)==set(expected),'Exactly the two approved language/recipient previews exist')
for language,recipient in expected.items():
    record=prepared[language]
    raw=(root/f'reviewed-{language}.eml').read_bytes()
    message=BytesParser(policy=email.policy.default).parsebytes(raw)
    check(not message.defects,f'{language}: MIME parser reports no defects')
    for header in ['From','To','Reply-To','Subject','Message-ID']:
        check(len(message.get_all(header,[]))==1,f'{language}: exactly one {header} header')
    check(getaddresses(message.get_all('To',[]))==[('',recipient)] and record['recipient']==recipient,f'{language}: exact approved recipient')
    check(not any(message.get_all(h) for h in ['Cc','Bcc','Resent-To','Resent-Cc','Resent-Bcc']),f'{language}: no additional recipients')
    check(getaddresses(message.get_all('From',[]))==[('FICTIONAL WP Hub connected test','visabookinghelp@gmail.com')],f'{language}: exact approved sender identity')
    check(getaddresses(message.get_all('Reply-To',[]))==[('','visabookinghelp@gmail.com')],f'{language}: exact Reply-To')
    check(str(message['Subject'])==record['subject'] and str(message['Subject']).startswith('[TEST] FICTIONAL'),f'{language}: reviewed subject exact and explicitly fictional')
    check(str(message['Message-ID'])==f"<{record['delivery_id']}@app.example.invalid>",f'{language}: Message-ID matches saved immutable delivery')
    plain=message.get_body(preferencelist=('plain',)); html=message.get_body(preferencelist=('html',))
    check(plain is not None and html is not None,f'{language}: plain and HTML alternatives present')
    text=plain.get_content();markup=html.get_content();parser=TextOnly();parser.feed(markup)
    visible=' '.join(parser.parts)
    disclaimer='FICTIONAL TEST: no real reservation or purchase.' if language=='en' else 'TEST FICTIF : aucune réservation, aucun achat réel.'
    attachment_instruction='attached to this email' if language=='en' else 'joints à cet e-mail'
    check(disclaimer in text and disclaimer in visible,f'{language}: explicit fictional disclaimer in both alternatives')
    check(attachment_instruction in text and attachment_instruction in visible,f'{language}: PDF attachment instructions in both alternatives')
    check('\ufffd' not in text+markup+str(message['Subject']),f'{language}: decoded Unicode contains no replacement characters')
    check(not any(tag in parser.tags for tag in ['script','form','iframe','button']),f'{language}: no scripts, forms, frames or fake download buttons')
    check(all(url.startswith(('https://fixture.example.invalid','mailto:support@example.invalid')) for url in parser.links),f'{language}: only expected fictional brand/support URLs')
    pdf_records={pdf['name']:pdf for pdf in record['pdfs']};attachments=list(message.iter_attachments())
    check(len(attachments)==(1 if language=='en' else 2) and {part.get_filename() for part in attachments}==set(pdf_records),f'{language}: exact expected PDF filenames and count')
    pdf_results=[]
    for part in attachments:
        name=part.get_filename();pdf_record=pdf_records[name];data=part.get_payload(decode=True)
        check(part.get_content_type()=='application/pdf' and part.get_content_disposition()=='attachment',f'{language}/{name}: attachment disposition and PDF media type')
        check(len(data)==pdf_record['size'] and hashlib.sha256(data).hexdigest()==pdf_record['sha256'],f'{language}/{name}: decoded bytes match approved size and SHA256')
        stream=io.StringIO();handler=logging.StreamHandler(stream);logger=logging.getLogger('pypdf');logger.addHandler(handler)
        try:
            reader=PdfReader(io.BytesIO(data),strict=True)
            extracted='\n'.join(page.extract_text() or '' for page in reader.pages)
        finally:logger.removeHandler(handler)
        check(not reader.is_encrypted and len(reader.pages)==1 and not stream.getvalue(),f'{language}/{name}: strict parse, one page, no encryption or parser warnings')
        check('FICTIONAL WP Hub connected journey' in extracted and 'Not a reservation or travel document' in extracted,f'{language}/{name}: harmless fictional PDF content')
        check(not any(key in reader.trailer['/Root'] for key in ['/OpenAction','/AA']),f'{language}/{name}: no automatic PDF actions')
        check(name in text and name in visible,f'{language}/{name}: filename present in both message alternatives')
        pdf_results.append({'name':name,'sha256':pdf_record['sha256'],'bytes':len(data),'pages':len(reader.pages),'strict_parse':True,'text':extracted})
    messages[language]={'recipient':recipient,'sender':'visabookinghelp@gmail.com','reply_to':'visabookinghelp@gmail.com','subject':record['subject'],
        'mime_sha256':hashlib.sha256(raw).hexdigest(),'plain_sha256':hashlib.sha256(text.encode()).hexdigest(),'html_sha256':hashlib.sha256(markup.encode()).hexdigest(),'pdfs':pdf_results}
acks=json.loads((root/'gmail-bridge-store-acks.json').read_text(encoding='utf-8'))
branded=[mail for mail in acks if mail['subject'].startswith('[TEST] FICTIONAL connected store journey')]
check(len(branded)==2,'Exactly two branded real-store acknowledgements captured')
check(sorted(mail['to'] for mail in branded)==sorted(expected.values()),'Captured customer acknowledgement recipients match the two approved orders')
check(all(not mail['attachments'] for mail in acks),'No premature PDFs in captured customer or admin store notifications')
check(all(mail['to'] in expected.values() for mail in acks),'All captured store notification recipients belong to the exact approved two-address list')
check(any('Your documents are not attached yet.' in mail['message'] for mail in branded) and any('Il ne contient pas encore vos documents.' in mail['message'] for mail in branded),'Real store acknowledgements use English/French configured copy and explain later document delivery')
report={'status':'pre_send_review_passed','checks_passed':len(checks),'checks':checks,'messages':messages,
    'captured_store_acknowledgements':len(branded),'captured_store_admin_notifications':len(acks)-len(branded),'real_emails_sent_by_review':0,
    'source_fixture_scope':'Donor8799 read via query-only connection; no before/after filesystem hash baseline was measured',
    'limitations':['This reviews saved source MIME, not inbox receipt','PDF structure/text/hash checks do not independently establish visual layout','Fictional brand image URL intentionally unresolved; not real logo evidence',
        'French custom acknowledgement subject/heading/body is translated; the uncustomized Woo fixture wrapper/order table remains English. Actual store language/template parity remains separate.']}
(root/'pre-send-review.json').write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
print(json.dumps({'status':report['status'],'checks_passed':len(checks),'pdf_instances':sum(len(x['pdfs']) for x in messages.values()),'real_emails_sent':0}))
