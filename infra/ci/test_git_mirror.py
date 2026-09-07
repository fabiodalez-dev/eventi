import os
from pathlib import Path
import subprocess
import sys
import unittest
import tempfile
from unittest.mock import patch
from types import SimpleNamespace

import git_mirror

from git_mirror import refs, valid_name, verify_destination


class MirrorTests(unittest.TestCase):
    def test_only_changed_sources_need_incremental_sync(self):
        repository = {'pushed_at': '2026-09-07T10:00:00Z'}
        state = {'source_pushed_at': repository['pushed_at']}
        self.assertFalse(git_mirror.needs_sync(repository, state, True))
        self.assertTrue(git_mirror.needs_sync(repository, {**state, 'pending_refs': {}}, True))
        self.assertTrue(git_mirror.needs_sync(repository, state, False))
        self.assertTrue(git_mirror.needs_sync(repository, None, True))
        self.assertTrue(git_mirror.needs_sync({}, state, True))
        self.assertTrue(git_mirror.needs_sync({'pushed_at': 'later'}, state, True))

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


class MirrorIntegrationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source = self.root / 'source'
        self.destination = self.root / 'destination.git'
        self.backups = self.root / 'backups'
        self.backups.mkdir()
        self.git('init', '-b', 'main', str(self.source))
        self.git('init', '--bare', str(self.destination))
        self.git('-C', str(self.source), 'config', 'user.name', 'Mirror Test')
        self.git('-C', str(self.source), 'config', 'user.email', 'mirror@example.test')
        self.commit('one')
        self.project = {'id': 4, 'namespace': {'id': 1},
                        'path_with_namespace': 'backup/eventi', 'visibility': 'private', 'empty_repo': True}
        self.config = {'namespace': 'backup', 'namespace_id': 1}
        self.repository = {'id': 7, 'name': 'eventi', 'full_name': 'owner/eventi'}
        original_git = git_mirror.run_git

        def local_git(args, env, cwd=None):
            targets = {'https://github.com/owner/eventi.git': str(self.source),
                       'https://gitlab.com/backup/eventi.git': str(self.destination)}
            args = [targets.get(arg, arg) for arg in args]
            # Local bare fixtures do not advertise push options.
            if '-o' in args:
                index = args.index('-o')
                del args[index:index + 2]
            return original_git(['-c', 'protocol.file.allow=always', *args], env, cwd)

        for patcher in [patch.object(git_mirror, 'ROOT', self.backups),
                        patch.object(git_mirror, 'gitlab', return_value=self.project),
                        patch.object(git_mirror.shutil, 'disk_usage', return_value=SimpleNamespace(free=100 * 1024**3)),
                        patch.object(git_mirror, 'run_git', side_effect=local_git)]:
            patcher.start()
            self.addCleanup(patcher.stop)

    def git(self, *args):
        return subprocess.run(['git', *args], check=True, capture_output=True, text=True).stdout.strip()

    def commit(self, text):
        (self.source / 'content.txt').write_text(text)
        self.git('-C', str(self.source), 'add', 'content.txt')
        self.git('-C', str(self.source), 'commit', '-m', text)

    def sync(self):
        git_mirror.sync_repository(self.repository, self.config, 'test-token', 'test-github')

    def test_sync_repeat_update_and_restore_bundle(self):
        self.sync()
        self.sync()
        self.commit('two')
        self.sync()
        self.assertEqual(self.git('-C', str(self.source), 'rev-parse', 'main'),
                         self.git('--git-dir', str(self.destination), 'rev-parse', 'main'))
        bundle = next((self.backups / 'history' / '7').glob('*.bundle'))
        self.git('-C', str(self.source), 'bundle', 'verify', str(bundle))
        restore = self.root / 'restore.git'
        self.git('clone', '--bare', str(bundle), str(restore))
        self.assertEqual(self.git('--git-dir', str(restore), 'show', 'main:content.txt'), 'one')

    def test_external_destination_change_is_rejected(self):
        self.sync()
        self.git('--git-dir', str(self.destination), 'update-ref', 'refs/heads/extra', 'main')
        with self.assertRaisesRegex(ValueError, 'outside'):
            self.sync()

    def test_source_rewrite_preserves_previous_commit_on_gitlab(self):
        self.sync()
        previous = self.git('-C', str(self.source), 'rev-parse', 'HEAD')
        self.git('-C', str(self.source), 'checkout', '--orphan', 'replacement')
        self.commit('rewritten')
        self.git('-C', str(self.source), 'branch', '-M', 'main')
        self.sync()
        self.assertEqual(self.git('-C', str(self.source), 'rev-parse', 'HEAD'),
                         self.git('--git-dir', str(self.destination), 'rev-parse', 'main'))
        self.assertEqual(previous, self.git('--git-dir', str(self.destination), 'rev-parse', 'refs/tags/mirror-history-' + previous))
        self.sync()

    def test_nonempty_destination_is_not_adopted(self):
        self.project['empty_repo'] = False
        with self.assertRaisesRegex(ValueError, 'nonempty'):
            self.sync()

    def test_host_disk_reserve_is_preserved(self):
        with patch.object(git_mirror.shutil, 'disk_usage', return_value=SimpleNamespace(free=1024)):
            with self.assertRaisesRegex(ValueError, 'reserve'):
                self.sync()

    def test_interrupted_state_save_can_recover(self):
        original_save = git_mirror.save_state
        def interrupted(path, data):
            if 'verified_at' in data and 'pending_refs' not in data:
                raise OSError('Simulated interruption after push')
            original_save(path, data)

        with patch.object(git_mirror, 'save_state', side_effect=interrupted):
            with self.assertRaises(OSError):
                self.sync()
        self.project['empty_repo'] = False
        self.sync()

    def test_interrupted_rewrite_recovers_without_losing_archive(self):
        self.sync()
        previous = self.git('-C', str(self.source), 'rev-parse', 'HEAD')
        self.git('-C', str(self.source), 'checkout', '--orphan', 'replacement')
        self.commit('replacement')
        self.git('-C', str(self.source), 'branch', '-M', 'main')
        original_save = git_mirror.save_state

        def interrupted(path, data):
            if 'verified_at' in data and 'pending_refs' not in data:
                raise OSError('Simulated interruption after rewrite')
            original_save(path, data)

        with patch.object(git_mirror, 'save_state', side_effect=interrupted):
            with self.assertRaises(OSError):
                self.sync()
        self.sync()
        self.assertEqual(previous, self.git('--git-dir', str(self.destination),
                         'rev-parse', 'refs/tags/mirror-history-' + previous))
