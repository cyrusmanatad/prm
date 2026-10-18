#!/usr/bin/env python3
"""Verify a fresh source/configuration/database setup without local dependencies.

Requires Docker Compose, Python 3, Node 24/npm, and Chromium and WebKit system libraries.
Creates and removes only a new, explicitly named synthetic Compose project.
Build caches may be reused; host vendor/node_modules and local .env are excluded.
"""
import argparse
import base64
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tempfile
import time


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', required=True)
    parser.add_argument('--port', type=int, default=8001)
    args = parser.parse_args()
    if not re.fullmatch(r'weavdash-m1-clean-[a-z0-9-]+', args.project) or not 1024 <= args.port <= 65535:
        parser.error('Use a new weavdash-m1-clean-* project and a port between 1024 and 65535.')
    root = Path(__file__).resolve().parents[2]
    logs = Path(tempfile.mkdtemp(prefix=args.project+'-evidence-'))
    for resource in ['ps', 'volume ls']:
        command = ['docker', *resource.split(), '-q', '--filter', 'label=com.docker.compose.project='+args.project]
        if subprocess.run(command, check=True, capture_output=True, text=True).stdout.strip():
            raise RuntimeError('Refusing to reuse existing project resources.')
    started = time.monotonic()
    phases = []
    with tempfile.TemporaryDirectory(prefix=args.project+'-source-') as directory:
        source = Path(directory)
        files = subprocess.run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'],
                               cwd=root, check=True, capture_output=True).stdout
        for raw in set(files.split(b'\0')) - {b''}:
            relative = Path(os.fsdecode(raw))
            original = root / relative
            if original.is_symlink():
                raise RuntimeError(f'Review source symlink before setup: {relative}')
            if original.is_file():
                target = source / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(original, target)
        assert not (source / 'src/vendor').exists()
        assert not (source / 'src/node_modules').exists()
        assert not (source / 'src/.env').exists()
        config = (source / 'src/.env.example').read_text()
        values = {key: secrets.token_hex(24) for key in ['DB_PASSWORD', 'MIGRATION_DB_PASSWORD', 'POSTGRES_PASSWORD']}
        values['APP_KEY'] = 'base64:'+base64.b64encode(secrets.token_bytes(32)).decode()
        values['APP_DEBUG'] = 'false'
        for key, value in values.items():
            config, count = re.subn(r'^'+key+r'=.*$', key+'='+value, config, flags=re.MULTILINE)
            if count != 1:
                raise RuntimeError(f'Expected exactly one {key} configuration entry.')
        environment_file = source / 'src/.env'
        environment_file.write_text(config)
        environment_file.chmod(0o600)
        # Shell variables otherwise override --env-file and could reuse host credentials.
        application_keys = set(re.findall(r'^([A-Z][A-Z0-9_]*)=', config, re.MULTILINE))
        clean_environment = {key: value for key, value in os.environ.items() if key not in application_keys}
        (source / 'compose.clean.yaml').write_text(
            'services:\n  app:\n    ports: !override\n'
            f'      - "127.0.0.1:{args.port}:8000"\n'
            f'    environment:\n      APP_URL: http://localhost:{args.port}\n')
        compose = ['docker', 'compose', '--env-file', 'src/.env', '-p', args.project,
                   '-f', 'compose.yaml', '-f', 'compose.clean.yaml']

        def phase(name, command, *, cwd=None, timeout=600, input_file=None, env=None):
            print(f'Clean setup: {name}', flush=True)
            with (logs / (name+'.log')).open('w') as output:
                with open(input_file, 'rb') if input_file else open(os.devnull, 'rb') as stdin:
                    result = subprocess.run(command, cwd=cwd or source, env=env if env is not None else clean_environment,
                                            stdin=stdin, stdout=output, stderr=subprocess.STDOUT, timeout=timeout)
            if result.returncode:
                raise RuntimeError(f'{name} failed; inspect {logs / (name+".log")}')
            phases.append(name)

        try:
            phase('build', compose+['build', '--pull', 'app', 'migrate', 'acceptance', 'worker', 'scheduler'], timeout=900)
            phase('dependencies', compose+['up', '-d', '--wait', 'postgres', 'redis'])
            phase('migrate', compose+['run', '--rm', '-T', 'migrate'])
            phase('grants', compose+['run', '--rm', '-T', 'migrate', 'php', 'scripts/grant-runtime.php'])
            for name in ['rls-fixtures', 'rls']:
                phase(name, compose+['exec', '-T', 'postgres', 'psql', '-U', 'postgres', '-d', 'weavdash', '-v', 'ON_ERROR_STOP=1'],
                      input_file=source / f'src/tests/Integration/{name}.sql')
            phase('identity', compose+['run', '--rm', '-T', 'app', 'php', 'artisan', 'test', '--compact'])
            phase('integration', compose+['run', '--rm', '-T', 'app', 'vendor/bin/phpunit', '-c', 'phpunit.pgsql.xml'])
            phase('acceptance', compose+['run', '--rm', '-T', 'acceptance'], timeout=180)
            phase('app', compose+['up', '-d', '--wait', 'app'])
            phase('npm-ci', ['npm', 'ci', '--ignore-scripts', '--no-fund'], cwd=source / 'src')
            phase('frontend-types', ['npm', 'run', 'typecheck'], cwd=source / 'src')
            phase('frontend-tests', ['npm', 'test'], cwd=source / 'src')
            phase('frontend-build', ['npm', 'run', 'build'], cwd=source / 'src')
            phase('browsers', ['npx', 'playwright', 'install', 'chromium', 'webkit'], cwd=source / 'src')
            browser_env = {**clean_environment, 'COMPOSE_PROJECT_NAME': args.project,
                           'COMPOSE_FILE': str(source / 'compose.yaml')+os.pathsep+str(source / 'compose.clean.yaml'),
                           'PLAYWRIGHT_BASE_URL': f'http://localhost:{args.port}'}
            phase('browser', ['npm', 'run', 'test:browser'], cwd=source / 'src', env=browser_env, timeout=240)
        finally:
            phase('cleanup', compose+['down', '--volumes'], timeout=90)
    summary = {'project': args.project, 'phases': phases, 'elapsed_seconds': round(time.monotonic()-started),
               'host_dependencies_copied': False, 'reused_local_credentials': False,
               'build_cache_may_be_reused': True,
               'remote_ci_executed': os.environ.get('GITHUB_ACTIONS') == 'true'}
    (logs / 'summary.json').write_text(json.dumps(summary, indent=2)+'\n')
    print(f'Clean setup passed. Evidence: {logs}', flush=True)


if __name__ == '__main__':
    main()
