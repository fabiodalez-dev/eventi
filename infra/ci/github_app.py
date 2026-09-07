"""GitHub App authentication. Installation credentials never enter a job VM."""
import base64
import json
import subprocess
import time
import urllib.request


def b64(value):
    return base64.urlsafe_b64encode(value).decode().rstrip("=")


class GitHub:
    def __init__(self, config):
        self.config = config
        self.token = None
        self.expires = 0

    def jwt(self):
        now = int(time.time())
        payload = b64(json.dumps({"alg": "RS256", "typ": "JWT"}).encode()) + "." + b64(
            json.dumps({"iat": now - 60, "exp": now + 540, "iss": self.config["app_id"]}).encode()
        )
        signature = subprocess.run(
            ["openssl", "dgst", "-sha256", "-sign", self.config["private_key"]],
            input=payload.encode(), capture_output=True, check=True,
        ).stdout
        return payload + "." + b64(signature)

    def request(self, path, method="GET", data=None, token=None):
        if not path.startswith("/") or path.startswith("//"):
            raise ValueError("Only GitHub API paths are accepted")
        if token is None:
            if time.time() >= self.expires:
                response = self.request(
                    f'/app/installations/{int(self.config["installation_id"])}/access_tokens',
                    "POST", {}, self.jwt(),
                )
                self.token = response["token"]
                self.expires = time.time() + 3000
            token = self.token
        request = urllib.request.Request(
            "https://api.github.com" + path,
            data=None if data is None else json.dumps(data).encode(), method=method,
            headers={"Authorization": "Bearer " + token,
                     "Accept": "application/vnd.github+json",
                     "X-GitHub-Api-Version": "2022-11-28",
                     "User-Agent": "fabio-ci-controller"},
        )
        with urllib.request.urlopen(request, timeout=30) as response:
            body = response.read()
            return json.loads(body) if body else None
