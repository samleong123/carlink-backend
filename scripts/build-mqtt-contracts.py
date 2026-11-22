#!/usr/bin/env python3
"""Build the implemented MQTT subset; fixtures are copied from the authoritative proposal."""
import copy
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / 'contracts/json-schema/v1'
FIXTURES = ROOT / 'contracts/fixtures/v1'
QUALITIES = ['valid', 'estimated', 'unsupported', 'unavailable', 'stale', 'ambiguous', 'invalid']
UUID = {'type': 'string', 'format': 'uuid'}
TIME = {'type': 'string', 'format': 'date-time', 'pattern': r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$'}
METRICS = {
    'speed_kmh': (0, 250), 'engine_rpm': (0, 30000), 'coolant_temp_c': (-60, 250),
    'engine_load_pct': (0, 100), 'throttle_pct': (0, 100), 'intake_temp_c': (-60, 250),
    'maf_gps': (0, 2000), 'fuel_rate_lph': (0, 1000), 'fuel_level_pct': (0, 100),
    'odometer_km': (0, 1000000000), 'engine_runtime_s': (0, 100000000000), 'ecu_voltage_v': (0, 100),
}


def measurement(value):
    return {
        'type': 'object', 'required': ['value', 'quality'],
        'properties': {'value': value, 'quality': {'enum': QUALITIES}, 'observed_at': {}, 'sample_id': {}, 'observed_uptime_ms': {}},
        'allOf': [
            {'if': {'properties': {'quality': {'enum': ['unsupported', 'unavailable']}}}, 'then': {'properties': {'value': {'type': 'null'}}}},
            {'if': {'properties': {'quality': {'enum': ['valid', 'estimated']}}}, 'then': {'properties': {'value': {'not': {'type': 'null'}}}}},
            {'if': {'properties': {'value': {'not': {'type': 'null'}}}}, 'then': {
                'required': ['observed_at', 'sample_id', 'observed_uptime_ms'],
                'properties': {'observed_at': TIME, 'sample_id': UUID, 'observed_uptime_ms': {'type': 'integer', 'minimum': 0}},
            }},
        ],
    }


vehicle = {key: measurement({'type': ['number', 'null'], 'minimum': lo, 'maximum': hi}) for key, (lo, hi) in METRICS.items()}
vehicle['mil_on'] = measurement({'type': ['boolean', 'null']})
vehicle['engine_state'] = measurement({'enum': ['running', 'off', 'unknown', None]})
gps = {
    'type': 'object', 'required': ['fix_valid'],
    'properties': {'fix_valid': {'type': 'boolean'}, 'quality': {'enum': QUALITIES}},
    'allOf': [{'if': {'properties': {'fix_valid': {'const': True}}}, 'then': {
        'required': ['latitude', 'longitude', 'sample_id', 'observed_at', 'observed_uptime_ms'],
        'properties': {
            'latitude': {'type': 'number', 'minimum': -90, 'maximum': 90},
            'longitude': {'type': 'number', 'minimum': -180, 'maximum': 180},
            'heading_deg': {'type': ['number', 'null'], 'minimum': 0, 'maximum': 359.999999},
            'speed_kmh': {'type': ['number', 'null'], 'minimum': 0, 'maximum': 250},
            'horizontal_accuracy_m': {'type': ['number', 'null'], 'minimum': 0, 'maximum': 100000},
            'sample_id': UUID, 'observed_at': TIME, 'observed_uptime_ms': {'type': 'integer', 'minimum': 0},
        },
    }}],
}
telemetry = {
    '$schema': 'https://json-schema.org/draft/2020-12/schema',
    'title': 'CarLink v1 implemented telemetry ingress subset',
    'description': 'Extensions are retained but not semantically validated or projected. Runtime additionally checks broker identity, installation intervals, sample uptime <= packet uptime, finite numbers, a seven-day replay horizon and five-minute future bound.',
    'type': 'object',
    'required': ['schema_version', 'message_type', 'event_id', 'device_id', 'installation_id', 'boot_id', 'sequence', 'occurred_at', 'sent_at', 'uptime_ms', 'edge_version', 'config_version', 'clock', 'payload'],
    'properties': {
        'schema_version': {'const': '1.0'}, 'message_type': {'const': 'telemetry'},
        **{key: UUID for key in ['event_id', 'device_id', 'installation_id', 'boot_id']},
        **{key: {'type': 'integer', 'minimum': 0} for key in ['sequence', 'uptime_ms', 'config_version']},
        'occurred_at': TIME, 'sent_at': TIME, 'edge_version': {'type': 'string', 'minLength': 1, 'maxLength': 100},
        'clock': {'type': 'object', 'properties': {'source': {'type': ['string', 'null'], 'maxLength': 100}, 'synchronized': {'type': 'boolean'}, 'estimated_error_ms': {'type': ['number', 'null'], 'minimum': 0}}},
        'payload': {'type': 'object', 'properties': {'vehicle': {'type': 'object', 'properties': vehicle}, 'gps': gps}},
    },
}
ack = {
    '$schema': 'https://json-schema.org/draft/2020-12/schema',
    'title': 'CarLink v1 implemented telemetry application ACK',
    'description': 'Only accepted and duplicate are produced by this pass. No HTTP response wrapper belongs in MQTT.',
    'type': 'object', 'additionalProperties': False,
    'required': ['schema_version', 'message_type', 'device_id', 'acknowledged_at', 'results'],
    'properties': {
        'schema_version': {'const': '1.0'}, 'message_type': {'const': 'ingest_ack'}, 'device_id': UUID, 'acknowledged_at': TIME,
        'results': {'type': 'array', 'minItems': 1, 'maxItems': 1, 'items': {
            'type': 'object', 'additionalProperties': False, 'required': ['event_id', 'status'],
            'properties': {'event_id': UUID, 'status': {'enum': ['accepted', 'duplicate']}},
        }},
    },
}


def write(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, allow_nan=False) + '\n')


for name, schema in [('telemetry', telemetry), ('ingest_ack', ack)]:
    write(OUTPUT / f'{name}.schema.json', schema)
    blocks = re.findall(r'```json\s*\n(.*?)\n```', (ROOT / 'BACKEND_DETAIL_15Nov2026.md').read_text(), re.S)
    fixture = next(json.loads(block) for block in blocks if f'"message_type": "{name}"' in block)
    write(FIXTURES / f'{name}.json', fixture)

duplicate = copy.deepcopy(json.loads((FIXTURES / 'ingest_ack.json').read_text()))
duplicate['results'][0]['status'] = 'duplicate'
write(FIXTURES / 'ingest_ack_duplicate.json', duplicate)
