#!/usr/bin/env python3
"""Provision bounded RabbitMQ resource/topic ACLs through loopback management. Never activates devices or attests ACL safety."""
import argparse
import base64
import getpass
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request
import uuid

p = argparse.ArgumentParser(description=__doc__)
p.add_argument('--url', default='http://127.0.0.1:15672')
p.add_argument('--operator', required=True)
p.add_argument('--consumer', default='carlink_consumer')
p.add_argument('--publisher', default='carlink_publisher')
p.add_argument('--organization', required=True, type=uuid.UUID)
p.add_argument('--device', required=True, type=uuid.UUID)
p.add_argument('--device-username', help='Defaults to managed-device principal d-UUID_WITHOUT_HYPHENS; use UUID explicitly only for legacy records.')
p.add_argument('--replace-existing-user-passwords', action='store_true')
a = p.parse_args()
parsed = urllib.parse.urlparse(a.url)
if parsed.scheme != 'https' and not (parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost', '::1')):
    p.error('Use HTTPS or a loopback/SSH-tunneled management endpoint.')
device_username = a.device_username or 'd-' + a.device.hex
names = [a.consumer, a.publisher, device_username]
if len(set(names)) != 3 or a.operator in names or any(not re.fullmatch(r'[A-Za-z0-9_-]{1,160}', name) for name in names):
    p.error('Principals must be distinct and contain only letters, numbers, underscores or hyphens.')
op_pass = getpass.getpass('Broker operator password: ')
auth = base64.b64encode(f'{a.operator}:{op_pass}'.encode()).decode()

def request(method, path, payload=None):
    req = urllib.request.Request(a.url.rstrip('/')+'/api/'+path, method=method,
        data=json.dumps(payload).encode() if payload is not None else None,
        headers={'Authorization': 'Basic '+auth, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=15) as res:
            content = res.read()
            return json.loads(content) if content else None
    except urllib.error.HTTPError as e:
        if method == 'GET' and e.code == 404:
            return None
        raise SystemExit(f'Broker operation failed: HTTP {e.code}; credentials and response redacted.') from None
    except (OSError, ValueError):
        raise SystemExit('Broker operation failed; details redacted.') from None

def q(value):
    return urllib.parse.quote(value, safe='')

existing = {name: request('GET', 'users/'+q(name)) for name in names}
if any(user and user.get('tags') for user in existing.values()):
    raise SystemExit('An existing target principal has management tags. Review/remove those privileges before provisioning; no target users changed.')
for name in names:
    if existing[name] and not a.replace_existing_user_passwords:
        print(f'Keeping existing principal {name}; explicit flag required for password replacement.')
    else:
        password = getpass.getpass(f'New password for {name}: ')
        if len(password) < 24:
            p.error('Use independent random passwords of at least 24 characters.')
        request('PUT', 'users/'+q(name), {'password': password, 'tags': ''})
vhost = 'carlink'
request('PUT', 'vhosts/'+q(vhost), {})
for queue, suffix in [('carlink.telemetry','telemetry'), ('carlink.events','events')]:
    request('PUT', f'queues/{q(vhost)}/{q(queue)}', {'durable': True, 'auto_delete': False, 'arguments': {'x-queue-type': 'quorum'}})
    request('POST', f'bindings/{q(vhost)}/e/amq.topic/q/{q(queue)}', {'routing_key': f'fleet.v1.*.*.up.{suffix}', 'arguments': {}})
prefix = re.escape(f'fleet.v1.{a.organization}.{a.device}')
own_queue = '^mqtt-subscription-'+re.escape(str(a.device))+'qos[01]$'
acls = {
    a.consumer: {'configure': '^$', 'write': '^$', 'read': r'^carlink\.(telemetry|events)$'},
    a.publisher: {'configure': '^$', 'write': r'^amq\.topic$', 'read': '^$'},
    device_username: {'configure': own_queue, 'write': r'^(amq\.topic|mqtt-subscription-'+re.escape(str(a.device))+r'qos[01])$', 'read': r'^(amq\.topic|mqtt-subscription-'+re.escape(str(a.device))+r'qos[01])$'},
}
for name, acl in acls.items():
    request('PUT', f'permissions/{q(vhost)}/{q(name)}', acl)
request('PUT', f'topic-permissions/{q(vhost)}/{q(a.publisher)}', {'exchange': 'amq.topic', 'write': r'^fleet\.v1\.[0-9a-f-]{36}\.[0-9a-f-]{36}\.down\.(ingest_ack|results)$', 'read': '^$'})
request('PUT', f'topic-permissions/{q(vhost)}/{q(device_username)}', {'exchange': 'amq.topic', 'write': '^'+prefix+r'\.up\.(telemetry|events)$', 'read': '^'+prefix+r'\.down\.(ingest_ack|results)$'})
print('Provisioned resource/topic ACLs. MQTT username: '+device_username+'; client ID: '+str(a.device))
print('Still required: enforce client-ID equality at CONNECT (custom auth backend), negative ACL tests, reconnect tests and external security review. BROKER_VERIFIED_TOPIC_ACLS remains unchanged.')
