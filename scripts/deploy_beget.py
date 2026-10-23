"""Upload public website files to the restricted FTP root; never delete remote files."""
import ftplib
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import ssl
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = '.elimora-deploy-manifest.json'
PUBLIC_EXTENSIONS = {'.html', '.css', '.js', '.mjs', '.json', '.xml', '.txt'}
ASSET_EXTENSIONS = {'.webp', '.png', '.jpg', '.jpeg', '.svg', '.gif', '.avif', '.ico', '.woff', '.woff2', '.ttf', '.otf', '.mp4', '.webm', '.pdf'}


def public_file(name):
    p = PurePosixPath(name)
    if p.is_absolute() or '..' in p.parts:
        return False
    if name in {'.htaccess', '.nojekyll'}:
        return True
    if any(part.startswith('.') for part in p.parts):
        return False
    if len(p.parts) == 1:
        return p.suffix.lower() in PUBLIC_EXTENSIONS
    return p.parts[0] == 'assets' and p.suffix.lower() in ASSET_EXTENSIONS


def deploy():
    values = {k: os.environ.get(k, '') for k in ('FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD')}
    if not all(values.values()):
        raise RuntimeError('Missing FTP_SERVER, FTP_USERNAME or FTP_PASSWORD repository secret')
    names = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
    files = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
             for name in names if name and public_file(name) and not (ROOT / name).is_symlink()}
    if not {'index.html', 'app.js', 'recommendation.mjs', '.htaccess'} <= files.keys():
        raise RuntimeError('Required website files missing; upload cancelled')
    # Certificate and hostname verification are mandatory. Never fall back to plain FTP.
    with ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=60) as ftp:
        print('Connecting to FTPS server', flush=True)
        ftp.connect(values['FTP_SERVER'], 21)
        print('Authenticating over TLS', flush=True)
        ftp.login(values['FTP_USERNAME'], values['FTP_PASSWORD'])
        print('Authentication successful; protecting data connection', flush=True)
        ftp.prot_p()
        ftp.cwd('/')  # This account is restricted to elimora.ru/public_html.
        old_buffer = io.BytesIO()
        try:
            ftp.retrbinary('RETR ' + MANIFEST, old_buffer.write)
        except ftplib.error_perm as exc:
            if not str(exc).startswith('550'):
                raise
            previous = {}
        else:
            try:
                previous = json.loads(old_buffer.getvalue())
                if not isinstance(previous, dict):
                    previous = {}
            except (ValueError, UnicodeError):
                previous = {}
        changed = [name for name, digest in files.items() if previous.get(name) != digest]
        # Publish assets and dependencies before pages that reference them.
        changed.sort(key=lambda n: (n.endswith('.html'), not n.startswith('assets/'), n))
        made = set()
        for name in changed:
            parent = PurePosixPath(name).parent
            for folder in reversed([parent, *parent.parents]):
                directory = str(folder)
                if directory == '.' or directory in made:
                    continue
                try:
                    ftp.mkd(directory)
                except ftplib.error_perm as exc:
                    if not str(exc).startswith('550'):
                        raise
                made.add(directory)
            temporary = name + '.deploy-tmp'
            with (ROOT / name).open('rb') as source:
                ftp.storbinary('STOR ' + temporary, source)
            ftp.rename(temporary, name)
            print('Updated:', name, flush=True)
        payload = json.dumps(files, sort_keys=True).encode()
        ftp.storbinary('STOR ' + MANIFEST + '.deploy-tmp', io.BytesIO(payload))
        ftp.rename(MANIFEST + '.deploy-tmp', MANIFEST)
        print(f'Deployment complete: {len(changed)} updated, {len(files) - len(changed)} unchanged. No remote files deleted.')


if __name__ == '__main__':
    try:
        deploy()
    except Exception as exc:
        message = str(exc)
        for key in ('FTP_PASSWORD', 'FTP_USERNAME', 'FTP_SERVER'):
            value = os.environ.get(key)
            if value:
                message = message.replace(value, '[redacted]')
        print(f'Deployment failed ({type(exc).__name__}): {message}', file=sys.stderr)
        sys.exit(1)
