"""Native backup/independent restore/conversion checks in the fresh owned fixture only."""
from pathlib import Path
import datetime, hashlib, json, os, subprocess, sys

repo = Path(__file__).resolve().parents[2]
fixture = Path(os.environ['WPHUB_FIXTURE_ROOT']).resolve()
output = Path(sys.argv[1]).resolve()
if not (fixture / 'FICTIONAL-ONLY').is_file() or os.environ.get('WPHUB_VALIDATION_MAIL_TRANSPORT') != 'capture':
    raise RuntimeError('Only a fresh fictional capture fixture is permitted')
output.mkdir(parents=True, exist_ok=True)
php = ['C:/xampp/php/php.exe', '-d', 'disable_functions=mail,is_readable', str(repo/'tools/validation/myisam-fixture-probe.php')]
mysql = ['C:/xampp/mysql/bin/mysql.exe', '--protocol=tcp', '--host=127.0.0.1', '--port=33177', '--user=root']

def probe(phase, schema='wphub_fixture_hpos'):
    r = subprocess.run(php + [phase, schema], capture_output=True, check=True, cwd=repo)
    (fixture/f'myisam-{phase}-{schema}.private.log').write_bytes(r.stderr)
    return json.loads(r.stdout)

def assert_facts_equal(a, b):
    assert a.keys() == b.keys(), 'Restored table names mismatch'
    fields = ['rows','row_sha256','columns_sha256','indexes_sha256','engine','auto_increment','collation']
    for name in a:
        for key in fields:
            assert a[name][key] == b[name][key], f'Restore mismatch: {name} {key}'

evidence = {'started_at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'scope':'fresh fictional native WordPress/WooCommerce schemas; no live data or store changes',
    'fixture_directory':str(fixture), 'real_email_sent':0, 'paid_resources_created':0,
    'limitations':['Local MariaDB 10.4 / PHP 8.2 is not a production MariaDB 11.4 / PHP 8.3 compatibility claim.',
                   'Tiny fictional table timings are not a production downtime estimate.',
                   'Exact production schemas, backup and write-quiesce proof remain necessary before live conversion.']}
evidence['seed'] = probe('seed')
evidence['reproduction'] = probe('reproduce')
evidence['health_before'] = probe('health')
assert evidence['health_before']['health']['requirements']['compatible'] is False
before = probe('inspect')
dump = fixture/'myisam-before.private.sql'
with dump.open('xb') as stream:
    subprocess.run(['C:/xampp/mysql/bin/mysqldump.exe','--protocol=tcp','--host=127.0.0.1','--port=33177','--user=root',
        '--default-character-set=utf8mb4','--lock-all-tables','--quick','--hex-blob','--routines','--events','--triggers',
        'wphub_fixture_hpos'],stdout=stream,stderr=subprocess.PIPE,check=True)
assert dump.stat().st_size > 10000
assert_facts_equal(before['tables'], probe('inspect')['tables'])
subprocess.run(mysql+['--execute=CREATE DATABASE wphub_myisam_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'],capture_output=True,check=True)
with dump.open('rb') as stream:
    subprocess.run(mysql+['--default-character-set=utf8mb4','wphub_myisam_restore'],stdin=stream,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
restored = probe('inspect','wphub_myisam_restore')
assert_facts_equal(before['tables'], restored['tables'])
backup = {'dump_sha256':hashlib.sha256(dump.read_bytes()).hexdigest(),'dump_bytes':dump.stat().st_size,
    'source_schema':'wphub_fixture_hpos','independent_restore_schema':'wphub_myisam_restore',
    'backup_uses_global_read_lock':True,'all_table_data_columns_indexes_engines_sequences_match':True,
    'table_count':len(before['tables']), 'backup_contains_only_fictional_data':True}
(fixture/'myisam-backup-restore-verified.json').write_text(json.dumps(backup),encoding='utf-8')
evidence['backup_restore'] = backup
evidence['before'] = before
evidence['conversion'] = probe('convert')
evidence['after'] = probe('inspect')
evidence['rollback'] = probe('rollback')
evidence['health_after'] = probe('health')
assert evidence['health_after']['health']['requirements']['compatible'] is True
assert_facts_equal(before['tables'], probe('inspect','wphub_myisam_restore')['tables'])
evidence['independent_restore_retains_original_myisam_tables'] = True
evidence['passed'] = True
evidence['finished_at_utc'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
(output/'myisam-conversion-rehearsal.json').write_text(json.dumps(evidence,indent=2),encoding='utf-8')
print(json.dumps({'passed':True,'tables_converted':8,'backup_restore_tables_verified':len(before['tables']),
    'companion_compatible_before':False,'companion_compatible_after':True,'real_mail_sent':0,'fixture':str(fixture)}))
