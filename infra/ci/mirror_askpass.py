#!/usr/bin/python3
"""Credentials only for the two explicitly configured HTTPS hosts."""
import os
import sys
import re
from urllib.parse import urlsplit

prompt = sys.argv[1] if len(sys.argv) > 1 else ''
match = re.search(r"'(https://[^']+)'", prompt)
host = urlsplit(match.group(1)).netloc if match else ''
if host in {'github.com', 'x-access-token@github.com'}:
    value = 'x-access-token' if prompt.startswith('Username') else os.environ['MIRROR_GITHUB_TOKEN']
elif host in {'gitlab.com', 'oauth2@gitlab.com'}:
    value = 'oauth2' if prompt.startswith('Username') else os.environ['MIRROR_GITLAB_TOKEN']
else:
    sys.exit(1)
print(value)
