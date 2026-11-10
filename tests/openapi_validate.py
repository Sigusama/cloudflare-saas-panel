# SPDX-License-Identifier: AGPL-3.0-only
"""Validate the published API description and optional isolated HTTP captures."""
import json
import re
import sys
from pathlib import Path

from jsonschema import Draft202012Validator
from openapi_spec_validator import validate_spec

root = Path(__file__).resolve().parent.parent
spec = json.loads((root / "public/openapi.json").read_text(encoding="utf-8"))
validate_spec(spec)
assert spec["info"]["version"] == (root / "VERSION").read_text().strip()
operations = {}
for path, methods in spec["paths"].items():
    for method, operation in methods.items():
        assert method in {"get", "post", "patch", "delete"}
        assert path == "/health" or path.startswith("/api/v1/")
        assert operation["operationId"] not in [value[1]["operationId"] for value in operations.values()]
        operations[(method.upper(), path)] = (re.compile("^" + re.sub(r"\{[^}]+\}", r"[0-9]+", path) + "$"), operation)
print(f"OpenAPI {spec['openapi']} valid: {len(operations)} public operations, version {spec['info']['version']}.")

if len(sys.argv) > 1:
    seen = set()
    count = 0
    root_schema = {"components": spec["components"]}

    def resolve(value):
        while "$ref" in value:
            target = spec
            for part in value["$ref"].removeprefix("#/").split("/"):
                target = target[part]
            value = target
        return value

    for line in Path(sys.argv[1]).read_text(encoding="utf-8").splitlines():
        record = json.loads(line)
        path = record["path"].split("?", 1)[0]
        candidates = [(key, value) for key, value in operations.items() if key[0] == record["method"] and value[0].fullmatch(path)]
        if not candidates:  # Intentional wrong-method/unknown-route checks.
            Draft202012Validator({**root_schema, "$ref": "#/components/schemas/ErrorEnvelope"}).validate(record["response"])
            continue
        key, (_, operation) = candidates[0]
        response = resolve(operation["responses"][str(record["status"])])
        schema = response["content"]["application/json"]["schema"]
        try:
            Draft202012Validator({**root_schema, **schema}).validate(record["response"])
            if 200 <= record["status"] < 300:
                seen.add(key)
                if "requestBody" in operation:
                    request_schema = operation["requestBody"]["content"]["application/json"]["schema"]
                    Draft202012Validator({**root_schema, **request_schema}).validate(record["request"])
        except Exception as error:
            raise AssertionError(f"API contract mismatch: {record['method']} {path} HTTP {record['status']}: {error}") from error
        count += 1
    missing = set(operations) - seen
    assert not missing, f"Public operations without a successful HTTP check: {sorted(missing)}"
    print(f"{count} HTTP request/response pairs match OpenAPI; all {len(operations)} operations succeeded.")
