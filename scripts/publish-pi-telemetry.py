#!/usr/bin/env python3
"""Publish explicitly fake Pi snapshots over WSS, QoS 1; wait for application ACKs (not only PUBACK)."""
import argparse
import copy
from datetime import datetime, timezone
import getpass
import json
from pathlib import Path
import threading
import time
from urllib.parse import urlparse
import uuid

import paho.mqtt.client as mqtt

p = argparse.ArgumentParser(description=__doc__)
p.add_argument('--url', required=True, help='wss://api.example.com/mqtt')
p.add_argument('--organization', required=True, type=uuid.UUID)
p.add_argument('--device', required=True, type=uuid.UUID)
p.add_argument('--device-username', help='Optional legacy principal; defaults to the managed d-UUID_WITHOUT_HYPHENS principal.')
p.add_argument('--serial', required=True)
p.add_argument('--plate', required=True)
p.add_argument('--count', type=int, default=1)
p.add_argument('--interval', type=float, default=5)
p.add_argument('--speed', type=float, default=60)
a = p.parse_args()
u = urlparse(a.url)
if u.scheme != 'wss' or not u.hostname or u.username or u.query:
    p.error('Use a WSS URL without credentials or query parameters.')
if not 1 <= a.count <= 1000 or not 2 <= a.interval <= 30 or not 0 <= a.speed <= 250:
    p.error('count 1..1000, interval 2..30 (also honor purchased/configured minimum), speed 0..250 required.')
fixture = json.loads((Path(__file__).resolve().parent.parent/'contracts/fixtures/v1/pi_telemetry.json').read_text())
base = f'fleet/v1/{a.organization}/{a.device}'
connected = threading.Event()
received = set()
condition = threading.Condition()
client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=str(a.device), protocol=mqtt.MQTTv5, transport='websockets')
client.username_pw_set(a.device_username or 'd-' + a.device.hex, getpass.getpass('Device MQTT password (managed device static token): '))
client.tls_set()
client.ws_set_options(path=u.path or '/mqtt')

def on_connect(c, userdata, flags, reason, properties):
    if reason.is_failure:
        print('MQTT authentication rejected.')
        return
    c.subscribe(base+'/down/ingest_ack', qos=1)

def on_subscribe(c, userdata, mid, codes, properties):
    if all(not code.is_failure for code in codes):
        connected.set()

def on_message(c, userdata, message):
    try:
        ack = json.loads(message.payload)
        if ack.get('message_type') == 'ingest_ack' and ack.get('device_id') == str(a.device):
            with condition:
                for result in ack.get('results', []):
                    if result.get('status') in ('accepted', 'duplicate'):
                        received.add(result['event_id'])
                condition.notify_all()
    except (ValueError, KeyError, TypeError):
        pass

client.on_connect = on_connect
client.on_subscribe = on_subscribe
client.on_message = on_message
client.connect(u.hostname, u.port or 443, keepalive=30)
client.loop_start()
pending = set()
try:
    if not connected.wait(15):
        raise SystemExit('Could not authenticate and subscribe to the application ACK topic.')
    for i in range(a.count):
        packet = copy.deepcopy(fixture)
        packet['event_id'] = str(uuid.uuid4())
        stamp = datetime.now(timezone.utc).isoformat(timespec='milliseconds').replace('+00:00', 'Z')
        packet['timestamp'] = packet['gps']['timestamp'] = stamp
        packet['gateway']['device_id'] = a.serial
        packet['gateway']['vehicle_id'] = a.plate
        packet['vehicle']['speed_kmh'] = a.speed
        packet['gps']['speed_kmh'] = a.speed
        packet.pop('driver_session', None)  # Fake telemetry must not claim a real authenticated shift.
        packet['vehicle']['has_dtc_fault'] = bool(packet['vehicle'].pop('dtc_codes', []))
        packet['device_health']['last_sync_at'] = stamp
        packet['device_health']['uptime_s'] = i*int(a.interval)
        result = client.publish(base+'/up/telemetry', json.dumps(packet, allow_nan=False), qos=1, retain=False)
        result.wait_for_publish(timeout=15)
        if not result.is_published():
            raise SystemExit('Broker PUBACK timed out; application acceptance is unknown.')
        pending.add(packet['event_id'])
        print('Published FAKE snapshot event_id='+packet['event_id'])
        if i+1 < a.count:
            time.sleep(a.interval)
    with condition:
        complete = condition.wait_for(lambda: pending <= received, timeout=120)
    if not complete:
        raise SystemExit('Application ACK timeout. Check broker outbox, scheduler and quarantine; do not infer acceptance from PUBACK.')
    print(f'Application accepted/duplicate ACKs received: {len(pending)}. Analytics projection may still be pending.')
finally:
    client.disconnect()
    client.loop_stop()
