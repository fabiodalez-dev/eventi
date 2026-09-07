#!/bin/bash
# Trusted image provisioning, before any project or CI credential is introduced.
set -euo pipefail
test "$(id -u)" = 0
test -f /var/lib/fabio-ci-provisioned
cd /opt/actions-runner
test "$(sha256sum /tmp/actions-runner.tar.gz | cut -d ' ' -f 1)" = 70920811a4f8ad4328818682bca5c6469c1c942fab52448868071d0063816613
tar -xzf /tmp/actions-runner.tar.gz
chown -R runner:runner /opt/actions-runner
./bin/installdependencies.sh
curl --fail --silent --show-error --location -o /tmp/android-tools.zip https://dl.google.com/android/repository/commandlinetools-linux-15859902_latest.zip
test "$(sha256sum /tmp/android-tools.zip | cut -d ' ' -f 1)" = 4e4c464f145a7512b57d088ac6c278c03c9eea610886b35a5e0804e74eedf583
install -d /opt/android-sdk/cmdline-tools
unzip -q /tmp/android-tools.zip -d /opt/android-sdk/cmdline-tools
mv /opt/android-sdk/cmdline-tools/cmdline-tools /opt/android-sdk/cmdline-tools/latest
chown -R runner:runner /opt/android-sdk
export ANDROID_HOME=/opt/android-sdk
export JAVA_HOME=/usr/lib/jvm/java-21-openjdk-amd64
# License acceptance is a separate operator step, never silently bypassed.
echo 'Runner and Android command-line tools installed. SDK licenses/platforms still need provisioning.'
