"""Compare official client-exported fictional MIME with its saved reviewed source. No network."""
from pathlib import Path
from email.parser import BytesParser
from email.utils import getaddresses
from html.parser import HTMLParser
from pypdf import PdfReader
import email.policy,hashlib,io,json,logging,re,sys,unicodedata
root=Path(sys.argv[1]).resolve();language=sys.argv[2]
if language not in ['en','fr'] or not (root/'FICTIONAL-ONLY').is_file():raise ValueError('Known fictional fixture/language required')
received_path=root/('outlook-received-fr.eml' if language=='fr' else 'gmail-received-en.eml')
source_path=root/f'reviewed-{language}.eml'
record=json.loads((root/'gmail-bridge-prepared.json').read_text(encoding='utf-8'))[language]
expected_recipient={'en':'youssefbenamar755@gmail.com','fr':'benamar-y14@outlook.com'}[language]
source=BytesParser(policy=email.policy.default).parsebytes(source_path.read_bytes())
received=BytesParser(policy=email.policy.default).parsebytes(received_path.read_bytes())
checks=[]
def check(condition,label):
    checks.append({'check':label,'passed':bool(condition)})
    if not condition:raise AssertionError(label)
def normalize(text):return re.sub(r'\s+',' ',unicodedata.normalize('NFC',text).replace('\xa0',' ')).strip()
class VisibleText(HTMLParser):
    def __init__(self):super().__init__(convert_charrefs=True);self.parts=[];self.ignored=0
    def handle_starttag(self,tag,attrs):
        if tag in ['style','script','head']:self.ignored+=1
    def handle_endtag(self,tag):
        if tag in ['style','script','head'] and self.ignored:self.ignored-=1
    def handle_data(self,data):
        if not self.ignored:self.parts.append(data)
def semantic_html(message):
    part=message.get_body(preferencelist=('html',));parser=VisibleText();parser.feed(part.get_content());return normalize(' '.join(parser.parts))
check(record['recipient']==expected_recipient,'Saved preview uses the exact approved recipient')
check(not source.defects and not received.defects,'Both MIME messages parse without defects')
headers={}
for name in ['From','To','Reply-To','Subject','Message-ID']:
    check(len(source.get_all(name,[]))==len(received.get_all(name,[]))==1,f'Exactly one source and received {name} header')
    headers[name]={'source':str(source[name]),'received':str(received[name])}
    if name!='Message-ID':check(str(source[name])==str(received[name]),f'Received {name} exactly matches the reviewed source')
    else:check(bool(re.fullmatch(r'<[^<>\s]+@[^<>\s]+>',str(received[name]))),'Received Message-ID is present and well formed; normalization difference recorded')
check(getaddresses(received.get_all('To',[]))==[('',expected_recipient)],'Received message addressed only to the approved test recipient')
check(not any(received.get_all(name) for name in ['Cc','Bcc','Resent-To','Resent-Cc','Resent-Bcc']),'No CC/BCC or resent recipients in received MIME')
source_parts=list(source.iter_attachments());received_parts=list(received.iter_attachments())
source_pdf={part.get_filename():part for part in source_parts};received_pdf={part.get_filename():part for part in received_parts};expected={pdf['name']:pdf for pdf in record['pdfs']}
check(len(source_parts)==len(received_parts)==len(expected) and set(source_pdf)==set(received_pdf)==set(expected),'Exact received attachment count and filenames')
pdfs=[]
for name in expected:
    original=source_pdf[name].get_payload(decode=True);actual=received_pdf[name].get_payload(decode=True);digest=hashlib.sha256(actual).hexdigest()
    check(actual==original and digest==expected[name]['sha256'] and len(actual)==expected[name]['size'],f'{name}: received PDF bytes, approved SHA256 and size exactly match')
    check(received_pdf[name].get_content_type()=='application/pdf',f'{name}: received PDF media type')
    stream=io.StringIO();handler=logging.StreamHandler(stream);logger=logging.getLogger('pypdf');logger.addHandler(handler)
    try:reader=PdfReader(io.BytesIO(actual),strict=True);text='\n'.join(page.extract_text() or '' for page in reader.pages)
    finally:logger.removeHandler(handler)
    check(not reader.is_encrypted and len(reader.pages)==1 and not stream.getvalue(),f'{name}: received PDF strict parse with one page and no warnings')
    check('FICTIONAL WP Hub connected journey' in text and 'Not a reservation or travel document' in text,f'{name}: expected harmless fictional text')
    pdfs.append({'name':name,'bytes':len(actual),'sha256':digest,'strict_parse':True,'pages':len(reader.pages)})
check(source.get_body(preferencelist=('html',)) is not None and received.get_body(preferencelist=('html',)) is not None,'Both source and received HTML alternatives present')
source_html=semantic_html(source);received_html=semantic_html(received)
check(source_html==received_html,'Received HTML semantic text matches the reviewed source after whitespace/entity normalization')
plain_source=source.get_body(preferencelist=('plain',));plain_received=received.get_body(preferencelist=('plain',))
check(plain_source is not None and plain_received is not None,'Received plain-text alternative is present')
plain_exact=plain_source.get_content()==plain_received.get_content()
plain_semantic=normalize(plain_source.get_content())==normalize(plain_received.get_content())
disclaimer='FICTIONAL TEST: no real reservation or purchase.' if language=='en' else 'TEST FICTIF : aucune réservation, aucun achat réel.'
check(disclaimer in plain_received.get_content() and all(name in plain_received.get_content() for name in expected),'Received plain text preserves fictional disclaimer and attachment filenames')
plain_body_equal=normalize(plain_source.get_content().split(disclaimer,1)[1])==normalize(plain_received.get_content().split(disclaimer,1)[1])
check(plain_body_equal,'Received plain-text main body after fictional disclaimer matches after line wrapping normalization')
result={'status':'received_mime_integrity_verified','client':'Outlook web official MIME export' if language=='fr' else 'Gmail official raw MIME export',
    'language':language,'recipient':expected_recipient,'checks_passed':len(checks),'checks':checks,'headers':headers,'pdfs':pdfs,
    'source_mime_sha256':hashlib.sha256(source_path.read_bytes()).hexdigest(),'received_mime_sha256':hashlib.sha256(received_path.read_bytes()).hexdigest(),
    'transport_header_differences':{'message_id_equal':str(source['Message-ID'])==str(received['Message-ID']),'source_date':str(source['Date']),'received_date':str(received['Date']),
        'observation':'Received Message-ID/date differ from prepared MIME. Four identity/content headers matched exactly; do not claim all header bytes identical.'},
    'html_comparison':'Semantic text equal; client-export HTML byte formatting is not claimed identical','plain_text':{'bytes_equal':plain_exact,'normalized_text_equal':plain_semantic,'main_body_equal_after_line_wrapping_normalization':plain_body_equal,'disclaimer_and_filenames_preserved':True,
        'observed_formatting':'Received alternative adds HTML preheader/image alt text and plain-text list/emphasis markers; main body preserved.'},
    'scope':'Actual exported recipient message. Source-versus-received PDF byte equality and strict parse verified. No sends by audit.',
    'limits':['Gmail recipient confirmation remains separate when only Outlook has been exported','No native/mobile client or real professional-brand logo claim','Not a public-internet Woo/Cloud persistence test']}
release=root.parent/'email-documents-release'
(release/'connected-received-mime-integrity.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
print(json.dumps({'status':result['status'],'checks_passed':len(checks),'received_pdfs':len(pdfs),'plain_exact':plain_exact,'plain_normalized':plain_semantic,'html_semantic_equal':True}))
