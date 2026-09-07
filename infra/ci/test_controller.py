import unittest
from controller import matching_job, trusted_run


class PolicyTests(unittest.TestCase):
    def run_data(self, event="push", actor="fabiodalez-dev", head="fabiodalez-dev/eventi"):
        return {"event": event, "actor": {"login": actor}, "head_repository": {"full_name": head}}

    def test_owner_runs(self):
        self.assertTrue(trusted_run(self.run_data(), "fabiodalez-dev/eventi", "fabiodalez-dev"))

    def test_dependabot(self):
        self.assertTrue(trusted_run(self.run_data("pull_request", "dependabot[bot]"), "fabiodalez-dev/eventi", "fabiodalez-dev"))

    def test_untrusted_actor(self):
        self.assertFalse(trusted_run(self.run_data(actor="outsider"), "fabiodalez-dev/eventi", "fabiodalez-dev"))

    def test_fork(self):
        self.assertFalse(trusted_run(self.run_data(head="outsider/eventi"), "fabiodalez-dev/eventi", "fabiodalez-dev"))

    def test_privileged_pr(self):
        self.assertFalse(trusted_run(self.run_data(event="pull_request_target"), "fabiodalez-dev/eventi", "fabiodalez-dev"))

    def test_label_required(self):
        self.assertFalse(matching_job({"status": "queued", "labels": ["ubuntu-latest"]}))
        self.assertTrue(matching_job({"status": "queued", "labels": ["fabio-ci"]}))

    def test_completed_is_not_repeated(self):
        self.assertFalse(matching_job({"status": "completed", "labels": ["fabio-ci"]}))


if __name__ == "__main__":
    unittest.main()
