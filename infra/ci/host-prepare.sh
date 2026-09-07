#!/bin/bash
# Run as root on the CI host after reviewing paths and package simulation.
set -euo pipefail
test "$(id -u)" = 0
root=/var/lib/fabio-ci
source_dir=$(cd "$(dirname "$0")" && pwd)
getent passwd fabio-ci >/dev/null || useradd --system --home-dir "$root" --shell /usr/sbin/nologin --groups kvm fabio-ci
install -d -m 0750 -o fabio-ci -g fabio-ci "$root" "$root/images" "$root/build" "$root/jobs"
install -d -m 0700 /etc/fabio-ci
if [ ! -f /etc/fabio-ci/vm_ed25519 ]; then
    ssh-keygen -q -t ed25519 -N '' -C fabio-ci-management -f /etc/fabio-ci/vm_ed25519
fi
cd "$root/images"
expected=$(awk '$2 == "*ubuntu-24.04-server-cloudimg-amd64.img" {print $1}' SHA256SUMS)
test -n "$expected"
actual=$(sha256sum ubuntu-24.04.img | cut -d ' ' -f 1)
test "$expected" = "$actual"
chown fabio-ci:fabio-ci ubuntu-24.04.img
if [ ! -f "$root/build/base.qcow2" ]; then
    runuser -u fabio-ci -- qemu-img create -f qcow2 -F qcow2 -b "$root/images/ubuntu-24.04.img" "$root/build/base.qcow2" 80G
fi
sed "s|__CI_SSH_PUBLIC_KEY__|$(cat /etc/fabio-ci/vm_ed25519.pub)|" "$source_dir/cloud-init.yaml" > "$root/build/user-data"
cloud-localds "$root/build/seed.img" "$root/build/user-data"
chown fabio-ci:fabio-ci "$root/build/seed.img"
