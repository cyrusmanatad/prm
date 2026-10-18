#!/usr/bin/env python3
"""Scan the reviewable source tree, including untracked non-ignored files.

Uses a digest-pinned Gitleaks image with no container network access. Ignored
local credentials, dependencies, test traces, and .git metadata are not copied.
This scans current source, not Git history or the ignored local environment.
"""
import json
import os
from pathlib import Path
import secrets
import shutil
import string
import subprocess
import tempfile

IMAGE = ('ghcr.io/gitleaks/gitleaks:v8.30.1@'
         'sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f')


def scan(source: Path, results: Path):
    command = ['docker', 'run', '--rm', '--network', 'none', '--read-only',
               '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
               '--user', f'{os.getuid()}:{os.getgid()}',
               '--mount', f'type=bind,src={source},dst=/scan,readonly',
               '--mount', f'type=bind,src={results},dst=/results',
               IMAGE, 'dir', '/scan', '--no-banner', '--no-color', '--redact=100',
               '--report-format', 'json', '--report-path', '/results/report.json', '--timeout', '60']
    result = subprocess.run(command, text=True, capture_output=True, timeout=90)
    report = results / 'report.json'
    if result.returncode not in [0, 1] or not report.exists():
        # Avoid echoing scanner input or secret-bearing diagnostic output.
        raise RuntimeError(f'Gitleaks failed to produce a report (exit {result.returncode}).')
    return result.returncode, json.loads(report.read_text()) or []


def main():
    root = Path(__file__).resolve().parents[2]
    with tempfile.TemporaryDirectory(prefix='weavdash-secrets-') as directory:
        temporary = Path(directory)
        source, results, control = [temporary / name for name in ['source', 'results', 'control']]
        for path in [source, results, control]:
            path.mkdir()
        # A fresh synthetic key proves that a green scan isn't a disabled scanner.
        suffix = ''.join(secrets.SystemRandom().sample(string.ascii_uppercase + '234567', 16))
        (control / 'positive-control.txt').write_text('aws_access_key_id = "AKIA' + suffix + '"\n')
        code, findings = scan(control, results)
        if code != 1 or not any(finding['RuleID'] == 'aws-access-token' for finding in findings):
            rules = [finding.get('RuleID') for finding in findings]
            raise RuntimeError(f'Secret scanner failed its synthetic positive control (exit {code}, rules {rules}).')
        print('Gitleaks synthetic positive control passed.', flush=True)
        listed = subprocess.run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'],
                                cwd=root, check=True, capture_output=True).stdout
        count = 0
        for raw in set(listed.split(b'\0')) - {b''}:
            relative = Path(os.fsdecode(raw))
            original = root / relative
            if original.is_symlink():
                raise RuntimeError(f'Review source symlink before scanning: {relative}')
            if not original.is_file():
                continue
            target = source / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(original, target)
            count += 1
        code, findings = scan(source, results)
        if code or findings:
            for finding in findings:
                print(f"{finding['RuleID']}: {finding['File']}:{finding['StartLine']}")
            raise SystemExit('Secret scan failed; findings are redacted. Review before committing.')
        print(f'Gitleaks source scan passed: {count} reviewable files; no findings.')


if __name__ == '__main__':
    main()
