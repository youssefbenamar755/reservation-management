"""Independent post-send audit. Reads private SQLite without credentials; no network/mail."""
from pathlib import Path
import hashlib,json,sqlite3,sys
root=Path(sys.argv[1]).resolve()
if not (root/'FICTIONAL-ONLY').is_file():raise ValueError('Fictional fixture required')
load=lambda name:json.loads((root/name).read_text(encoding='utf-8'))
prepared=load('gmail-bridge-prepared.json');review=load('pre-send-review.json');store=load('gmail-bridge-store-evidence.json')
tls=[json.loads(x) for x in (root/'tls-requests.jsonl').read_text(encoding='utf-8').splitlines()]
requests=[json.loads(x) for x in (root/'store-requests.jsonl').read_text(encoding='utf-8').splitlines()]
expected={'en':'youssefbenamar755@gmail.com','fr':'benamar-y14@outlook.com'};checks=[];deliveries={}
def check(condition,label):
    checks.append({'check':label,'passed':bool(condition)})
    if not condition:raise AssertionError(label)
db=sqlite3.connect((root/'app/fixture.sqlite').as_uri()+'?mode=ro',uri=True);db.execute('PRAGMA query_only=ON');db.row_factory=sqlite3.Row
check(set(prepared)==set(expected) and set(store['orders'])==set(expected),'Exact two approved fictional orders in saved preview/store evidence')
check(review['status']=='pre_send_review_passed' and review['pdf_visual_review']['all_three_pages_inspected'],'Independent pre-send MIME/PDF review passed')
check(db.execute('SELECT COUNT(*) FROM order_email_deliveries').fetchone()[0]==2,'Exactly two workflow deliveries exist in new app database')
check(db.execute('SELECT COUNT(*) FROM wc_orders').fetchone()[0]==2,'Exactly two fictional imported orders exist in new app database')
check(db.execute('SELECT COUNT(*) FROM jobs').fetchone()[0]==0,'Actual dedicated queue is empty after completion')
check(db.execute('SELECT COUNT(*) FROM website_document_profiles WHERE workflow_enabled=1').fetchone()[0]==0 and store['workflow_enabled'] is False,'Fixture workflow disabled in app and actual store')
check(all(row['tls'] in ['TLSv1.2','TLSv1.3'] for row in tls),'All recorded actual network requests negotiated TLS1.2 or TLS1.3')
check(all(not row['fault'] for row in tls),'No response injection used in real Gmail journey')
for language,recipient in expected.items():
    record=prepared[language];remote=store['orders'][language]
    row=db.execute('SELECT id,status,sync_status,gmail_message_id,send_attempts,sync_attempts,sent_at,synced_at,document_set_version FROM order_email_deliveries WHERE id=?',(record['delivery_id'],)).fetchone()
    order=db.execute('SELECT customer_email,status,wp_order_id FROM wc_orders WHERE id=?',(record['order_id'],)).fetchone()
    check(row is not None and row['status']=='sent' and row['sync_status']=='completed',f'{language}: app independently records accepted Gmail send and completed sync')
    check(row['gmail_message_id'] and not row['gmail_message_id'].startswith('capture_'),f'{language}: actual provider message ID, not capture')
    check(row['send_attempts']==1 and row['sync_attempts']==1 and row['sent_at'] and row['synced_at'],f'{language}: one send and one successful sync attempt')
    check(order['customer_email']==recipient==remote['recipient'] and order['wp_order_id']==record['wp_order_id']==remote['wp_order_id'],f'{language}: exact approved customer and store order identity')
    check(order['status']==remote['status']=='completed',f'{language}: both actual store and WP Hub final order status completed')
    receipt=remote['receipt']
    check(receipt['receipt_id']==record['delivery_id'] and receipt['provider_message_id']==row['gmail_message_id'] and receipt['document_set_version']==row['document_set_version'] and receipt['complete'] is True,f'{language}: store persisted exact accepted Gmail/PDF receipt and completion marker')
    check(receipt['attachment_count']==len(record['pdfs']),f'{language}: store receipt attachment count exact')
    notes=remote['private_document_notes']
    check(len(notes)==1 and record['delivery_id'] in notes[0],f'{language}: exactly one private document receipt note')
    prefix=f"/wp-json/wc/v3/wphub-documents/orders/{record['wp_order_id']}"
    indices=[i for i,r in enumerate(tls) if r['path']==prefix+'/complete' and r['method']=='POST']
    check(len(indices)==1,f'{language}: exactly one actual completion request')
    index=indices[0];receipts=[r for r in tls[:index] if r['path']==prefix+'/receipt']
    check(len(receipts)==2 and receipts[0]['method']=='POST' and receipts[1]['method']=='GET' and all(r['status']==200 for r in receipts),f'{language}: receipt persisted and verified before completion')
    check(tls[index]['status']==200,f'{language}: actual store acknowledged successful completion')
    after=[r for r in tls[index+1:] if r['path']==f"/wp-json/wc/v3/orders/{record['wp_order_id']}" and r['method']=='GET']
    check(bool(after) and after[0]['status']==200,f'{language}: final actual order refresh after completion')
    complete=[r for r in requests if r['path']==prefix+'/complete']
    check(len(complete)==1 and complete[0]['captured_notifications']==0,f'{language}: no redundant Woo completion email')
    check(hashlib.sha256((root/f'reviewed-{language}.eml').read_bytes()).hexdigest()==review['messages'][language]['mime_sha256'],f'{language}: reviewed immutable source MIME unchanged')
    deliveries[language]={'recipient':recipient,'provider_message_id':row['gmail_message_id'],'delivery_id':row['id'],'status':row['status'],'sync_status':row['sync_status'],'send_attempts':row['send_attempts'],'sync_attempts':row['sync_attempts'],'pdfs':record['pdfs']}
db.close()
result={'status':'real_gmail_local_tls_journey_verified','checks_passed':len(checks),'checks':checks,'deliveries':deliveries,'network_requests':len(tls),
    'store':{'wordpress':store['wordpress'],'woocommerce':store['woocommerce'],'hpos':store['hpos'],'compatibility_sync':store['compatibility_sync']},
    'real_document_emails_accepted':2,'store_acknowledgements':'captured only','inbox_receipt_verified':False,'received_pdf_hashes_verified':False,
    'scope':'Actual Laravel services/DB worker and local TLS WooREST plus real Gmail provider acceptance. Recipient mailbox and Cloud checks separate.',
    'donor_fixture':'Query-only credential read; no measured before/after hash baseline.'}
(root/'post-send-audit.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
print(json.dumps({'status':result['status'],'checks_passed':len(checks),'real_document_emails_accepted':2,'network_requests':len(tls)}))
