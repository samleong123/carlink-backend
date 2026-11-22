#!/usr/bin/env python3
"""Validate OpenAPI and MQTT schemas/fixtures; check AsyncAPI local references."""
import copy
import json
from pathlib import Path

import yaml
from jsonschema import Draft202012Validator, FormatChecker
from openapi_spec_validator import validate

ROOT = Path(__file__).resolve().parent.parent
validate(json.loads((ROOT / 'contracts/openapi.json').read_text()))
validators = {}
for path in sorted((ROOT / 'contracts/json-schema/v1').glob('*.schema.json')):
    schema = json.loads(path.read_text())
    Draft202012Validator.check_schema(schema)
    validators[path.name.removesuffix('.schema.json')] = Draft202012Validator(schema, format_checker=FormatChecker())
for path in sorted((ROOT / 'contracts/fixtures/v1').glob('*.json')):
    value = json.loads(path.read_text())
    validators['pi_telemetry' if 'gateway' in value else value['message_type']].validate(value)

telemetry = json.loads((ROOT / 'contracts/fixtures/v1/telemetry.json').read_text())
invalid = copy.deepcopy(telemetry)
invalid['payload']['vehicle']['speed_kmh']['value'] = '52'
assert not validators['telemetry'].is_valid(invalid), 'Numeric strings must be rejected'
invalid = copy.deepcopy(telemetry)
invalid['payload']['vehicle']['speed_kmh']['value'] = None
assert not validators['telemetry'].is_valid(invalid), 'Valid speed cannot have a missing value'
invalid = copy.deepcopy(telemetry)
invalid['payload']['vehicle']['speed_kmh']['value'] = 0
validators['telemetry'].validate(invalid)
invalid = json.loads((ROOT / 'contracts/fixtures/v1/ingest_ack.json').read_text())
invalid['request_id'] = 'not-an-http-envelope'
assert not validators['ingest_ack'].is_valid(invalid), 'HTTP keys must not enter application ACKs'
pi = json.loads((ROOT / 'contracts/fixtures/v1/pi_telemetry.json').read_text())
validators['pi_telemetry'].validate(pi)
for path, value in [('event_id', ''), ('vehicle.speed_kmh', '52'), ('network.rssi_dbm', 999), ('timestamp', '2026-11-31T12:00:00Z')]:
    invalid = copy.deepcopy(pi)
    node = invalid
    parts = path.split('.')
    for part in parts[:-1]:
        node = node[part]
    node[parts[-1]] = value
    assert not validators['pi_telemetry'].is_valid(invalid), path


def check_refs(value, path):
    if isinstance(value, dict):
        if '$ref' in value:
            file, _, pointer = value['$ref'].partition('#')
            target = path.parent / file if file else path
            document = yaml.safe_load(target.read_text())
            for part in pointer.lstrip('/').split('/') if pointer else []:
                document = document[part.replace('~1', '/').replace('~0', '~')]
        for child in value.values():
            check_refs(child, path)
    elif isinstance(value, list):
        for child in value:
            check_refs(child, path)


async_path = ROOT / 'contracts/asyncapi.yaml'
async_spec = yaml.safe_load(async_path.read_text())
assert async_spec['asyncapi'] == '2.6.0'
check_refs(async_spec, async_path)
print('OpenAPI valid; MQTT schemas, fixtures and negative checks valid; AsyncAPI YAML/local references checked (not full AsyncAPI spec validation).')
