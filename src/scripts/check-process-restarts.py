#!/usr/bin/env python3
"""Crash only the named worker/scheduler in a disposable Compose project.

Checks Docker restart policy independently of the synthetic schedule/queue tests.
Never run against a shared or production project.
"""
import argparse
import subprocess
import time
from pathlib import Path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', required=True, help='Explicit disposable Compose project')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    compose = ['docker', 'compose', '--env-file', 'src/.env', '-p', args.project]

    def run(command, *, check=True):
        return subprocess.run(command, cwd=root, text=True, capture_output=True,
                              timeout=45, check=check)

    run(compose + ['up', '-d', '--wait', 'worker', 'scheduler'])
    for service in ['worker', 'scheduler']:
        container = run(compose + ['ps', '-q', service]).stdout.strip()
        if not container:
            raise RuntimeError(f'{service}: missing container')

        def state():
            output = run(['docker', 'inspect', '--format',
                          '{{.State.Running}} {{.RestartCount}} {{.State.StartedAt}}',
                          container]).stdout.split()
            return output[0] == 'true', int(output[1]), output[2]

        before = state()
        if not before[0]:
            raise RuntimeError(f'{service}: not running before crash')
        # An init process reaps scheduler children. Kill the actual Artisan
        # process, not namespace PID 1 (which ignores unhandled in-namespace signals).
        command = 'queue:work' if service == 'worker' else 'schedule:work'
        signal_probe = '''
foreach (glob('/proc/[0-9]*/cmdline') as $path) {
    $args = explode("\\0", @file_get_contents($path) ?: '');
    if (($args[1] ?? '') === 'artisan' && ($args[2] ?? '') === $argv[1]) {
        $pid = (int) basename(dirname($path));
        if ($pid > 1) { exit(posix_kill($pid, SIGKILL) ? 0 : 1); }
    }
}
exit(2);
'''
        crash = run(compose + ['exec', '-T', service, 'php', '-r',
                               signal_probe, command], check=False)
        if crash.returncode not in [0, 137]:
            raise RuntimeError(f'{service}: failed to signal its Artisan process')
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            after = state()
            if after[0] and after[1] > before[1] and after[2] != before[2]:
                run(compose + ['exec', '-T', service, 'php', '-r', 'exit(0);'])
                print(f'{service}: SIGKILL recovered; restart count {before[1]} -> {after[1]}', flush=True)
                break
            time.sleep(0.2)
        else:
            raise RuntimeError(f'{service}: automatic restart did not occur')


if __name__ == '__main__':
    main()
