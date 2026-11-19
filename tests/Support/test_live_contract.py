"""Validate shareable LIVE fixtures against the officially generated OpenAPI."""
import copy
import json
import unittest
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker, RefResolver, ValidationError

ROOT = Path(__file__).resolve().parents[2]


class LiveContractFixturesTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.spec = json.loads((ROOT / "contracts/openapi.json").read_text())
        cls.batch = json.loads((ROOT / "tests/Fixtures/live-v1.batch.json").read_text())
        operation = cls.spec["paths"]["/api/v1/live/ingest/batches"]["post"]
        cls.request = Draft202012Validator(
            operation["requestBody"]["content"]["application/json"]["schema"],
            resolver=RefResolver.from_schema(cls.spec),
            format_checker=FormatChecker(),
        )
        cls.response = Draft202012Validator(
            operation["responses"]["200"]["content"]["application/json"]["schema"],
            resolver=RefResolver.from_schema(cls.spec),
            format_checker=FormatChecker(),
        )

    def test_all_twelve_normalized_fixtures_match_the_generated_request(self):
        self.request.validate(self.batch)
        self.assertEqual(len({event["eventType"] for event in self.batch["events"]}), 12)

    def test_acknowledgment_has_typed_status_and_error_map(self):
        self.response.validate({"batchId": self.batch["batchId"], "results": [
            {"eventId": event["eventId"], "status": "accepted", "code": None,
             "canonicalEventId": event["eventId"], "errors": {}}
            for event in self.batch["events"]
        ]})
        with self.assertRaises(ValidationError):
            self.response.validate({"batchId": self.batch["batchId"], "results": [
                {"eventId": None, "status": "accepted", "code": None,
                 "canonicalEventId": None, "errors": []}
            ]})

    def test_event_payload_types_and_server_fields_are_not_unconstrained(self):
        for field, value in [("schemaVersion", 2), ("businessId", "forged"),
                             ("observedAt", "2026-11-18 12:00:00"),
                             ("payload", {"rawBinary": "unfiltered"})]:
            with self.subTest(field=field):
                batch = copy.deepcopy(self.batch)
                batch["events"][1][field] = value
                with self.assertRaises(ValidationError):
                    self.request.validate(batch)


if __name__ == "__main__":
    unittest.main()
