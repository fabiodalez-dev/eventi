#!/bin/sh
# Forced SSH command: no arguments or caller-supplied code is evaluated.
set -eu
exec sudo -n /usr/bin/systemctl start fabio-eventi-mirror.service
