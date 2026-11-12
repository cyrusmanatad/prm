#!/usr/bin/env python3
"""Crash and verify worker/scheduler restart only in a guarded recovery stack."""
import argparse
import importlib.util
from pathlib import Path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', required=True)
    args = parser.parse_args()
    spec = importlib.util.spec_from_file_location('recovery', Path(__file__).with_name('check-recovery.py'))
    recovery = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(recovery)
    recovery.RecoveryStack(args.project).fault('process-crash')


if __name__ == '__main__':
    main()
