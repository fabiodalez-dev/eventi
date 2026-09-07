#!/usr/bin/python3
"""Private, one-way Git backups. Never execute code from cloned repositories."""
import fcntl
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import time
import urllib.request
from urllib.error import HTTPError

from github_app import GitHub

ROOT = Path('/var/lib/fabio-git-mirrors')
CONFIG = Path('/etc/fabio-ci/gitlab-mirror.json')


def save_state(path, data):
    temporary = path.with_suffix('.pending')
    temporary.write_text(json.dumps(data, indent=2))
    temporary.replace(path)


def gitlab(token, path, method='GET', data=None):
    if not path.startswith('/') or path.startswith('//'):
        raise ValueError('Invalid API path')
    request = urllib.request.Request('https://gitlab.com/api/v4' + path,
        headers={'PRIVATE-TOKEN': token, 'Content-Type': 'application/json'},
        data=None if data is None else json.dumps(data).encode(), method=method)
    with urllib.request.urlopen(request, timeout=60) as response:
        body = response.read()
        return json.loads(body) if body else None


def valid_name(name):
    return bool(re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_.-]*', name)) and not name.endswith('.git')


def refs(output):
    result = {}
    for line in output.splitlines():
        sha, ref = line.split()
        if ref.startswith(('refs/heads/', 'refs/tags/')) and not ref.endswith('^{}'):
            result[ref] = sha
    return result


def verify_destination(project, config, name):
    if (project['namespace']['id'] != config['namespace_id'] or
            project['path_with_namespace'] != config['namespace'] + '/' + name or
            project['visibility'] != 'private'):
        raise ValueError('Destination identity or privacy mismatch')


def run_git(args, env, cwd=None):
    process = subprocess.Popen(['git', '-c', 'core.hooksPath=/dev/null',
        '-c', 'credential.helper=', '-c', 'http.followRedirects=false',
        '-c', 'http.lowSpeedLimit=1000', '-c', 'http.lowSpeedTime=120',
        '-c', 'protocol.file.allow=never', *args],
        env=env, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        text=True, start_new_session=True)
    try:
        stdout, _ = process.communicate(timeout=1800)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGTERM)
        try:
            process.communicate(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.communicate()
        raise RuntimeError('Git transfer timed out') from None
    if process.returncode:
        # Git output may contain remote-controlled text or credentials.
        raise RuntimeError('Git command failed: ' + args[0])
    return stdout


def sync_repository(repository, config, token, github_token):
    name = repository['name']
    if not valid_name(name):
        raise ValueError('Unsafe repository name')
    if shutil.disk_usage(ROOT).free < 20 * 1024**3:
        raise ValueError('Less than 20 GiB free: preserving host disk reserve')
    path = config['namespace'] + '/' + name
    from urllib.parse import quote
    try:
        project = gitlab(token, '/projects/' + quote(path, safe=''))
    except HTTPError as error:
        if error.code != 404:
            raise
        # Project creation through REST is not available within a group-scoped
        # fine-grained token. Fail closed, never fall back to an account-wide token.
        raise ValueError('Create the empty private destination in GitLab first') from error
    verify_destination(project, config, name)
    statefile = ROOT / (str(repository['id']) + '.json')
    state = json.loads(statefile.read_text()) if statefile.exists() else None
    if state and state['project_id'] != project['id']:
        raise ValueError('Destination was replaced')
    if not state and not project['empty_repo']:
        raise ValueError('Refusing to adopt a nonempty destination')
    if not state:
        state = {'project_id': project['id'], 'refs': {},
                 'source': repository['full_name'], 'destination': path}
        save_state(statefile, state)
    gitlab(token, '/projects/' + str(project['id']), 'PUT', {
        'builds_access_level': 'disabled', 'shared_runners_enabled': False,
        'auto_devops_enabled': False,
    })
    env = {**os.environ, 'GIT_TERMINAL_PROMPT': '0',
        'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
        'GIT_ASKPASS': '/usr/local/lib/fabio-ci/mirror_askpass.py',
        'MIRROR_GITHUB_TOKEN': github_token, 'MIRROR_GITLAB_TOKEN': token}
    local = ROOT / (str(repository['id']) + '.git')
    if not local.exists():
        run_git(['init', '--bare', str(local)], env)
    # Preserve all previously fetched history before a force push or deletion.
    old = refs(run_git(['for-each-ref', '--format=%(objectname) %(refname)'], env, local))
    if old:
        archive = ROOT / 'history' / str(repository['id'])
        archive.mkdir(parents=True, exist_ok=True)
        day = time.strftime('%Y-%m-%d', time.gmtime())
        bundle = archive / (day + '.bundle')
        if not bundle.exists():
            run_git(['bundle', 'create', str(bundle), '--all'], env, local)
    source = 'https://github.com/' + repository['full_name'] + '.git'
    destination = 'https://gitlab.com/' + path + '.git'
    run_git(['fetch', '--prune', source, '+refs/heads/*:refs/heads/*',
             '+refs/tags/*:refs/tags/*'], env, local)
    current = refs(run_git(['for-each-ref', '--format=%(objectname) %(refname)'], env, local))
    remote = refs(run_git(['ls-remote', '--refs', destination], env, local))
    if remote != (state or {}).get('refs', {}):
        # An interrupted push can have succeeded before state was persisted.
        if remote != current and remote != state.get('pending_refs'):
            raise ValueError('Destination changed outside this backup service')
    if current and current != remote:
        lease = []
        expected = {**remote, **current}
        # Source branches may be rebased (Dependabot) or replaced (built assets).
        # Preserve every replaced target in an immutable tag, then use an exact
        # lease: an external concurrent change still rejects the entire push.
        for ref, previous in remote.items():
            if ref in current and current[ref] != previous:
                run_git(['fetch', destination, ref], env, local)
                backup_ref = 'refs/tags/mirror-history-' + previous
                run_git(['update-ref', backup_ref, previous], env, local)
                expected[backup_ref] = previous
                lease.append('--force-with-lease=' + ref + ':' + previous)
        # Do not delete remote refs. Old history is retained on GitLab as well
        # as in the local bundle, even when the source rewrites its branches.
        # Persist the exact atomic result before pushing, so a crash after a
        # rewrite can be recovered without accepting unrelated remote changes.
        save_state(statefile, {**state, 'pending_refs': expected})
        run_git(['push', '--atomic', *lease, '-o', 'ci.skip', destination,
                 'refs/heads/*:refs/heads/*', 'refs/tags/*:refs/tags/*'], env, local)
    confirmed = refs(run_git(['ls-remote', '--refs', destination], env, local))
    if any(confirmed.get(ref) != sha for ref, sha in current.items()):
        raise ValueError('Remote verification failed')
    save_state(statefile, {'project_id': project['id'], 'refs': confirmed,
        'verified_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
        'source': repository['full_name'], 'destination': path})
    print('Verified ' + repository['full_name'] + ': ' + str(len(current)) + ' refs', flush=True)


def main():
    os.umask(0o077)
    ROOT.mkdir(parents=True, exist_ok=True)
    lock = (ROOT / '.lock').open('w')
    fcntl.flock(lock, fcntl.LOCK_EX)
    config = json.loads(CONFIG.read_text())
    token = Path('/etc/fabio-ci/gitlab-mirror.token').read_text().strip()
    api = GitHub(json.loads(Path('/etc/fabio-ci/github-app.json').read_text()))
    failures = []
    page = 1
    while True:
        repositories = api.request('/installation/repositories?per_page=100&page=' + str(page))['repositories']
        for repository in repositories:
            if repository['owner']['login'].lower() != config['owner'].lower():
                continue
            selected = sys.argv[1:] or config.get('repositories', [])
            if selected and repository['name'] not in selected:
                continue
            try:
                # Renew short-lived credentials before each repository.
                api.request('/repos/' + repository['full_name'])
                sync_repository(repository, config, token, api.token)
            except Exception as error:
                code = (' HTTP ' + str(error.code)) if isinstance(error, HTTPError) else ''
                if isinstance(error, (ValueError, RuntimeError)):
                    code += ' ' + str(error)
                print('Failed ' + repository['full_name'] + ': ' + type(error).__name__ + code, flush=True)
                failures.append(repository['full_name'])
        if len(repositories) < 100:
            break
        page += 1
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
