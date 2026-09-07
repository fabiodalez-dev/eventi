#!/bin/bash
set -euo pipefail
test "$(id -u)" = 0
test -x /opt/actions-runner/bin/Runner.Listener
test -f /opt/android-sdk/platforms/android-36/android.jar
docker info >/dev/null
test -z "$(find /opt/actions-runner -maxdepth 1 -name '.credentials*' -print)"
# Preserve the verified SSH host key across disposable clones.
touch /etc/cloud/cloud-init.disabled
sync
shutdown -h now
