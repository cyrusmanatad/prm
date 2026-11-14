#!/usr/bin/env python3
"""Keep reviewed checksum exceptions restricted to exact findings."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('scanner', Path(__file__).with_name('check-secrets.py'))
scanner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scanner)


class ExceptionTest(unittest.TestCase):
    def test_only_exact_reviewed_checksum_findings_are_accepted(self):
        for path, rule, value in scanner.EVIDENCE_CHECKSUMS:
            finding = {'File': '/scan/' + path, 'RuleID': rule, 'Secret': value}
            self.assertTrue(scanner.evidence_checksum(finding))
            for field, changed in [('File', '/scan/other.json'), ('RuleID', 'another-rule'),
                                   ('Secret', value[:-1] + 'x')]:
                with self.subTest(path=path, field=field):
                    self.assertFalse(scanner.evidence_checksum({**finding, field: changed}))


if __name__ == '__main__':
    unittest.main()
