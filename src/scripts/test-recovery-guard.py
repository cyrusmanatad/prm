#!/usr/bin/env python3
"""Verify the fault runner refuses shared resources before injecting faults."""
import copy
import importlib.util
import json
import os
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('recovery', Path(__file__).with_name('check-recovery.py'))
recovery = importlib.util.module_from_spec(spec)
spec.loader.exec_module(recovery)


class GuardTest(unittest.TestCase):
    def test_shared_project_names_are_rejected_before_docker_access(self):
        for project in ['weavdash', 'weavdash-staging', 'weavdash-professional', 'weavdash-m5-recovery-', 'x;echo bad']:
            with self.subTest(project=project), patch.object(recovery.subprocess, 'run') as run:
                with self.assertRaises(ValueError):
                    recovery.RecoveryStack(project)
                run.assert_not_called()

    def test_inspected_identity_network_ports_and_mounts_fail_closed(self):
        stack = recovery.RecoveryStack('weavdash-m5-recovery-guard')
        container = {'Config': {'Labels': {
            'com.docker.compose.project': stack.project,
            'com.docker.compose.service': 'redis',
            'weavdash.recovery': 'synthetic-disposable',
        }}, 'HostConfig': {'PortBindings': {}}, 'NetworkSettings': {'Networks': {'isolated': {}}},
            'Mounts': [{'Type': 'volume', 'Name': stack.project + '_redis-data'}]}
        network = {'Internal': True, 'Labels': {'com.docker.compose.project': stack.project}}
        for mutation in ['valid', 'project', 'service', 'label', 'port', 'external', 'network-project', 'volume', 'bind']:
            data, net = copy.deepcopy(container), copy.deepcopy(network)
            if mutation in ['project', 'service']:
                data['Config']['Labels']['com.docker.compose.' + mutation] = 'shared'
            elif mutation == 'label':
                del data['Config']['Labels']['weavdash.recovery']
            elif mutation == 'port':
                data['HostConfig']['PortBindings'] = {'6379/tcp': [{'HostPort': '6379'}]}
            elif mutation == 'external':
                net['Internal'] = False
            elif mutation == 'network-project':
                net['Labels']['com.docker.compose.project'] = 'shared'
            elif mutation == 'volume':
                data['Mounts'][0]['Name'] = 'shared-data'
            elif mutation == 'bind':
                data['Mounts'] = [{'Type': 'bind', 'Source': '/shared/production'}]

            def output(command, **kwargs):
                if command[1] == 'volume':
                    return json.dumps([{'Labels': {'com.docker.compose.project': stack.project}}])
                return json.dumps([net if command[1] == 'network' else data])

            with self.subTest(mutation=mutation), patch.dict(os.environ, {
                'RECOVERY_SOURCE': '/tmp/recovery-source', 'RECOVERY_CONTROL': '/tmp/recovery-control',
            }), patch.object(stack, 'run', return_value=SimpleNamespace(stdout='container-id')), patch.object(recovery.subprocess, 'check_output', side_effect=output):
                if mutation == 'valid':
                    self.assertEqual('container-id', stack.validate('redis'))
                else:
                    with self.assertRaises(RuntimeError):
                        stack.validate('redis')


if __name__ == '__main__':
    unittest.main()
