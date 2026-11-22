import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildPiTelemetryPacket,
  encodeConnect,
  encodePuback,
  encodePublish,
  encodeRemainingLength,
  encodeSubscribe,
  normalizeCommissioningConfig,
  normalizePlate,
  tryDecodePacket,
} from '../../scripts/mock-pi.mjs';

const deviceId = '01a1ef0f-497d-7649-8cce-02411a0a80c0';
const validConfig = {
  device_id: deviceId,
  organization_id: '11a1ef0f-497d-7649-8cce-02411a0a80c1',
  obd_uuid: '22a1ef0f-497d-7649-8cce-02411a0a80c2',
  obd_bluetooth_mac: 'aa:bb:cc:dd:ee:ff',
  api_url: 'https://carlink-be.samsam123.name.my/',
  broker_url: 'wss://carlink-iot.samsam123.name.my/ws',
  broker_username: `d-${deviceId.replace(/-/g, '')}`,
  auth_token: 'a'.repeat(64),
};

test('normalizeCommissioningConfig unwraps raw, configuration, and data envelopes', () => {
  const fromRaw = normalizeCommissioningConfig(validConfig);
  assert.equal(fromRaw.obd_bluetooth_mac, 'AA:BB:CC:DD:EE:FF');
  assert.equal(fromRaw.api_url, 'https://carlink-be.samsam123.name.my');

  const fromWrapped = normalizeCommissioningConfig(JSON.stringify({ configuration: validConfig }));
  assert.deepEqual(fromWrapped, fromRaw);

  const fromEnvelope = normalizeCommissioningConfig({ data: { configuration: validConfig } });
  assert.deepEqual(fromEnvelope, fromRaw);

  assert.throws(() => normalizeCommissioningConfig({ ...validConfig, broker_username: 'wrong' }));
  assert.throws(() => normalizeCommissioningConfig({ ...validConfig, auth_token: 'short' }));
  assert.throws(() => normalizeCommissioningConfig({ ...validConfig, broker_url: 'ws://user:pass@host/ws' }));
});

test('normalizePlate normalizes Malaysian/alphanumeric plates cleanly', () => {
  assert.equal(normalizePlate(' vfr-7550 '), 'VFR7550');
  assert.throws(() => normalizePlate('---'));
});

test('MQTT codec handles multi-byte remaining length, chunked reads, and QoS 1 publish roundtrip', () => {
  assert.deepEqual([...encodeRemainingLength(127)], [127]);
  assert.deepEqual([...encodeRemainingLength(128)], [0x80, 0x01]);

  const conn = encodeConnect({ clientId: deviceId, username: validConfig.broker_username, password: validConfig.auth_token });
  assert.equal(conn[0], 0x10);
  // 2-byte varint remaining length because clientId+username+token > 127 bytes
  assert.equal(conn.subarray(3, 9).toString('utf8'), '\x00\x04MQTT');

  const sub = encodeSubscribe(7, [{ topic: 'fleet/v1/org/dev/down/ingest_ack', qos: 1 }]);
  assert.equal(sub[0], 0x82);

  const packet = buildPiTelemetryPacket({ serial: `pi-${deviceId}`, plate: 'VFR7550', tick: 3, intervalSeconds: 5 });
  const wireJson = JSON.stringify(packet);
  assert.ok(Buffer.byteLength(wireJson) > 500);

  const pub = encodePublish({
    topic: `fleet/v1/${validConfig.organization_id}/${deviceId}/up/telemetry`,
    payload: wireJson,
    packetId: 42,
    qos: 1,
  });

  // Split across two chunks and append a CONNACK + PUBACK to test framing
  const connack = Buffer.from([0x20, 0x02, 0x00, 0x00]);
  const puback = encodePuback(42);
  const fullStream = Buffer.concat([connack, pub, puback]);

  assert.equal(tryDecodePacket(fullStream.subarray(0, 1)), null);
  assert.equal(tryDecodePacket(pub.subarray(0, 50)), null);

  let buf = fullStream;
  const decoded = [];
  while (buf.length > 0) {
    const res = tryDecodePacket(buf);
    assert.ok(res);
    decoded.push(res.packet);
    buf = res.rest;
  }
  assert.equal(decoded[0].type, 'CONNACK');
  assert.equal(decoded[0].returnCode, 0);
  assert.equal(decoded[1].type, 'PUBLISH');
  assert.equal(decoded[1].qos, 1);
  assert.equal(decoded[1].packetId, 42);
  assert.deepEqual(JSON.parse(decoded[1].payload), packet);
  assert.equal(decoded[2].type, 'PUBACK');
  assert.equal(decoded[2].packetId, 42);
});

test('buildPiTelemetryPacket satisfies CarLink PiTelemetryValidator rules', () => {
  const pkt = buildPiTelemetryPacket({
    serial: `pi-${deviceId}`,
    plate: 'VFR7550',
    tick: 3,
    intervalSeconds: 5,
    harsh: true,
    now: new Date('2026-11-18T14:50:00.123Z'),
  });
  assert.equal(pkt.message_type, 'telemetry');
  assert.equal(pkt.timestamp, '2026-11-18T14:50:00Z');
  assert.equal(pkt.gps.timestamp, '2026-11-18T14:50:00Z');
  assert.equal(pkt.gateway.device_id, `pi-${deviceId}`);
  assert.equal(pkt.gateway.vehicle_id, 'VFR7550');
  assert.equal(pkt.vehicle.has_dtc_fault, false);
  assert.equal('dtc_codes' in pkt.vehicle, false);
  assert.equal(pkt.driving_behaviour.harsh_braking, true);
  assert.equal(pkt.network.operator, 'CelcomDigi');
  assert.equal(pkt.network.network_type, 'LTE');
});
