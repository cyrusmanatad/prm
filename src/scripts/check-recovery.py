#!/usr/bin/env python3
"""Run M5 synthetic faults on a fresh, dedicated internal-only Compose project."""
import argparse
import base64
import secrets
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time


class RecoveryStack:
    def __init__(self, project):
        if not re.fullmatch(r'weavdash-m5-recovery-[a-z0-9]+', project):
            raise ValueError('Refusing non-disposable project name')
        self.project = project
        self.root = Path(__file__).resolve().parents[2]
        # Always replace inherited recovery credentials; each stack is disposable.
        self.environment = {**os.environ,
                            'RECOVERY_APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
                            'RECOVERY_DB_PASSWORD': secrets.token_hex(24),
                            'RECOVERY_MIGRATION_PASSWORD': secrets.token_hex(24),
                            'RECOVERY_POSTGRES_PASSWORD': secrets.token_hex(24)}
        self.compose = ['docker', 'compose', '-p', project, '-f', str(self.root / 'compose.recovery.yaml')]

    def run(self, *args, check=True):
        result = subprocess.run([*self.compose, *args], cwd=self.root, text=True,
                                capture_output=True, timeout=120, env=self.environment)
        if check and result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        return result

    def validate(self, service):
        container = self.run('ps', '-a', '-q', service).stdout.strip()
        if not container or '\n' in container:
            raise RuntimeError('Expected exactly one recovery container: ' + service)
        data = json.loads(subprocess.check_output(['docker', 'inspect', container], text=True))[0]
        labels = data['Config']['Labels']
        if (labels.get('com.docker.compose.project') != self.project
                or labels.get('com.docker.compose.service') != service
                or labels.get('weavdash.recovery') != 'synthetic-disposable'
                or data['HostConfig'].get('PortBindings')):
            raise RuntimeError('Refusing unrecognized or published container: ' + service)
        for network in data['NetworkSettings']['Networks']:
            info = json.loads(subprocess.check_output(['docker', 'network', 'inspect', network], text=True))[0]
            if not info['Internal'] or info['Labels'].get('com.docker.compose.project') != self.project:
                raise RuntimeError('Refusing shared/external network')
        for mount in data['Mounts']:
            if mount['Type'] == 'volume' and not mount['Name'].startswith(self.project + '_'):
                raise RuntimeError('Refusing shared volume')
            if mount['Type'] == 'volume':
                volume = json.loads(subprocess.check_output(['docker', 'volume', 'inspect', mount['Name']], text=True))[0]
                if (volume.get('Labels') or {}).get('com.docker.compose.project') != self.project:
                    raise RuntimeError('Refusing volume without matching project ownership')
            if mount['Type'] == 'bind' and mount['Source'] not in {
                    str(Path(os.environ['RECOVERY_SOURCE']).resolve()),
                    str(Path(os.environ['RECOVERY_CONTROL']).resolve()),
                    str(self.root / 'src/tests/Fixtures/recovery-signatures.hdb'),
                    str(self.root / 'src/docker/postgres/init.sh')}:
                raise RuntimeError('Refusing unexpected bind mount')
        return container

    def fault(self, fault):
        services = ['worker', 'scheduler'] if fault == 'process-crash' else ['redis']
        if fault in ['smtp-stop', 'smtp-start']:
            services = ['smtp']
        for service in services:
            self.validate(service)
        if fault == 'redis-restart':
            self.run('restart', 'redis')
            self.run('up', '-d', '--wait', 'redis')
        elif fault in ['redis-stop', 'redis-start']:
            if fault == 'redis-stop':
                self.run('stop', 'redis')
            else:
                self.run('up', '-d', '--wait', 'redis')
        elif fault == 'redis-loss':
            self.run('exec', '-T', 'redis', 'redis-cli', 'FLUSHALL', 'SYNC')
        elif fault in ['smtp-stop', 'smtp-start']:
            if fault == 'smtp-stop':
                self.run('stop', 'smtp')
            else:
                self.run('up', '-d', '--wait', 'smtp')
        elif fault == 'process-crash':
            for service in services:
                container = self.validate(service)
                before = json.loads(subprocess.check_output(['docker', 'inspect', container], text=True))[0]
                # Docker kill suppresses restart policy; kill the actual Artisan child.
                command = 'queue:work' if service == 'worker' else 'schedule:work'
                probe = '''foreach (glob('/proc/[0-9]*/cmdline') as $p) {
                    $a=explode("\\0", @file_get_contents($p) ?: '');
                    if (($a[1] ?? '')==='artisan' && ($a[2] ?? '')===$argv[1]) {
                        $pid=(int)basename(dirname($p));
                        if ($pid>1) { exit(posix_kill($pid, SIGKILL) ? 0 : 1); }
                    }
                } exit(2);'''
                result = self.run('exec', '-T', service, 'php', '-r', probe, command, check=False)
                if result.returncode not in [0, 137]:
                    raise RuntimeError('Failed to kill Artisan')
                deadline = time.monotonic() + 30
                while time.monotonic() < deadline:
                    after = json.loads(subprocess.check_output(['docker', 'inspect', container], text=True))[0]
                    if after['State']['Running'] and after['RestartCount'] > before['RestartCount']:
                        break
                    time.sleep(.2)
                else:
                    raise RuntimeError('Process failed to restart')
        else:
            raise ValueError('Unknown fault: ' + fault)
        print('fault passed: ' + fault, flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', required=True)
    parser.add_argument('--filter', help='Optional focused PHPUnit test filter')
    args = parser.parse_args()
    stack = RecoveryStack(args.project)
    source = Path(os.environ['RECOVERY_SOURCE']).resolve()
    if (source.parent != Path(tempfile.gettempdir()).resolve()
            or not source.name.startswith('weavdash-m5-recovery-')
            or not (source / 'vendor/autoload.php').is_file()
            or not (source / 'public/build/manifest.json').is_file()
            or not (source / '.env').is_file()
            or (source / '.env').read_text().strip()
            or (source / 'bootstrap/cache/config.php').exists()):
        raise RuntimeError('Require temporary source, dependencies, built assets, empty .env and uncached config')
    # Refuse even stopped resources from an earlier project before creating anything.
    existing = subprocess.check_output(['docker', 'ps', '-aq', '--filter',
                                       'label=com.docker.compose.project=' + args.project], text=True)
    volumes = subprocess.check_output(['docker', 'volume', 'ls', '-q', '--filter',
                                      'label=com.docker.compose.project=' + args.project], text=True)
    networks = subprocess.check_output(['docker', 'network', 'ls', '-q', '--filter',
                                       'label=com.docker.compose.project=' + args.project], text=True)
    if existing.strip() or volumes.strip() or networks.strip():
        raise RuntimeError('Project already exists; refusing reuse')
    with tempfile.TemporaryDirectory(prefix='weavdash-recovery-control-') as control:
        os.chmod(control, 0o777)
        os.environ['RECOVERY_CONTROL'] = control
        stack.environment['RECOVERY_CONTROL'] = control
        started = time.monotonic()
        try:
            stack.run('up', '-d', '--wait', 'postgres', 'redis', 'smtp', 'clamav')
            for service in ['postgres', 'redis', 'smtp', 'clamav']:
                stack.validate(service)
            migration = ['run', '--rm', '-T', '--no-deps', '-e', 'DB_USERNAME=weavdash_migrator',
                         '-e', 'DB_PASSWORD=' + stack.environment['RECOVERY_MIGRATION_PASSWORD'], 'acceptance']
            stack.run(*migration, 'php', 'artisan', 'migrate', '--force')
            stack.run(*migration, 'php', 'scripts/grant-runtime.php')
            stack.run('up', '-d', 'worker', 'scheduler')
            stack.fault('process-crash')
            # Stop automatic consumers while fixtures coordinate each failure explicitly.
            stack.run('stop', 'worker', 'scheduler')
            for config in ['phpunit.acceptance.xml', 'phpunit.pgsql.xml', 'phpunit.xml']:
                command = [*stack.compose, 'run', '--rm', '-T', '--no-deps', 'acceptance',
                           'vendor/bin/phpunit', '-c', config]
                if args.filter and config == 'phpunit.acceptance.xml':
                    command.extend(['--filter', args.filter])
                with tempfile.TemporaryFile(mode='w+') as log:
                    process = subprocess.Popen(command, stdout=log, stderr=subprocess.STDOUT, env=stack.environment)
                    try:
                        while process.poll() is None:
                            for request in Path(control).glob('*.request'):
                                fault = json.loads(request.read_text())['fault']
                                stack.fault(fault)
                                response = request.with_suffix('.response')
                                response.write_text('ok')
                                response.replace(request.with_suffix('.done'))
                                request.unlink()
                            time.sleep(.1)
                    finally:
                        if process.poll() is None:
                            process.terminate()
                            process.wait(timeout=30)
                        log.seek(0)
                        print(log.read(), flush=True)
                    if process.returncode:
                        raise RuntimeError(config + ' failed')
                if config == 'phpunit.acceptance.xml' and not args.filter:
                    capture_probe = '''$ids=[];
                        foreach (file('/capture/messages.jsonl') ?: [] as $line) {
                            $data=base64_decode(json_decode($line,true,flags:JSON_THROW_ON_ERROR)['data']);
                            if (!preg_match('/^Message-ID:\\s*<([^>]+)>/mi',$data,$m)) { exit(2); }
                            $ids[]=$m[1];
                        }
                        echo json_encode(['messages'=>count($ids),'unique'=>count(array_unique($ids))]);'''
                    captures = json.loads(stack.run('exec', '-T', 'smtp', 'php', '-r', capture_probe).stdout)
                    if captures != {'messages': 6, 'unique': 6}:
                        raise RuntimeError('Unexpected synthetic SMTP capture totals: ' + str(captures))
                    print('SMTP sink: 6 captured messages, 6 unique Message-IDs; replays added none.', flush=True)
            print(stack.run('run', '--rm', '-T', '--no-deps', 'acceptance',
                            'vendor/bin/pint', '--test', 'tests/Acceptance', 'tests/Fixtures').stdout)
            print(f'Recovery gates passed in {time.monotonic() - started:.1f}s', flush=True)
        finally:
            # Only this fresh project is removed. Never prune global Docker resources.
            for service in ['postgres', 'redis', 'smtp', 'clamav', 'worker', 'scheduler']:
                if stack.run('ps', '-a', '-q', service).stdout.strip():
                    stack.validate(service)
            stack.run('down', '--volumes', '--remove-orphans')


if __name__ == '__main__':
    main()
