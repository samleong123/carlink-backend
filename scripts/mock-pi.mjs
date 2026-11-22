#!/usr/bin/env node
/**
 * Zero-dependency CarLink Mock Raspberry Pi (Node.js >= 22 ESM).
 *
 * Accepts the CarLink commissioning JSON (`device_id`, `organization_id`, `obd_uuid`,
 * `obd_bluetooth_mac`, `api_url`, `broker_url`, `broker_username`, `auth_token`),
 * connects over WSS/MQTTS with MQTT 3.1.1 QoS 1, publishes valid `pi_snapshot_v1`
 * telemetry packets, logs `down/ingest_ack` & `down/results`, and optionally polls
 * signed edge commands (`configuration` updates / `extended_dtc`).
 */
import { createHmac, randomUUID, timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createInterface } from 'node:readline/promises';
import tls from 'node:tls';
import { pathToFileURL } from 'node:url';

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const MAC_RE = /^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/;
const TOKEN_RE = /^[0-9a-f]{64}$/i;

export function normalizeCommissioningConfig(rawInput) {
  const parsed = typeof rawInput === 'string' ? JSON.parse(rawInput) : rawInput;
  const cfg = parsed?.data?.configuration ?? parsed?.configuration ?? parsed;
  if (!cfg || typeof cfg !== 'object' || Array.isArray(cfg)) {
    throw new Error('Commissioning JSON must be an object.');
  }

  const required = [
    'device_id',
    'organization_id',
    'obd_uuid',
    'obd_bluetooth_mac',
    'api_url',
    'broker_url',
    'broker_username',
    'auth_token',
  ];
  for (const key of required) {
    if (typeof cfg[key] !== 'string' || cfg[key].trim() === '') {
      throw new Error(`Missing or invalid required field: ${key}`);
    }
  }

  const out = {
    device_id: cfg.device_id.trim().toLowerCase(),
    organization_id: cfg.organization_id.trim().toLowerCase(),
    obd_uuid: cfg.obd_uuid.trim().toLowerCase(),
    obd_bluetooth_mac: cfg.obd_bluetooth_mac.trim().toUpperCase(),
    api_url: cfg.api_url.trim().replace(/\/$/, ''),
    broker_url: cfg.broker_url.trim(),
    broker_username: cfg.broker_username.trim(),
    auth_token: cfg.auth_token.trim().toLowerCase(),
  };

  for (const k of ['device_id', 'organization_id', 'obd_uuid']) {
    if (!UUID_RE.test(out[k])) {
      throw new Error(`Field ${k} must be a valid UUID.`);
    }
  }
  if (!MAC_RE.test(out.obd_bluetooth_mac)) {
    throw new Error('Field obd_bluetooth_mac must look like AA:BB:CC:DD:EE:FF.');
  }
  if (!TOKEN_RE.test(out.auth_token)) {
    throw new Error('Field auth_token must be a 64-character hex token.');
  }

  const expectedUser = 'd-' + out.device_id.replace(/-/g, '');
  if (out.broker_username !== expectedUser) {
    throw new Error(`Field broker_username (${out.broker_username}) must match ${expectedUser}.`);
  }

  const apiUrl = new URL(out.api_url);
  if (!['https:', 'http:'].includes(apiUrl.protocol) || apiUrl.username || apiUrl.password) {
    throw new Error('Field api_url must be a valid HTTP/HTTPS URL without embedded credentials.');
  }

  const brokerUrl = new URL(out.broker_url);
  if (!['wss:', 'ws:', 'mqtts:'].includes(brokerUrl.protocol) || brokerUrl.username || brokerUrl.password) {
    throw new Error('Field broker_url must use wss:// or mqtts:// without embedded credentials.');
  }

  return out;
}

export function normalizePlate(plate) {
  const cleaned = String(plate || '')
    .trim()
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '');
  if (!cleaned || cleaned.length > 32) {
    throw new Error('Vehicle plate must contain 1..32 alphanumeric characters (e.g. VFR7550).');
  }
  return cleaned;
}

// --- Minimal MQTT 3.1.1 binary codec ---

export function encodeRemainingLength(length) {
  const bytes = [];
  let n = length;
  do {
    let digit = n % 128;
    n = Math.floor(n / 128);
    if (n > 0) digit |= 0x80;
    bytes.push(digit);
  } while (n > 0);
  return Buffer.from(bytes);
}

function encodeUtf8Field(str) {
  const data = Buffer.from(str, 'utf8');
  const len = Buffer.allocUnsafe(2);
  len.writeUInt16BE(data.length, 0);
  return Buffer.concat([len, data]);
}

function wrapMqttPacket(headerByte, body) {
  return Buffer.concat([Buffer.from([headerByte]), encodeRemainingLength(body.length), body]);
}

export function encodeConnect({ clientId, username, password, keepAlive = 30 }) {
  const varHeader = Buffer.from([
    0x00, 0x04, 0x4d, 0x51, 0x54, 0x54, // "MQTT"
    0x04, // Protocol level 4 (3.1.1)
    0xc2, // CleanSession=1 | Username=1 | Password=1
    (keepAlive >> 8) & 0xff,
    keepAlive & 0xff,
  ]);
  const payload = Buffer.concat([
    encodeUtf8Field(clientId),
    encodeUtf8Field(username),
    encodeUtf8Field(password),
  ]);
  return wrapMqttPacket(0x10, Buffer.concat([varHeader, payload]));
}

export function encodeSubscribe(packetId, subscriptions) {
  const idBuf = Buffer.allocUnsafe(2);
  idBuf.writeUInt16BE(packetId, 0);
  const parts = [idBuf];
  for (const { topic, qos = 1 } of subscriptions) {
    parts.push(encodeUtf8Field(topic), Buffer.from([qos & 0x03]));
  }
  return wrapMqttPacket(0x82, Buffer.concat(parts));
}

export function encodePublish({ topic, payload, packetId = 1, qos = 1, retain = false }) {
  const header = 0x30 | ((qos & 0x03) << 1) | (retain ? 1 : 0);
  const parts = [encodeUtf8Field(topic)];
  if (qos > 0) {
    const idBuf = Buffer.allocUnsafe(2);
    idBuf.writeUInt16BE(packetId, 0);
    parts.push(idBuf);
  }
  parts.push(Buffer.isBuffer(payload) ? payload : Buffer.from(String(payload), 'utf8'));
  return wrapMqttPacket(header, Buffer.concat(parts));
}

export function encodePuback(packetId) {
  const buf = Buffer.allocUnsafe(4);
  buf[0] = 0x40;
  buf[1] = 0x02;
  buf.writeUInt16BE(packetId, 2);
  return buf;
}

export function encodePingreq() {
  return Buffer.from([0xc0, 0x00]);
}

export function encodeDisconnect() {
  return Buffer.from([0xe0, 0x00]);
}

export function tryDecodePacket(buffer) {
  if (buffer.length < 2) return null;
  let multiplier = 1;
  let value = 0;
  let pos = 1;
  let encodedByte = 0;
  do {
    if (pos >= buffer.length) return null;
    encodedByte = buffer[pos++];
    value += (encodedByte & 0x7f) * multiplier;
    if (multiplier > 128 * 128 * 128) {
      throw new Error('Malformed MQTT Remaining Length.');
    }
    multiplier *= 128;
  } while ((encodedByte & 0x80) !== 0);

  const totalLength = pos + value;
  if (buffer.length < totalLength) return null;

  const firstByte = buffer[0];
  const type = (firstByte >> 4) & 0x0f;
  const flags = firstByte & 0x0f;
  const body = buffer.subarray(pos, totalLength);
  const rest = buffer.subarray(totalLength);

  if (type === 2 && body.length >= 2) {
    return { packet: { type: 'CONNACK', sessionPresent: (body[0] & 0x01) === 1, returnCode: body[1] }, rest };
  }
  if (type === 4 && body.length >= 2) {
    return { packet: { type: 'PUBACK', packetId: body.readUInt16BE(0) }, rest };
  }
  if (type === 9 && body.length >= 2) {
    return {
      packet: {
        type: 'SUBACK',
        packetId: body.readUInt16BE(0),
        returnCodes: [...body.subarray(2)],
      },
      rest,
    };
  }
  if (type === 13) {
    return { packet: { type: 'PINGRESP' }, rest };
  }
  if (type === 3) {
    if (body.length < 2) throw new Error('Malformed MQTT PUBLISH packet.');
    let idx = 0;
    const topicLen = body.readUInt16BE(idx);
    idx += 2;
    if (body.length < idx + topicLen) throw new Error('Truncated MQTT PUBLISH topic.');
    const topic = body.subarray(idx, idx + topicLen).toString('utf8');
    idx += topicLen;
    const qos = (flags >> 1) & 0x03;
    let packetId = null;
    if (qos > 0) {
      if (body.length < idx + 2) throw new Error('Truncated MQTT PUBLISH packetId.');
      packetId = body.readUInt16BE(idx);
      idx += 2;
    }
    const payload = body.subarray(idx).toString('utf8');
    return { packet: { type: 'PUBLISH', qos, packetId, topic, payload }, rest };
  }

  return { packet: { type: `TYPE_${type}`, flags, body }, rest };
}

const CONNACK_ERRORS = {
  1: 'Unacceptable protocol version',
  2: 'Identifier rejected',
  3: 'Broker server unavailable',
  4: 'Bad broker_username or auth_token (ensure RabbitMQ user d-<uuid> exists with matching token)',
  5: 'Not authorized (check RabbitMQ vhost/permissions/topic-permissions for user)',
};

class MinimalMqttClient {
  constructor(urlString, { clientId, username, password, caPem = null, keepAlive = 30 }) {
    this.url = new URL(urlString);
    this.clientId = clientId;
    this.username = username;
    this.password = password;
    this.caPem = caPem;
    this.keepAlive = keepAlive;
    this.nextPacketId = 1;
    this.recvBuf = Buffer.alloc(0);
    this.sendRaw = null;
    this.closeRaw = null;
    this.pingTimer = null;
    this.handlers = { message: [], close: [], error: [] };
  }

  on(event, fn) {
    this.handlers[event]?.push(fn);
    return this;
  }

  emit(event, ...args) {
    for (const fn of this.handlers[event] || []) fn(...args);
  }

  allocPacketId() {
    const id = this.nextPacketId;
    this.nextPacketId = id >= 65535 ? 1 : id + 1;
    return id;
  }

  async connect() {
    let settleConnect;
    let rejectConnect;
    const connected = new Promise((res, rej) => {
      settleConnect = res;
      rejectConnect = rej;
    });

    const handleChunk = (chunk) => {
      this.recvBuf = Buffer.concat([this.recvBuf, chunk]);
      while (true) {
        let decoded;
        try {
          decoded = tryDecodePacket(this.recvBuf);
        } catch (err) {
          this.emit('error', err);
          return;
        }
        if (!decoded) break;
        this.recvBuf = decoded.rest;
        const { packet } = decoded;
        if (packet.type === 'CONNACK') {
          if (packet.returnCode === 0) {
            this.startKeepAlive();
            settleConnect();
          } else {
            const detail = CONNACK_ERRORS[packet.returnCode] || `CONNACK code ${packet.returnCode}`;
            rejectConnect(new Error(`MQTT authentication failed: ${detail}`));
          }
        } else if (packet.type === 'PUBLISH') {
          if (packet.qos === 1 && packet.packetId !== null) {
            this.sendRaw(encodePuback(packet.packetId));
          }
          this.emit('message', packet.topic, packet.payload);
        } else if (packet.type === 'PUBACK') {
          this.emit('puback', packet.packetId);
        } else if (packet.type === 'SUBACK') {
          if (packet.returnCodes.some((c) => c === 0x80)) {
            this.emit('error', new Error('Broker rejected topic subscription (ACL failure / 0x80).'));
          } else {
            this.emit('suback', packet);
          }
        }
      }
    };

    const connectPayload = encodeConnect({
      clientId: this.clientId,
      username: this.username,
      password: this.password,
      keepAlive: this.keepAlive,
    });

    if (this.url.protocol === 'wss:' || this.url.protocol === 'ws:') {
      if (typeof WebSocket !== 'function') {
        throw new Error('Global WebSocket not found. Use Node.js >= 22.');
      }
      const ws = new WebSocket(this.url.toString(), ['mqtt']);
      ws.binaryType = 'arraybuffer';
      this.sendRaw = (buf) => {
        if (ws.readyState === WebSocket.OPEN) ws.send(buf);
      };
      this.closeRaw = () => ws.close();

      ws.addEventListener('open', () => {
        this.sendRaw(connectPayload);
      });
      ws.addEventListener('message', (event) => {
        if (event.data instanceof ArrayBuffer) {
          handleChunk(Buffer.from(event.data));
        } else if (ArrayBuffer.isView(event.data)) {
          handleChunk(Buffer.from(event.data.buffer, event.data.byteOffset, event.data.byteLength));
        }
      });
      ws.addEventListener('error', (event) => {
        const msg = event?.message || event?.error?.message || 'WebSocket connection error';
        const err = new Error(msg);
        rejectConnect(err);
        this.emit('error', err);
      });
      ws.addEventListener('close', (event) => {
        this.stopKeepAlive();
        rejectConnect(new Error(`WebSocket closed before CONNACK (code ${event.code})`));
        this.emit('close', event.code);
      });
    } else if (this.url.protocol === 'mqtts:') {
      const socket = tls.connect({
        host: this.url.hostname,
        port: Number(this.url.port || 8883),
        servername: this.url.hostname,
        ...(this.caPem ? { ca: this.caPem } : {}),
      });
      this.sendRaw = (buf) => socket.write(buf);
      this.closeRaw = () => socket.end();
      socket.on('secureConnect', () => this.sendRaw(connectPayload));
      socket.on('data', handleChunk);
      socket.on('error', (err) => {
        rejectConnect(err);
        this.emit('error', err);
      });
      socket.on('close', () => {
        this.stopKeepAlive();
        rejectConnect(new Error('TLS socket closed before CONNACK'));
        this.emit('close');
      });
    }

    const timeout = setTimeout(() => rejectConnect(new Error('MQTT CONNACK timeout after 15s')), 15000);
    timeout.unref?.();
    try {
      await connected;
    } finally {
      clearTimeout(timeout);
    }
  }

  startKeepAlive() {
    this.stopKeepAlive();
    this.pingTimer = setInterval(() => {
      this.sendRaw?.(encodePingreq());
    }, Math.max(5000, Math.floor((this.keepAlive * 1000) / 2)));
    this.pingTimer.unref?.();
  }

  stopKeepAlive() {
    if (this.pingTimer) {
      clearInterval(this.pingTimer);
      this.pingTimer = null;
    }
  }

  subscribe(topics) {
    const packetId = this.allocPacketId();
    const subs = topics.map((topic) => ({ topic, qos: 1 }));
    this.sendRaw(encodeSubscribe(packetId, subs));
    return packetId;
  }

  publish(topic, payload) {
    const packetId = this.allocPacketId();
    this.sendRaw(encodePublish({ topic, payload, packetId, qos: 1, retain: false }));
    return packetId;
  }

  disconnect() {
    this.stopKeepAlive();
    try {
      this.sendRaw?.(encodeDisconnect());
      this.closeRaw?.();
    } catch {
      // Ignore shutdown errors
    }
  }
}

// --- Telemetry simulation matching PiTelemetryValidator.php & TelemetryInbox.php ---

export function buildPiTelemetryPacket({
  serial,
  plate,
  tick,
  intervalSeconds,
  baseLat = 3.139,
  baseLon = 101.6869,
  baseSpeed = 52,
  harsh = false,
  now = new Date(),
}) {
  // Whole-second UTC avoids TIMESTAMP(0) rounding drift in telemetry_inbox.occurred_at
  const stamp = now.toISOString().replace(/\.\d{3}Z$/, 'Z');
  const angle = tick * 0.18;
  const radius = 0.0045; // ~500m loop around KL
  const lat = Number((baseLat + Math.sin(angle) * radius).toFixed(6));
  const lon = Number((baseLon + Math.cos(angle) * radius).toFixed(6));
  const speed = Number(Math.max(0, Math.min(250, baseSpeed + Math.sin(angle * 2) * 8)).toFixed(1));
  const rpm = Math.round(speed > 1 ? 950 + speed * 32 : 760);
  const maf = Number((3.2 + (rpm / 1000) * 4.1).toFixed(2));
  const rateLph = Number(((maf * 3600) / (14.7 * 745)).toFixed(3));
  const accelY = harsh && tick % 4 === 3 ? -6.4 : Number((Math.cos(angle) * 0.7).toFixed(2));

  return {
    message_type: 'telemetry',
    event_id: randomUUID(),
    timestamp: stamp,
    gateway: {
      device_id: serial,
      vehicle_id: plate,
      edge_version: 'mock-pi-1.0.0',
    },
    network: {
      modem_model: 'SIM7600G-H',
      imei: null,
      imsi: null,
      registered_plmn: '50212',
      operator: 'CelcomDigi',
      network_type: 'LTE',
      registration_status: 'registered',
      roaming: false,
      lte_band: 3,
      earfcn: 1650,
      cell_id: '12345678',
      pci: 123,
      tac: '3456',
      rssi_dbm: -68,
      rsrp_dbm: -95,
      rsrq_db: -11,
      sinr_db: 12.5,
      connected: true,
    },
    gps: {
      latitude: lat,
      longitude: lon,
      altitude_m: 45.2,
      speed_kmh: speed,
      heading_deg: Number((((angle * 180) / Math.PI + 360) % 360).toFixed(1)),
      satellites: 12,
      hdop: 0.9,
      fix_valid: true,
      timestamp: stamp,
    },
    vehicle: {
      obd_connected: true,
      engine_running: true,
      engine_rpm: rpm,
      speed_kmh: speed,
      coolant_temp_c: 88,
      engine_load_pct: Number((22 + speed * 0.4).toFixed(1)),
      throttle_pct: Number((12 + speed * 0.25).toFixed(1)),
      intake_temp_c: 34,
      maf_gps: maf,
      fuel_rate_lph: rateLph,
      fuel_level_pct: Number(Math.max(10, 78 - tick * 0.05).toFixed(1)),
      odometer_km: null,
      engine_runtime_s: 3600 + tick * intervalSeconds,
      ecu_voltage_v: 13.8,
      mil_on: false,
      has_dtc_fault: false,
      calculated_fuel_consumption: {
        rateLph,
        rateSource: 'maf',
        instantL100km: speed > 5 ? Number(((rateLph / speed) * 100).toFixed(2)) : null,
        totalLitres: null,
        distanceKm: null,
        averageL100km: null,
        averageKmPerL: null,
      },
    },
    driving_behaviour: {
      sensor: 'MPU6050',
      acceleration: { x_mps2: 0.15, y_mps2: accelY, z_mps2: 9.8 },
      gyroscope: { x_dps: 0.4, y_dps: 0.2, z_dps: 1.1 },
      threshold: { harsh_braking: null, rapid_acceleration: null, sharp_cornering: null },
      harsh_braking: accelY <= -5.0,
      rapid_acceleration: false,
      sharp_cornering: false,
    },
    device_health: {
      cpu_temp_c: 48.2,
      cpu_usage_pct: 21.5,
      memory_usage_pct: 41.0,
      disk_usage_pct: 32.0,
      uptime_s: tick * intervalSeconds,
      ble_obd_connected: true,
      lte_connected: true,
      pending_records: 0,
      last_sync_at: stamp,
    },
  };
}

// --- Edge signed command polling (matches deploy/pi/command-runner.mjs) ---

function verifySignedCommand(item, config) {
  if (typeof item?.wire_payload !== 'string' || !/^[0-9a-f]{64}$/i.test(item?.signature || '')) {
    return null;
  }
  const expected = createHmac('sha256', config.auth_token).update(item.wire_payload, 'utf8').digest();
  const actual = Buffer.from(item.signature, 'hex');
  if (expected.length !== actual.length || !timingSafeEqual(expected, actual)) return null;
  const cmd = JSON.parse(item.wire_payload);
  if (cmd.device_id !== config.device_id || cmd.organization_id !== config.organization_id) return null;
  if (Date.parse(cmd.expires_at) <= Date.now()) return null;
  return cmd;
}

async function pollCommandsOnce(config, seenIds, onIntervalChange) {
  const base = `${config.api_url}/api/v1/device/${config.device_id}`;
  const headers = {
    Accept: 'application/json',
    Authorization: `Bearer ${config.auth_token}`,
  };
  const res = await fetch(`${base}/commands`, { headers, signal: AbortSignal.timeout(10000) });
  if (!res.ok) return;
  const json = await res.json();
  for (const item of json?.data?.items || []) {
    const cmd = verifySignedCommand(item, config);
    if (!cmd || seenIds.has(cmd.id)) continue;
    seenIds.add(cmd.id);
    console.log(`[CMD] Received ${cmd.kind} command id=${cmd.id}`);
    await fetch(`${base}/commands/${cmd.id}/result`, {
      method: 'POST',
      headers: { ...headers, 'Content-Type': 'application/json' },
      body: JSON.stringify({ state: 'running', result_status: 'started' }),
      signal: AbortSignal.timeout(10000),
    });
    if (cmd.kind === 'configuration' && Number.isInteger(cmd.parameters?.telemetry_interval_seconds)) {
      const next = Math.max(2, Math.min(30, cmd.parameters.telemetry_interval_seconds));
      onIntervalChange(next);
      console.log(`[CMD] Updated telemetry interval -> ${next}s`);
    }
    await fetch(`${base}/commands/${cmd.id}/result`, {
      method: 'POST',
      headers: { ...headers, 'Content-Type': 'application/json' },
      body: JSON.stringify({ state: 'completed', result_status: 'completed' }),
      signal: AbortSignal.timeout(10000),
    });
    console.log(`[CMD] Completed command id=${cmd.id}`);
  }
}

// --- CLI entrypoint ---

function parseArgs(argv) {
  const opts = {
    configPath: null,
    json: null,
    stdin: false,
    plate: process.env.CARLINK_PLATE || null,
    serial: null,
    interval: 5,
    count: 0,
    speed: 52,
    lat: 3.139,
    lon: 101.6869,
    harsh: false,
    pollCommands: false,
    caPath: null,
  };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--config' || a === '-c') opts.configPath = argv[++i];
    else if (a === '--json' || a === '-j') opts.json = argv[++i];
    else if (a === '--stdin') opts.stdin = true;
    else if (a === '--plate' || a === '-p') opts.plate = argv[++i];
    else if (a === '--serial') opts.serial = argv[++i];
    else if (a === '--interval' || a === '-i') opts.interval = Number(argv[++i]);
    else if (a === '--count' || a === '-n') opts.count = Number(argv[++i]);
    else if (a === '--speed') opts.speed = Number(argv[++i]);
    else if (a === '--lat') opts.lat = Number(argv[++i]);
    else if (a === '--lon') opts.lon = Number(argv[++i]);
    else if (a === '--harsh') opts.harsh = true;
    else if (a === '--poll-commands') opts.pollCommands = true;
    else if (a === '--ca') opts.caPath = argv[++i];
    else if (a === '--help' || a === '-h') {
      console.log(`Usage:
  node scripts/mock-pi.mjs --config carlink-device.json --plate VFR7550 [options]
  cat carlink-device.json | node scripts/mock-pi.mjs --stdin --plate VFR7550

Options:
  --config, -c <file>   Path to downloaded carlink-device.json
  --json,   -j <json>   Inline commissioning JSON string
  --stdin               Read commissioning JSON from stdin
  --plate,  -p <plate>  Vehicle plate registered in CarLink (required, e.g. VFR7550)
  --serial     <str>    Override gateway.device_id (defaults to pi-<device_id>)
  --interval,-i <sec>   Seconds between telemetry packets (2..30, default 5)
  --count,  -n <num>    Number of packets to send (0 = continuous, default 0)
  --speed      <kmh>    Base simulated speed km/h (0..250, default 52)
  --lat / --lon         Base GPS coordinates (default 3.139, 101.6869)
  --harsh               Periodically emit harsh-braking sample every 4th packet
  --poll-commands       Poll signed edge commands from api_url/api/v1/device/<id>/commands
  --ca         <file>   Optional custom CA PEM file for mqtts:// endpoints`);
      process.exit(0);
    } else {
      throw new Error(`Unknown argument: ${a}`);
    }
  }
  if (!Number.isFinite(opts.interval) || opts.interval < 2 || opts.interval > 30) {
    throw new Error('--interval must be between 2 and 30 seconds.');
  }
  if (!Number.isInteger(opts.count) || opts.count < 0) {
    throw new Error('--count must be >= 0.');
  }
  return opts;
}

async function readStdinAll() {
  const chunks = [];
  for await (const chunk of process.stdin) chunks.push(chunk);
  return Buffer.concat(chunks).toString('utf8');
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  let rawJson = opts.json;
  if (!rawJson && opts.configPath) {
    rawJson = readFileSync(opts.configPath, 'utf8');
  } else if (!rawJson && (opts.stdin || !process.stdin.isTTY)) {
    rawJson = await readStdinAll();
  }

  let rl = null;
  if (!rawJson || !opts.plate) {
    if (!process.stdin.isTTY) {
      throw new Error('Provide commissioning JSON (--config/--stdin/--json) and --plate <PLATE>.');
    }
    rl = createInterface({ input: process.stdin, output: process.stdout });
    if (!rawJson) {
      console.log('Paste commissioning JSON (press Enter after the closing }):');
      let acc = '';
      for await (const line of rl) {
        acc += `${line}\n`;
        const trimmed = acc.trim();
        if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
          try {
            JSON.parse(trimmed);
            rawJson = trimmed;
            break;
          } catch {
            // Wait for remaining lines of multi-line JSON
          }
        }
      }
    }
    if (!opts.plate) {
      opts.plate = await rl.question('Vehicle plate (e.g. VFR7550): ');
    }
    rl.close();
  }

  const config = normalizeCommissioningConfig(rawJson);
  const plate = normalizePlate(opts.plate);
  const serial = opts.serial || `pi-${config.device_id}`;
  const caPem = opts.caPath ? readFileSync(opts.caPath) : null;

  const baseTopic = `fleet/v1/${config.organization_id}/${config.device_id}`;
  const upTopic = `${baseTopic}/up/telemetry`;
  const ackTopic = `${baseTopic}/down/ingest_ack`;
  const resTopic = `${baseTopic}/down/results`;

  console.log(`[INIT] device_id=${config.device_id}`);
  console.log(`[INIT] organization_id=${config.organization_id}`);
  console.log(`[INIT] serial=${serial} plate=${plate} mac=${config.obd_bluetooth_mac}`);
  console.log(`[INIT] broker=${config.broker_url} username=${config.broker_username}`);
  console.log(`[INIT] publish_topic=${upTopic}`);

  const client = new MinimalMqttClient(config.broker_url, {
    clientId: config.device_id,
    username: config.broker_username,
    password: config.auth_token,
    caPem,
  });

  client.on('error', (err) => console.error(`[MQTT ERROR] ${err.message}`));
  client.on('message', (topic, payload) => {
    console.log(`[DOWN ${topic}] ${payload}`);
  });

  await client.connect();
  console.log('[MQTT] Connected and authenticated.');
  client.subscribe([ackTopic, resTopic]);
  console.log(`[MQTT] Subscribed to ${ackTopic} & ${resTopic}`);

  let intervalSec = opts.interval;
  let running = true;
  let tick = 0;
  let ackCount = 0;

  client.on('close', (code) => {
    if (!running) return;
    running = false;
    console.error(`[MQTT] Connection closed unexpectedly (code ${code ?? 'unknown'}).`);
    process.exit(1);
  });

  client.on('message', (topic, payload) => {
    if (topic === ackTopic) {
      try {
        const msg = JSON.parse(payload);
        for (const r of msg.results || []) {
          if (r.status === 'accepted' || r.status === 'duplicate') ackCount++;
        }
      } catch {
        // Ignore parse failures
      }
    }
  });

  const stop = () => {
    if (!running) return;
    running = false;
    console.log(`\n[STOP] Shutting down after ${tick} packets published (${ackCount} ingest_acks received)...`);
    client.disconnect();
    process.exit(0);
  };
  process.on('SIGINT', stop);
  process.on('SIGTERM', stop);

  const seenCommands = new Set();
  let cmdTimer = null;
  if (opts.pollCommands) {
    cmdTimer = setInterval(() => {
      pollCommandsOnce(config, seenCommands, (next) => {
        intervalSec = next;
      }).catch((err) => console.error(`[CMD ERROR] ${err.message}`));
    }, 5000);
    cmdTimer.unref?.();
  }

  while (running && (opts.count === 0 || tick < opts.count)) {
    const packet = buildPiTelemetryPacket({
      serial,
      plate,
      tick,
      intervalSeconds: intervalSec,
      baseLat: opts.lat,
      baseLon: opts.lon,
      baseSpeed: opts.speed,
      harsh: opts.harsh,
    });
    const packetId = client.publish(upTopic, JSON.stringify(packet));
    console.log(
      `[PUB #${tick + 1}] pkt=${packetId} event_id=${packet.event_id} speed=${packet.vehicle.speed_kmh}km/h rpm=${packet.vehicle.engine_rpm} gps=(${packet.gps.latitude},${packet.gps.longitude})`,
    );
    tick++;
    if (opts.count !== 0 && tick >= opts.count) break;
    await new Promise((resolve) => setTimeout(resolve, intervalSec * 1000));
  }

  if (opts.count > 0) {
    console.log('[WAIT] Waiting up to 5s for broker/application ACKs...');
    await new Promise((resolve) => setTimeout(resolve, 5000));
    running = false;
    client.disconnect();
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((err) => {
    console.error(`[ERROR] ${err.message}`);
    process.exit(1);
  });
}
