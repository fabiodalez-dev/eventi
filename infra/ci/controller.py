#!/usr/bin/python3
"""One global queue for personal repositories, one disposable VM per job."""
import json
import re
import subprocess
import time
from pathlib import Path
from urllib.error import HTTPError

from github_app import GitHub


def trusted_run(run, repo, owner):
    # Never execute privileged pull_request_target or outside contributions.
    if run.get("event") not in {"push", "workflow_dispatch", "schedule", "pull_request"}:
        return False
    if (run.get("head_repository") or {}).get("full_name", "").lower() != repo.lower():
        return False
    actor = run.get("actor", {}).get("login", "")
    return actor.lower() == owner.lower() or actor == "dependabot[bot]"


def matching_job(job):
    return job.get("status") == "queued" and "fabio-ci" in job.get("labels", [])


def repositories(api, owner):
    result = []
    page = 1
    while True:
        batch = api.request(f"/installation/repositories?per_page=100&page={page}")["repositories"]
        result.extend(repo for repo in batch if not repo["archived"] and repo["owner"]["login"].lower() == owner.lower())
        if len(batch) < 100:
            return result
        page += 1


def tick(api, config, repos):
    for repository in repos:
        repo = repository["full_name"]
        # Public projects must be individually reviewed before using local compute.
        # A runner can receive any matching queued job in its repository; checking
        # a single run's actor alone is not a security boundary.
        if not repository["private"] and repo not in config.get("reviewed_public_repositories", []):
            continue
        if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo):
            continue
        runs = api.request(f"/repos/{repo}/actions/runs?per_page=20")["workflow_runs"]
        for run in reversed(runs):
            if run["status"] not in {"queued", "in_progress"} or not trusted_run(run, repo, config["owner"]):
                continue
            jobs = api.request(f'/repos/{repo}/actions/runs/{run["id"]}/jobs?per_page=100')["jobs"]
            for job in jobs:
                if not matching_job(job):
                    continue
                name = f'fabio-ci-{job["id"]}-{int(time.time())}'
                jit = api.request(f"/repos/{repo}/actions/runners/generate-jitconfig", "POST", {
                    "name": name, "runner_group_id": 1,
                    "labels": ["self-hosted", "Linux", "X64", "fabio-ci"],
                    "work_folder": "_work",
                })
                print(f'Starting {repo} job {job["id"]}', flush=True)
                try:
                    subprocess.run(
                        ["/usr/local/lib/fabio-ci/run_vm.py"],
                        input=jit["encoded_jit_config"].encode(), check=True, timeout=3900,
                    )
                finally:
                    # Ephemeral runners normally unregister themselves after one job.
                    try:
                        api.request(f'/repos/{repo}/actions/runners/{jit["runner"]["id"]}', "DELETE")
                    except HTTPError as error:
                        if error.code != 404:
                            raise
                # Rotate repositories for fairness rather than draining the first.
                repos.append(repos.pop(repos.index(repository)))
                return


def main():
    config = json.loads(Path("/etc/fabio-ci/github-app.json").read_text())
    api = GitHub(config)
    repos = []
    refreshed = 0
    while True:
        try:
            if time.time() - refreshed > 900:
                repos = repositories(api, config["owner"])
                refreshed = time.time()
            tick(api, config, repos)
        except Exception as error:
            # Do not log response bodies, tokens or command input.
            print(f"CI controller error: {type(error).__name__}", flush=True)
        time.sleep(60)


if __name__ == "__main__":
    main()
