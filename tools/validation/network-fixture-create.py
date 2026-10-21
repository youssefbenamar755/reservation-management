"""Create new private capture-only app/Woo fixture. Never reuses an existing DB."""
from pathlib import Path
import datetime, json, secrets, shutil, sys, zipfile
from cryptography import x509
from cryptography.x509.oid import NameOID
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa

repo = Path(__file__).resolve().parents[2]
parent = Path(sys.argv[1]).resolve()
source = parent / 'woo-integration'
wordpress_package = Path(sys.argv[2]).resolve() if len(sys.argv) > 2 else source/'wordpress-6.8.3.zip'
woocommerce_package = Path(sys.argv[3]).resolve() if len(sys.argv) > 3 else source/'woocommerce.10.3.0.zip'
for package in [wordpress_package, woocommerce_package]:
    if not package.is_file() or not package.is_relative_to(parent):
        raise RuntimeError('Only an existing private output package may seed a new fixture')
root = parent / ('wphub-network-' + secrets.token_hex(8))
root.mkdir()
(root/'FICTIONAL-ONLY').write_text('New isolated network fixture. Capture only. No production data.\n',encoding='utf-8')
with zipfile.ZipFile(wordpress_package) as archive:
    if any(Path(name).is_absolute() or '..' in Path(name).parts for name in archive.namelist()):
        raise RuntimeError('Unsafe WordPress archive member')
    archive.extractall(root)
with zipfile.ZipFile(woocommerce_package) as archive:
    if any(Path(name).is_absolute() or '..' in Path(name).parts for name in archive.namelist()):
        raise RuntimeError('Unsafe WooCommerce archive member')
    archive.extractall(root/'wordpress/wp-content/plugins')
# Optional official French catalogues are scoped to the explicitly selected new versions.
if wordpress_package.name == 'wordpress-7.1.3.zip' and woocommerce_package.name == 'woocommerce.11.2.1.zip':
    for filename, destination in [('wordpress-7.1.3-fr_FR.zip', 'wordpress/wp-content/languages'),
                                  ('woocommerce.11.2.1-fr_FR.zip', 'wordpress/wp-content/languages/plugins')]:
        translation = source / filename
        if translation.exists():
            with zipfile.ZipFile(translation) as archive:
                if any(Path(name).is_absolute() or '..' in Path(name).parts for name in archive.namelist()):
                    raise RuntimeError('Unsafe translation archive member')
                archive.extractall(root / destination)
(root/'wordpress/wp-content/mu-plugins').mkdir(exist_ok=True)
shutil.copyfile(repo/'integrations/wphub-documents/tests/real/isolation.php', root/'wordpress/wp-content/mu-plugins/isolation.php')
config = (source/'wordpress/wp-config.php').read_text(encoding='utf-8')
config = config.replace("PHP_SAPI !== 'cli'", "!in_array(PHP_SAPI, ['cli', 'cli-server'], true)")
config = config.replace("'http://127.0.0.1'", "'https://fixture.example.invalid'")
(root/'wordpress/wp-config.php').write_text(config,encoding='utf-8')
for path in ['app/cache', 'app/storage/framework/views', 'app/storage/framework/cache/data', 'app/storage/logs', 'app/storage/app/private']:
    (root/path).mkdir(parents=True, exist_ok=True)
(root/'app/fixture.sqlite').touch()
for filename in ['packages.php', 'services.php']:
    shutil.copyfile(repo/'bootstrap/cache'/filename, root/'app/cache'/filename)
private = {'format':'wphub-network-fixture-v1','key':'base64:'+__import__('base64').b64encode(secrets.token_bytes(32)).decode(),
    'origin':'https://fixture.example.invalid','recipient':'fixture-recipient@example.invalid','transport':'capture',
    'pairing':str(__import__('uuid').uuid4())}
(root/'private.json').write_text(json.dumps(private),encoding='utf-8')
now = datetime.datetime.now(datetime.timezone.utc)
key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'WP Hub fictional local test CA')])
ca = (x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key())
    .serial_number(x509.random_serial_number()).not_valid_before(now-datetime.timedelta(minutes=5)).not_valid_after(now+datetime.timedelta(days=7))
    .add_extension(x509.BasicConstraints(ca=True,path_length=0),critical=True).sign(key,hashes.SHA256()))
leaf_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
leaf = (x509.CertificateBuilder().subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'fixture.example.invalid')]))
    .issuer_name(name).public_key(leaf_key.public_key()).serial_number(x509.random_serial_number())
    .not_valid_before(now-datetime.timedelta(minutes=5)).not_valid_after(now+datetime.timedelta(days=7))
    .add_extension(x509.SubjectAlternativeName([x509.DNSName('fixture.example.invalid')]),critical=False)
    .add_extension(x509.BasicConstraints(ca=False,path_length=None),critical=True).sign(key,hashes.SHA256()))
(root/'ca.pem').write_bytes(ca.public_bytes(serialization.Encoding.PEM))
(root/'server.pem').write_bytes(leaf.public_bytes(serialization.Encoding.PEM)+ca.public_bytes(serialization.Encoding.PEM))
(root/'server-key.private.pem').write_bytes(leaf_key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
print(json.dumps({'fixture_path':str(root),'tls_host':'fixture.example.invalid','database_port':33177,'woo_http_port':8802,'tls_local_port':8443,'capture_only':True}))
