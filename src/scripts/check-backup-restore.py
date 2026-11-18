#!/usr/bin/env python3
"""Rehearse encrypted database/document/key recovery on two disposable, internal-only databases."""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tarfile
import tempfile
import time


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', required=True)
    parser.add_argument('--image', default='weavdash-app:latest')
    args = parser.parse_args()
    if not re.fullmatch(r'weavdash-m6-restore-[a-z0-9]+', args.project):
        parser.error('Use a new weavdash-m6-restore-* project.')
    for program in ['docker', 'gpg']:
        if not shutil.which(program):
            parser.error('Required program missing: ' + program)
    root = Path(__file__).resolve().parents[2]
    for resource in [['ps', '-a'], ['volume', 'ls'], ['network', 'ls']]:
        found = subprocess.check_output(['docker', *resource, '-q', '--filter',
                                         'label=com.docker.compose.project=' + args.project], text=True)
        if found.strip():
            raise RuntimeError('Refusing an existing Compose project.')
    started = time.monotonic()
    with tempfile.TemporaryDirectory(prefix=args.project + '-') as scratch:
        private = Path(scratch)
        private.chmod(0o700)
        environment = {**os.environ,
                       'READINESS_IMAGE': args.image,
                       'READINESS_UID': str(os.getuid()), 'READINESS_GID': str(os.getgid()),
                       'READINESS_APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
                       'READINESS_DB_PASSWORD': secrets.token_hex(24),
                       'READINESS_MIGRATION_PASSWORD': secrets.token_hex(24),
                       'READINESS_POSTGRES_PASSWORD': secrets.token_hex(24)}
        compose = ['docker', 'compose', '-p', args.project, '-f', str(root / 'compose.readiness.yaml')]

        def run(command, *, input_bytes=None):
            result = subprocess.run(command, cwd=root, env=environment, input=input_bytes,
                                    capture_output=True, timeout=180)
            if result.returncode:
                # Raw database/application errors may contain credentials or private fixture rows.
                raise RuntimeError('Readiness phase failed: ' + command[0])
            return result.stdout

        def dc(*command, input_bytes=None):
            return run([*compose, *command], input_bytes=input_bytes)

        def probe(service, mode):
            output = dc('run', '--rm', '-T', service, 'tests/Fixtures/backup-restore.php', mode)
            lines = [line for line in output.decode().splitlines() if line.startswith('{')]
            if not lines:
                # Fixture output is synthetic, but keep raw diagnostics in a private temporary file.
                descriptor, diagnostic = tempfile.mkstemp(prefix='weavdash-restore-diagnostic-', suffix='.txt')
                with os.fdopen(descriptor, 'wb') as log:
                    log.write(output)
                raise RuntimeError('Fixture emitted no JSON (' + mode + '); private diagnostic: ' + diagnostic)
            return json.loads(lines[-1])

        def validate(service):
            container = dc('ps', '-q', service).decode().strip()
            if not container or '\n' in container:
                raise RuntimeError('Expected one isolated database container.')
            info = json.loads(run(['docker', 'inspect', container]))[0]
            labels = info['Config']['Labels']
            if (labels.get('com.docker.compose.project') != args.project
                    or labels.get('weavdash.readiness') != 'synthetic-disposable'
                    or info['HostConfig'].get('PortBindings')):
                raise RuntimeError('Refusing unexpected or published database.')
            for network in info['NetworkSettings']['Networks']:
                details = json.loads(run(['docker', 'network', 'inspect', network]))[0]
                if not details['Internal'] or details['Labels'].get('com.docker.compose.project') != args.project:
                    raise RuntimeError('Refusing a shared/external network.')
            for mount in info['Mounts']:
                if mount['Type'] == 'volume':
                    details = json.loads(run(['docker', 'volume', 'inspect', mount['Name']]))[0]
                    if (details.get('Labels') or {}).get('com.docker.compose.project') != args.project:
                        raise RuntimeError('Refusing shared database storage.')

        try:
            print('Restore rehearsal: fresh isolated databases', flush=True)
            dc('up', '-d', '--wait', 'source', 'restored')
            for service in ['source', 'restored']:
                validate(service)
            for service in ['migrate', 'restored-control']:
                dc('run', '--rm', '-T', '--user', '0:0', '--entrypoint', 'chown', service,
                   str(os.getuid()) + ':' + str(os.getgid()), '/objects')
            dc('run', '--rm', '-T', 'migrate', 'artisan', 'migrate', '--force')
            dc('run', '--rm', '-T', 'migrate', 'scripts/grant-runtime.php')
            probe('migrate', 'seed')
            before = probe('migrate', 'fingerprint')
            probe('probe', 'verify')

            print('Restore rehearsal: encrypt database, documents and matching key', flush=True)
            backup_started = time.monotonic()
            dump = dc('exec', '-T', 'source', 'pg_dump', '-U', 'postgres', '-d', 'weavdash', '-Fc')
            (private / 'database.dump').write_bytes(dump)
            dc('run', '--rm', '-T', '-v', str(private) + ':/backup', '--entrypoint', 'tar',
               'migrate', 'cf', '/backup/objects.tar', '-C', '/objects', '.')
            (private / 'application.key').write_text(environment['READINESS_APP_KEY'])
            for name in ['database.dump', 'objects.tar', 'application.key']:
                (private / name).chmod(0o600)
            with tarfile.open(private / 'bundle.tar', 'w') as archive:
                for name in ['database.dump', 'objects.tar', 'application.key']:
                    archive.add(private / name, arcname=name)
            (private / 'passphrase').write_text(secrets.token_hex(32))
            (private / 'passphrase').chmod(0o600)
            (private / 'gnupg').mkdir(mode=0o700)
            gpg = ['gpg', '--batch', '--yes', '--no-symkey-cache', '--homedir', str(private / 'gnupg'),
                   '--pinentry-mode', 'loopback', '--passphrase-file', str(private / 'passphrase')]
            run([*gpg, '--cipher-algo', 'AES256', '--symmetric', '--output', str(private / 'bundle.gpg'), str(private / 'bundle.tar')])
            (private / 'bundle.gpg').chmod(0o600)
            digest = hashlib.sha256((private / 'bundle.gpg').read_bytes()).hexdigest()
            # Recovery uses only an independent copy of the encrypted bundle and its escrowed passphrase.
            shutil.copyfile(private / 'bundle.gpg', private / 'offhost-copy.gpg')
            (private / 'offhost-copy.gpg').chmod(0o600)
            if hashlib.sha256((private / 'offhost-copy.gpg').read_bytes()).hexdigest() != digest:
                raise RuntimeError('Encrypted copy checksum mismatch.')
            for name in ['database.dump', 'objects.tar', 'application.key', 'bundle.tar', 'bundle.gpg']:
                (private / name).unlink()
            backup_seconds = round(time.monotonic() - backup_started, 3)
            dc('stop', 'source')

            print('Restore rehearsal: recover from encrypted copy with source offline', flush=True)
            restore_started = time.monotonic()
            run([*gpg, '--decrypt', '--output', str(private / 'recovered.tar'), str(private / 'offhost-copy.gpg')])
            with tarfile.open(private / 'recovered.tar') as archive:
                if set(archive.getnames()) != {'database.dump', 'objects.tar', 'application.key'}:
                    raise RuntimeError('Unexpected backup contents.')
                archive.extractall(private, filter='data')
            environment['READINESS_APP_KEY'] = (private / 'application.key').read_text()
            dc('exec', '-T', 'restored', 'pg_restore', '-U', 'postgres', '-d', 'weavdash', '--exit-on-error',
               input_bytes=(private / 'database.dump').read_bytes())
            dc('run', '--rm', '-T', '-v', str(private) + ':/backup:ro', '--entrypoint', 'tar',
               'restored-control', 'xf', '/backup/objects.tar', '-C', '/objects')
            after = probe('restored-control', 'fingerprint')
            if before != after:
                raise RuntimeError('Restored database or document inventory differs.')
            verified = probe('restored-probe', 'verify')
            if after != probe('restored-control', 'fingerprint'):
                raise RuntimeError('Verification did not preserve restored records/files.')
            summary = {**verified, 'project': args.project, 'public_tables_compared': len(before['tables']),
                       'object_checksums_compared': len(before['objects']), 'encrypted_bundle_copy_verified': True,
                       'source_offline_during_restore': True, 'backup_seconds': backup_seconds,
                       'restore_and_verification_seconds': round(time.monotonic() - restore_started, 3),
                       'elapsed_seconds': round(time.monotonic() - started, 3),
                       'production_rpo_rto_accepted': False, 'real_offhost_storage_tested': False}
            print(json.dumps(summary, sort_keys=True), flush=True)
        finally:
            # This invocation refused all existing resources before creating its unique project.
            dc('down', '--volumes', '--remove-orphans')


if __name__ == '__main__':
    main()
