import os
from pathlib import Path
import subprocess
import sys
import unittest

from git_mirror import refs, valid_name, verify_destination


class MirrorTests(unittest.TestCase):
    def test_repository_name_validation(self):
        for name in ['eventi', 'Hi.Events', 'Pinakes-Android']:
            self.assertTrue(valid_name(name))
        for name in ['../secret', '-flag', 'a/b', 'a.git', '']:
            self.assertFalse(valid_name(name))

    def test_only_heads_and_tags(self):
        self.assertEqual(refs('abc refs/heads/main\ndef refs/tags/v1\nfff refs/tags/v1^{}\naaa refs/pull/1/head\n'),
                         {'refs/heads/main': 'abc', 'refs/tags/v1': 'def'})

    def test_private_exact_destination(self):
        config = {'namespace': 'backup', 'namespace_id': 1}
        project = {'namespace': {'id': 1}, 'path_with_namespace': 'backup/eventi', 'visibility': 'private'}
        verify_destination(project, config, 'eventi')
        for key, value in [('namespace', {'id': 2}), ('visibility', 'public'), ('path_with_namespace', 'else/eventi')]:
            with self.assertRaises(ValueError):
                verify_destination({**project, key: value}, config, 'eventi')

    def test_askpass_only_exact_hosts(self):
        script = Path(__file__).with_name('mirror_askpass.py')
        env = {**os.environ, 'MIRROR_GITHUB_TOKEN': 'test-github', 'MIRROR_GITLAB_TOKEN': 'test-gitlab'}
        for url, expected in [('https://oauth2@gitlab.com', 'test-gitlab'),
                              ('https://x-access-token@github.com', 'test-github')]:
            result = subprocess.run([sys.executable, str(script), "Password for '" + url + "':"], env=env, capture_output=True, text=True)
            self.assertEqual(result.stdout.strip(), expected)
        for url in ['https://gitlab.com.evil.test', 'https://github.com@evil.test', 'http://gitlab.com']:
            result = subprocess.run([sys.executable, str(script), "Password for '" + url + "':"], env=env, capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(result.stdout, '')
