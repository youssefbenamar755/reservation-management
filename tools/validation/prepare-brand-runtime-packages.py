"""Fetch only the two officially verified releases into the private fixture package cache."""
from pathlib import Path
from urllib.request import urlopen
import hashlib
import json
import re
import sys
import zipfile

root = Path(__file__).resolve().parents[4] / 'outputs' / 'woo-integration'
report = []
for name, url, expected in [
    ('wordpress-7.1.3.zip', 'https://wordpress.org/wordpress-7.1.3.zip', '7.1.3'),
    ('woocommerce.11.2.1.zip', 'https://downloads.wordpress.org/plugin/woocommerce.11.2.1.zip', '11.2.1'),
]:
    package = root / name
    if not package.exists():
        with urlopen(url, timeout=30) as response:
            data = response.read()
        with package.open('xb') as target:
            target.write(data)
    data = package.read_bytes()
    with zipfile.ZipFile(package) as archive:
        source = archive.read('wordpress/wp-includes/version.php' if name.startswith('wordpress-') else 'woocommerce/woocommerce.php').decode('utf-8')
        version = re.search(r"\$wp_version\s*=\s*'([^']+)'", source).group(1) if name.startswith('wordpress-') else re.search(r'Version:\s*([^\r\n]+)', source).group(1).strip()
    if version != expected:
        raise RuntimeError('Downloaded embedded version does not match the exact official release')
    if name.startswith('wordpress-') and hashlib.sha1(data).hexdigest() != '13eb1d042b1ca39224e44906cd84fe203112210c':
        raise RuntimeError('WordPress official published SHA1 mismatch')
    report.append({'filename': name, 'official_url': url, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(), 'embedded_version': version})
(root / 'brand-runtime-packages.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
if '--with-french' in sys.argv:
    translations = []
    for component, api, filename in [
        ('core', 'https://api.wordpress.org/translations/core/1.0/?version=7.1.3', 'wordpress-7.1.3-fr_FR.zip'),
        ('woocommerce', 'https://api.wordpress.org/translations/plugins/1.0/?slug=woocommerce&version=11.2.1', 'woocommerce.11.2.1-fr_FR.zip'),
    ]:
        with urlopen(api, timeout=30) as response:
            metadata = json.load(response)
        matches = [row for row in metadata['translations'] if row.get('language') == 'fr_FR']
        if len(matches) != 1 or not matches[0].get('package', '').startswith('https://downloads.wordpress.org/translation/'):
            raise RuntimeError('Official French translation package metadata is unavailable')
        url = matches[0]['package']
        package = root / filename
        if not package.exists():
            with urlopen(url, timeout=30) as response:
                data = response.read()
            with package.open('xb') as target:
                target.write(data)
        data = package.read_bytes()
        with zipfile.ZipFile(package) as archive:
            if not any(name.endswith('fr_FR.mo') for name in archive.namelist()):
                raise RuntimeError('French MO catalogue missing')
        translations.append({'component': component, 'official_metadata_url': api, 'official_package_url': url,
                             'filename': filename, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
    (root / 'brand-french-packages.json').write_text(json.dumps(translations, indent=2), encoding='utf-8')
    report += translations
print(json.dumps(report, indent=2))
