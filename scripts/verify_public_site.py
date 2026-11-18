"""Fail publication if public pages differ or HTTP does not redirect to HTTPS."""
import hashlib
from pathlib import Path
import sys
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
ORIGIN = 'https://elimora.ru'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


def verify():
    opener = urllib.request.build_opener(NoRedirect)
    failures = []
    for route, filename in [('/', 'index.html'), ('/maps', 'maps.html'), ('/services', 'services.html')]:
        request = urllib.request.Request(ORIGIN + route, headers={'Cache-Control': 'no-cache'})
        with urllib.request.urlopen(request, timeout=30) as response:
            actual = response.read()
            if response.status != 200 or response.url != ORIGIN + route:
                failures.append(route + ': unexpected HTTPS response or redirect')
        if hashlib.sha256(actual).digest() != hashlib.sha256((ROOT / filename).read_bytes()).digest():
            failures.append(route + ': published HTML does not match this commit')
        try:
            with opener.open('http://elimora.ru' + route + '?check=https', timeout=30) as response:
                failures.append(route + ': HTTP returned ' + str(response.status) + ' without HTTPS redirect')
        except urllib.error.HTTPError as response:
            if response.code not in (301, 308) or response.headers.get('Location') != ORIGIN + route + '?check=https':
                failures.append(route + ': permanent HTTPS redirect must preserve path and query')
    if failures:
        raise RuntimeError('; '.join(failures))
    print('Verified published HTML and permanent HTTP-to-HTTPS redirects for all three pages.')


if __name__ == '__main__':
    try:
        verify()
    except Exception as exc:
        print('Public verification failed: ' + str(exc), file=sys.stderr)
        sys.exit(1)
