"""Read-only evidence audit; never accesses private credentials or network."""
import email.policy, hashlib, json, sys
from email.parser import BytesParser
from pathlib import Path
root=Path(sys.argv[1]).resolve()
if not (root/'FICTIONAL-ONLY').is_file(): raise ValueError('Fictional fixture required')
journey=json.loads((root/'journey-evidence.json').read_text(encoding='utf-8'))
store=json.loads((root/'store-evidence.json').read_text(encoding='utf-8'))
tls=[json.loads(line) for line in (root/'tls-requests.jsonl').read_text(encoding='utf-8').splitlines()]
requests=[json.loads(line) for line in (root/'store-requests.jsonl').read_text(encoding='utf-8').splitlines()]
checks=[]
def check(condition, label):
    checks.append({'check':label,'passed':bool(condition)})
    if not condition: raise AssertionError(label)
check(all(row['tls'] in ['TLSv1.2','TLSv1.3'] for row in tls),'Every observed real gateway request used TLS1.2 or TLS1.3')
check(sum(row['fault'] for row in tls)==1,'Exactly one completion response was lost after actual upstream HTTP200')
for label in ['normal_en','lost_response_fr']:
    order=store['orders'][label]
    check(order['status']=='completed',f'Persisted Woo final state is completed: {label}')
    check(sum('WP Hub documents sent.' in note for note in order['notes'])==1,f'Exactly one private document receipt note: {label}')
    prefix=f"/wp-json/wc/v3/wphub-documents/orders/{order['id']}"
    complete=[i for i,row in enumerate(tls) if row['path']==prefix+'/complete' and row['method']=='POST']
    check(bool(complete),f'Actual network completion observed: {label}')
    for index in complete:
        before=tls[:index]
        receipts=[row for row in before if row['path']==prefix+'/receipt']
        check(len(receipts)>=2 and receipts[-2]['method']=='POST' and receipts[-1]['method']=='GET' and all(row['status']==200 for row in receipts[-2:]),f'Receipt persistence and successful read-back precede completion request at index{index}')
    completion_requests=[row for row in requests if row['path']==prefix+'/complete']
    check(bool(completion_requests) and all(row['captured_notifications']==0 for row in completion_requests),f'No redundant store completion email across actual requests: {label}')
check(store['orders']['stale_preview_en']['status']=='processing','Stale preview leaves actual Woo order processing')
captures=list((root/'app/storage').rglob('capture_*.eml'))
check(len(captures)==2,'Two actual private MIME captures exist')
for filename in captures:
    message=BytesParser(policy=email.policy.default).parsebytes(filename.read_bytes())
    check(str(message['To'])=='fixture-recipient@example.invalid' and not message['Cc'] and not message['Bcc'],'Captured MIME exact fictional recipient and no hidden recipients')
    attachments=list(message.iter_attachments())
    check(len(attachments)==1,'Captured MIME contains one approved fictional PDF')
    check(hashlib.sha256(attachments[0].get_payload(decode=True)).hexdigest()==journey['pdf_sha256'],'Decoded captured PDF bytes match approved private upload hash')
    check(message.get_body(preferencelist=('plain',)) is not None and message.get_body(preferencelist=('html',)) is not None,'Captured MIME has plain text and HTML alternatives')
acknowledgements=json.loads((root/'acknowledgements.json').read_text(encoding='utf-8'))
branded=[mail for mail in acknowledgements if 'FICTIONAL Network Brand' in mail['subject']]
check(len(branded)==3,'Three real-store paid-order acknowledgements captured for three fictional orders')
check(all(not mail['attachments'] for mail in branded),'No premature PDFs in captured store acknowledgements')
report={'status':'local_network_evidence_audited','checks':checks,'network_requests':len(tls),'store_requests':len(requests),'real_emails_sent':0,
        'evidence_scope':'Actual local TLS and Woo REST; mail capture. Not public HTTPS or hosted persistence.'}
(root/'audit-evidence.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
print(json.dumps({'status':report['status'],'checks_passed':len(checks),'network_requests':len(tls),'real_emails_sent':0}))
