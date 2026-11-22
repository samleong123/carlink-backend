#!/usr/bin/env python3
"""Install pinned Swagger UI browser assets locally; do not send login tokens to a third-party script host."""
import hashlib
from pathlib import Path
import urllib.request

VERSION = '5.17.14'
FILES = {
    'swagger-ui.css': '40170f0ee859d17f92131ba707329a88a070e4f66874d11365e9a77d232f6117',
    'swagger-ui-bundle.js': 'c2e4a9ef08144839ff47c14202063ecfe4e59e70a4e7154a26bd50d880c88ba1',
    'LICENSE': 'cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30',
}
DEST = Path(__file__).resolve().parent.parent/'public/docs/assets'
DEST.mkdir(parents=True, exist_ok=True)
for name, expected in FILES.items():
    with urllib.request.urlopen(f'https://cdn.jsdelivr.net/npm/swagger-ui-dist@{VERSION}/{name}', timeout=60) as response:
        data = response.read()
    digest = hashlib.sha256(data).hexdigest()
    if expected is not None and digest != expected:
        raise SystemExit(f'Checksum mismatch for {name}; existing asset not overwritten.')
    (DEST/name).write_bytes(data)
    print(f'{name} sha256={digest}')
