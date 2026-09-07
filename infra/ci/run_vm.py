#!/usr/bin/python3
"""Execute a single JIT runner in a fresh overlay. No shared writable cache."""
import fcntl
import os
import pwd
import re
import subprocess
import sys
import time
import uuid
from pathlib import Path

ROOT = Path("/var/lib/fabio-ci")
SSH = ["ssh", "-p", "22220", "-i", "/etc/fabio-ci/vm_ed25519",
       "-o", "UserKnownHostsFile=/etc/fabio-ci/known_hosts",
       "-o", "StrictHostKeyChecking=yes", "-o", "BatchMode=yes",
       "-o", "ConnectTimeout=5", "-o", "ServerAliveInterval=15",
       "-o", "ServerAliveCountMax=3", "runner@127.0.0.1"]


def main():
    jit = sys.stdin.buffer.read(1024 * 1024 + 1)
    if not jit or len(jit) > 1024 * 1024 or not re.fullmatch(rb"[A-Za-z0-9+/=\r\n]+", jit):
        raise ValueError("Invalid JIT payload")
    os.umask(0o077)
    lock = open("/run/lock/fabio-ci.lock", "w")
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    subprocess.run(["nft", "list", "table", "inet", "fabio_ci"], check=True, stdout=subprocess.DEVNULL)
    if subprocess.run(["systemctl", "is-active", "--quiet", "fabio-ci-build"]).returncode == 0:
        raise RuntimeError("Image provisioning is still running")
    base = ROOT / "images/runner-base.qcow2"
    if not base.is_file():
        raise RuntimeError("Verified base image missing")
    directory = ROOT / "jobs" / uuid.uuid4().hex
    directory.mkdir(mode=0o700)
    account = pwd.getpwnam("fabio-ci")
    os.chown(directory, account.pw_uid, account.pw_gid)
    disk = directory / "disk.qcow2"
    serial = directory / "serial.log"
    unit = "fabio-ci-job-" + directory.name
    try:
        subprocess.run(["runuser", "-u", "fabio-ci", "--", "qemu-img", "create", "-f", "qcow2", "-F", "qcow2", "-b", str(base), str(disk)], check=True)
        subprocess.run([
            "systemd-run", "--quiet", "--collect", "--unit=" + unit,
            "--property=User=fabio-ci", "--property=Group=fabio-ci", "--property=SupplementaryGroups=kvm",
            "--property=NoNewPrivileges=yes", "--property=ProtectSystem=strict", "--property=ProtectHome=yes",
            "--property=PrivateTmp=yes", "--property=DevicePolicy=closed", "--property=DeviceAllow=/dev/kvm rw",
            "--property=ReadWritePaths=" + str(directory), "--property=UMask=0077",
            "--property=CPUQuota=400%", "--property=MemoryMax=10G", "--property=RuntimeMaxSec=8000",
            "qemu-system-x86_64", "-enable-kvm", "-cpu", "host", "-smp", "4", "-m", "8192",
            "-drive", f"file={disk},if=virtio,format=qcow2",
            "-netdev", "user,id=net0,ipv6=off,hostfwd=tcp:127.0.0.1:22220-:22",
            "-device", "virtio-net-pci,netdev=net0", "-display", "none", "-serial", "file:" + str(serial), "-monitor", "none",
        ], check=True)
        for _ in range(60):
            if subprocess.run(SSH + ["true"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
                break
            time.sleep(2)
        else:
            raise RuntimeError("Guest boot failed")
        # Only the one-job token crosses the VM boundary, through encrypted stdin.
        command = (
            'umask 077; token_file=$(mktemp); trap \'rm -f "$token_file"\' EXIT; '
            'cat > "$token_file"; cd /opt/actions-runner; '
            'set -a; . /etc/profile.d/fabio-ci.sh; set +a; '
            './run.sh --jitconfig "$(cat "$token_file")"'
        )
        # Pinakes browser regression jobs allow 120 minutes, plus runner cleanup.
        subprocess.run(SSH + [command], input=jit, check=True, timeout=7800)
    finally:
        subprocess.run(["systemctl", "stop", unit], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        # The exact generated disposable disk is removed; no recursive broad delete.
        disk.unlink(missing_ok=True)
        serial.unlink(missing_ok=True)
        directory.rmdir()


if __name__ == "__main__":
    main()
