#!/usr/bin/env python3
"""Generate a fresh private staging configuration; never replace existing secrets."""
import argparse
import base64
import json
import os
from pathlib import Path
import secrets
from urllib.parse import urlparse

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('directory', type=Path)
parser.add_argument('--url', required=True)
parser.add_argument('--revision', required=True)
parser.add_argument('--smtp-env', type=Path, required=True, help='Private raw NAME=value SMTP configuration')
args = parser.parse_args()
url = urlparse(args.url)
if url.scheme != 'https' or not url.hostname or url.username or url.password or url.path not in ('', '/') or url.query or url.fragment:
    parser.error('--url must be an HTTPS origin')
args.directory.mkdir(mode=0o700, parents=True, exist_ok=True)
if args.directory.stat().st_mode & 0o077:
    parser.error('use a private directory with mode 700')
if any((args.directory / name).exists() for name in ('staging.env', 'reviewers.json', 'smtp.env')):
    parser.error('configuration already exists; preserve its keys and passwords')
if not args.revision or any(c not in 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.-' for c in args.revision):
    parser.error('revision must be a valid Docker tag')
smtp = args.smtp_env.read_text()
allowed = {'MAIL_HOST', 'MAIL_PORT', 'MAIL_SCHEME', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'MAIL_EHLO_DOMAIN'}
entries = {}
for line in smtp.splitlines():
    if not line or line.startswith('#'):
        continue
    key, separator, value = line.partition('=')
    if not separator or key not in allowed or '\x00' in value:
        parser.error('SMTP file must contain only supported raw MAIL_* settings')
    entries[key] = value
if any(not entries.get(key) for key in ('MAIL_HOST', 'MAIL_PORT', 'MAIL_SCHEME', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS')):
    parser.error('SMTP configuration is incomplete')
values = {
    'STAGING_APP_IMAGE': f'weavdash-staging-app:{args.revision}',
    'STAGING_WEB_IMAGE': f'weavdash-staging-web:{args.revision}',
    'APP_URL': args.url.rstrip('/'),
    'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
    **{name: secrets.token_urlsafe(32) for name in ('DB_PASSWORD', 'MIGRATION_DB_PASSWORD', 'POSTGRES_PASSWORD')},
}
files = {
    'smtp.env': smtp,
    'staging.env': ''.join(f'{key}={value}\n' for key, value in values.items()),
    'reviewers.json': json.dumps({role: secrets.token_urlsafe(24) for role in ('partner', 'brand', 'professional')}) + '\n',
}
for name, content in files.items():
    with os.fdopen(os.open(args.directory / name, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600), 'w') as target:
        target.write(content)
print('Created private staging.env, reviewers.json and smtp.env. No credentials printed.')
