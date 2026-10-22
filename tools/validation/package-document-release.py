"""Package explicitly scoped reviewed source, never a working tree or its secrets."""
import hashlib
import json
from pathlib import Path
import sys
import zipfile

repo = Path(sys.argv[1]).resolve()
outputs = Path(sys.argv[2]).resolve()
release = outputs / 'email-documents-release'
paths = {entry['file'] for entry in json.loads((outputs / 'document-feature-files.json').read_text(encoding='utf-8-sig'))}
safety = json.loads((release / 'safety-validation.json').read_text(encoding='utf-8-sig'))
paths.update(safety['files'])
paths.update(safety['final_review']['additional_changed_files'])
paths.update(str(file.relative_to(repo)).replace('\\', '/') for folder in ['tools/validation', 'integrations/wphub-documents/tests/real']
             for file in (repo / folder).iterdir() if file.is_file() and file.suffix in {'.php', '.py', '.cjs', '.md', '.ps1'})

manifest = []
for relative in sorted(paths):
    source = (repo / relative).resolve()
    if not source.is_relative_to(repo) or not source.is_file():
        raise ValueError(f'Invalid manifest path: {relative}')
    if any(part in {'storage', 'vendor', 'node_modules', '.git', '.codex', '.aws'} for part in source.relative_to(repo).parts):
        raise ValueError(f'Unsafe package path: {relative}')
    if source.name.startswith('.env') and source.name != '.env.example':
        raise ValueError('Live environment files cannot enter this package.')
    manifest.append({'file': relative, 'sha256': hashlib.sha256(source.read_bytes()).hexdigest(), 'bytes': source.stat().st_size})
manifest_json = json.dumps(manifest, indent=2) + '\n'
(outputs / 'document-feature-files.json').write_text(manifest_json, encoding='utf-8')
(release / 'changed-files.json').write_text(manifest_json, encoding='utf-8')

notice = '''# Staging review package — no production deployment approval

Source snapshots scoped by changed-files.json, plus the validation report.
Remote-base reconciliation is complete for the reviewed staging branch
codex/email-documents-staging, tracked in draft pull request #42:
https://github.com/youssefbenamar755/reservation-management/pull/42
This snapshot is for staging review. It does not authorize merging or pushing
main, production deployment or migration, live companion installation,
activation or customer mail. Do not overlay the ZIP onto production; pushing
main can automatically deploy. Recheck the current remote base before any
later production release.

Read docs/email-documents-release-runbook.md and RELEASE-REPORT.md for the
current gate statuses, completed evidence, outstanding checks and approved
staging scope. A prepared script or packaged source is not a passed runtime
check, and staging approval does not authorize production activation.
The separate companion ZIP contains runtime files only. Never install tests/real
or validation fixture guards on a live store. No credentials or customer PDFs
are included. Obtain the report's specified later approvals before production work.
'''
review_zip = release / 'wphub-email-documents-review.zip'
with zipfile.ZipFile(review_zip, 'w', zipfile.ZIP_DEFLATED) as archive:
    archive.writestr('REVIEW-PACKAGE.md', notice)
    archive.writestr('changed-files.json', manifest_json)
    archive.write(release / 'RELEASE-REPORT.md', 'RELEASE-REPORT.md')
    for entry in manifest:
        archive.write(repo / entry['file'], 'source/' + entry['file'])

plugin_zip = outputs / 'wphub-documents-1.0.0.zip'
plugin_files = ['wphub-documents.php', 'class-wphub-documents.php', 'README.md']
with zipfile.ZipFile(plugin_zip, 'w', zipfile.ZIP_DEFLATED) as archive:
    for name in plugin_files:
        archive.write(repo / 'integrations/wphub-documents' / name, 'wphub-documents/' + name)
with zipfile.ZipFile(plugin_zip) as archive:
    assert sorted(archive.namelist()) == sorted('wphub-documents/' + name for name in plugin_files)
    assert archive.testzip() is None
with zipfile.ZipFile(review_zip) as archive:
    assert archive.testzip() is None
    for entry in manifest:
        assert hashlib.sha256(archive.read('source/' + entry['file'])).hexdigest() == entry['sha256']

artifacts = {'source_files': len(manifest), 'scope': 'staging review only; no main or production deployment approval', 'packages': [
    {'path': str(file), 'bytes': file.stat().st_size, 'sha256': hashlib.sha256(file.read_bytes()).hexdigest()}
    for file in [review_zip, plugin_zip]
]}
(release / 'packages.json').write_text(json.dumps(artifacts, indent=2) + '\n', encoding='utf-8')
print(json.dumps(artifacts, indent=2))
