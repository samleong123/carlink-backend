#!/usr/bin/env node
/** Deterministic frontend reference; reads only the checked-in public OpenAPI contract. */
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const sourcePath = 'contracts/openapi.json';
const markdownPath = 'docs/frontend/API_REFERENCE.md';
const inventoryPath = 'docs/frontend/api-inventory.json';
const sourceBytes = readFileSync(resolve(root, sourcePath));
const spec = JSON.parse(sourceBytes.toString('utf8'));
const documentVersion = '1.0.0';
const methods = new Set(['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace']);
const sourceHash = hash(sourceBytes);

function hash(value) {
  return createHash('sha256').update(value).digest('hex');
}

function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]));
  }
  return value;
}

function schemaHash(value) {
  return hash(JSON.stringify(canonical(value)));
}

function pointer(ref) {
  if (!ref.startsWith('#/')) throw new Error(`External reference is not supported: ${ref}`);
  let value = spec;
  for (const key of ref.slice(2).split('/').map(part => decodeURIComponent(part).replace(/~1/g, '/').replace(/~0/g, '~'))) {
    if (!value || !Object.hasOwn(value, key)) throw new Error(`Unresolved reference: ${ref}`);
    value = value[key];
  }
  return value;
}

/** Inline local references, retaining an explicit marker if future contracts introduce recursion. */
function resolved(value, refs = [], depth = 0) {
  if (depth > 60) throw new Error('Reference resolution exceeded the 60-level safety bound.');
  if (Array.isArray(value)) return value.map(item => resolved(item, refs, depth + 1));
  if (!value || typeof value !== 'object') return value;
  if (value.$ref) {
    if (refs.includes(value.$ref)) return { $ref: value.$ref, 'x-frontend-resolution-note': 'Recursive reference retained; see component schema appendix.' };
    const { $ref, ...siblings } = value;
    return { ...resolved(pointer($ref), [...refs, $ref], depth + 1), ...resolved(siblings, refs, depth + 1) };
  }
  return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, resolved(item, refs, depth + 1)]));
}

function mergeParameters(pathItem, operation) {
  const parameters = new Map();
  for (const parameter of [...(pathItem.parameters ?? []), ...(operation.parameters ?? [])]) {
    const item = resolved(parameter);
    parameters.set(`${item.in}:${item.name}`, item);
  }
  return [...parameters.values()];
}

function topic(path) {
  if (path.includes('/payment-gateway/')) return 'Provider callbacks — server only';
  if (path.startsWith('/api/v1/device/')) return 'Pi device API — edge only';
  if (path.startsWith('/api/v1/platform/devices')) return 'SaaS device inventory and commissioning';
  if (path.startsWith('/api/v1/platform/')) return path.includes('/invoices') ? 'SaaS invoices' : 'SaaS plans, subscribers, staff and reports';
  if (path.includes('/driver/me/')) return 'Driver self service';
  if (path.includes('/files')) return 'Private files and downloads';
  if (/\/(?:plans|subscription|invoices|payments)(?:\/|$)/.test(path)) return 'Organization billing and subscriptions';
  if (/\/(?:compliance|compliance-types|compliance-obligations|compliance-records)(?:\/|$)/.test(path)) return 'Compliance';
  if (/maintenance|service-records/.test(path)) return 'Maintenance and work orders';
  if (/safety-events|safety-appeals|safety-score/.test(path)) return 'Safety and appeal review';
  if (/dtc-ai|extended-dtc|device-commands|\/configuration$/.test(path)) return 'DTC explanation and device commands';
  if (/notifications|notification-preferences/.test(path)) return 'In-app notification center';
  if (/driver-shifts|\/credentials(?:\/|$)|driver-assignments/.test(path)) return 'Driver assignments, credentials and shifts';
  if (/journeys|\/mileage(?:\/|$)|odometer-observations/.test(path)) return 'Journeys and mileage';
  if (/telemetry|positions|latest-state|diagnostics|\/energy$|\/coverage$|coverage-map|fleet-states|\/dashboard$|\/reports\/summary$/.test(path)) return 'Fleet dashboard, telemetry and analytics';
  if (/\/settings$|\/members(?:\/|$)|\/invitations(?:\/|$)|ownership-transfers|request-logs|audit-logs/.test(path)) return 'Organization settings, staff and audit';
  if (path.includes('{resource}') || path.includes('/installations') || path.endsWith('/archive')) return 'Fleet registry and legacy installation routes';
  if (path.includes('{organization}')) return 'Organization access';
  return 'Authentication, session and personal account';
}

function browserClassification(method, path, operation) {
  const notes = [];
  let classification = 'session';
  let authority = 'active human account';
  let executable = true;
  if (path.startsWith('/api/v1/payment-gateway/')) {
    classification = 'provider-only'; authority = 'payment provider callback secret'; executable = false;
    notes.push('Never execute in a human frontend; callback URLs contain bearer secrets.');
  } else if (path.startsWith('/api/v1/device/')) {
    classification = 'edge-only'; authority = 'per-device bearer token'; executable = false;
    notes.push('Pi/device integration only. Never request, retain or attach a device bearer token in the human frontend.');
  } else if (path.startsWith('/api/v1/platform/')) {
    classification = 'platform';
    const sharedStaff = /^\/api\/v1\/platform\/devices(?:\/\{device\}(?:\/(?:bluetooth|commissioning|activate))?)?$/.test(path);
    authority = sharedStaff ? 'admin_or_staff' : 'SaaS admin';
    notes.push(sharedStaff ? 'Current routes allow an active SaaS admin or technician; tenant roles alone grant no access.' : 'Requires independent active SaaS admin authority; tenant owner/admin is insufficient.');
  } else if (path.startsWith('/api/v1/organizations/{organization}')) {
    classification = 'tenant'; authority = 'active membership plus endpoint permission and applicable entitlement';
  } else if ((operation.security ?? spec.security ?? []).length === 0) {
    classification = 'public'; authority = 'public endpoint; browser session/CSRF can still be required';
  }
  const binary = operation['x-carlink-binary-success'] === true;
  if (path.endsWith('/files/{asset}/content') || path.endsWith('/files/{asset}/link')) notes.push('JSON response contains a confidential short-lived S3 bearer URL. Authorize issuance through the API, then retrieve bytes directly from S3; do not attach CarLink credentials or persist/log the URL.');
  if (path.includes('{resource}')) {
    notes.push('Expand {resource} to vehicles, drivers or devices. Choose the matching resource schema; do not merge all alternatives into one form.');
    if (['POST', 'PATCH'].includes(method)) notes.push('Managed-device mode disables the devices mutation branch (403); vehicles and drivers remain permissioned. Vehicle creation automatically registers a pending device.');
  }
  if (method === 'POST' && /\/devices\/\{device\}\/installations(?:\/\{installation\}\/close)?$/.test(path)) {
    notes.push('Legacy tenant installation mutation: disabled (403) while SAAS_MANAGED_DEVICES=true. Use SaaS platform link/unlink workflows.');
  }
  if (path.endsWith('/commissioning')) notes.push('Credential-bearing response. Present only in deliberate commissioning/download UI; omit from logs, request history and persistent browser storage.');
  if (path.endsWith('/dtc-ai')) notes.push('Keep diagnostic inputs/explanations transient; omit from client logs, analytics, history and persistent caches.');
  if (path === '/api/v1/auth/magic-links/consume') notes.push('Requires stateful first-party session and XSRF setup despite public OpenAPI security; explicit single-use link confirmation.');
  return { classification, authority, executable_in_human_frontend: executable, response_kind: binary ? 'binary' : 'json', notes };
}

function describeSchema(schema = {}) {
  const parts = [schema.type ?? (schema.anyOf ? 'anyOf' : schema.oneOf ? 'oneOf' : schema.allOf ? 'allOf' : 'unspecified')];
  if (schema.format) parts.push(`format=${schema.format}`);
  if (schema.enum) parts.push(`enum=${JSON.stringify(schema.enum)}`);
  for (const key of ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'pattern', 'default', 'nullable']) {
    if (Object.hasOwn(schema, key)) parts.push(`${key}=${JSON.stringify(schema[key])}`);
  }
  return parts.join('; ');
}

function code(value) { return `\`${String(value).replaceAll('`', '\\`')}\``; }
function cell(value) { return String(value ?? '').replaceAll('|', '\\|').replace(/\r?\n/g, '<br>'); }
function jsonFence(value) { return `\n\`\`\`json\n${JSON.stringify(value, null, 2)}\n\`\`\`\n`; }

const operations = [];
for (const [path, declaredPathItem] of Object.entries(spec.paths)) {
  const pathItem = declaredPathItem.$ref ? resolved(declaredPathItem) : declaredPathItem;
  for (const [verb, operation] of Object.entries(pathItem)) {
    if (!methods.has(verb)) continue;
    if (!operation.operationId) throw new Error(`Operation ID missing: ${verb} ${path}`);
    const parameters = mergeParameters(pathItem, operation);
    const requestBody = operation.requestBody ? resolved(operation.requestBody) : null;
    const responses = resolved(operation.responses ?? {});
    const schemaContent = { parameters, request_body: requestBody, responses };
    operations.push({
      method: verb.toUpperCase(), path, operation_id: operation.operationId, topic: topic(path),
      summary: operation.summary ?? '', description: operation.description ?? '', tags: operation.tags ?? [],
      declared_audience: operation['x-carlink-audience'] ?? null,
      browser: browserClassification(verb.toUpperCase(), path, operation),
      security: operation.security ?? spec.security ?? [],
      security_source: Object.hasOwn(operation, 'security') ? 'operation' : 'root',
      parameters, request_body: requestBody, responses,
      declared_response_codes: Object.keys(responses),
      request_schema_sha256: schemaHash({ parameters, request_body: requestBody }),
      response_schema_sha256: schemaHash(responses),
      schema_sha256: schemaHash(schemaContent),
      operation_sha256: schemaHash({ path, method: verb.toUpperCase(), operation, inherited_parameters: pathItem.parameters ?? [], security: operation.security ?? spec.security ?? [] }),
    });
  }
}
if (new Set(operations.map(item => item.operation_id)).size !== operations.length) throw new Error('Duplicate operation IDs found.');

const inventory = {
  document_version: documentVersion,
  generated_by: 'scripts/build-frontend-reference.mjs',
  source: { path: sourcePath, sha256: sourceHash, openapi_version: spec.openapi, api_version: spec.info.version },
  operation_count: operations.length,
  classification_note: 'Browser classifications and runtime notes are review guidance, not a replacement for server permissions, subscription checks or current routes. Generic response schemas do not fully define endpoint data payloads.',
  servers: spec.servers ?? [], security_schemes: spec.components?.securitySchemes ?? {},
  resolution: { local_references_inlined: true, maximum_depth: 60, recursive_references: 'Retained with x-frontend-resolution-note; none are invented.' },
  operations,
  component_schemas: Object.fromEntries(Object.entries(spec.components?.schemas ?? {}).map(([name, schema]) => [name, { schema_sha256: schemaHash(resolved(schema)), schema: resolved(schema) }])),
};

const lines = [
  '# CarLink frontend API reference', '',
  `Document version: **${documentVersion}**. API version: **${spec.info.version}**. Operations: **${operations.length}**.`, '',
  `Source: ${code(sourcePath)}. Source SHA-256: ${code(sourceHash)}.`, '',
  'Generated by `node scripts/build-frontend-reference.mjs`. Check freshness with `node scripts/build-frontend-reference.mjs --check`. Do not edit generated outputs by hand.', '',
  'Read the [frontend implementation guide](README.md) for screens, transport, permissions, workflows and current backend limitations. The [machine inventory](api-inventory.json) includes resolved parameters, bodies, response schemas, security and per-operation schema hashes.', '',
  '## Reading this reference', '',
  '- All paths below are exact contract paths. Inherited path parameters are merged with operation parameters; an operation parameter overrides the same name/location.',
  '- Request schemas are JSON schemas, not example payloads. Required property lists, alternative schemas, enums and numeric/string constraints are preserved. Omit optional values rather than supplying unsupported nulls.',
  '- Local references are inlined. Resolution stops explicitly on recursion; it never fabricates missing fields. Schema hashes use recursively sorted object keys, preserve array order, and hash UTF-8 compact JSON with SHA-256.',
  '- Browser labels describe the intended integration boundary. They do not grant access or replace backend role/permission, membership, subscription, source-state or CSRF checks. `admin_or_staff` currently means a SaaS admin or technician, not arbitrary organization staff.',
  '- `public` describes OpenAPI authentication only. Cookie/CSRF initialization still applies to first-party browser mutations; magic-link consumption requires a stateful browser session.',
  '- `edge-only` and `provider-only` operations must never execute in a human frontend. Never collect device tokens, provider callback secrets or Google credentials for a browser API explorer.',
  '- Generic fleet routes expand `{resource}` into `vehicles`, `drivers` or `devices`. In default managed-device mode, organization device mutations and legacy installation mutations are disabled. Existing contract alternatives are not proof those branches are enabled.',
  '- Response codes below are the codes declared in OpenAPI, not a guarantee that middleware, proxies or infrastructure cannot return another status. Common response schemas often type only the seven-field envelope; they do not fully define endpoint-specific `data`. Check the implementation guide and controllers before writing response interfaces.',
  '- Pagination is cursor based where declared. Preserve query filters while following opaque cursors. Do not infer totals, arbitrary page numbers or fleet completeness from one page.',
  '- Known reviewed contract/runtime differences: platform subscribers/devices/request logs declare `per_page` maximum 100 while current controllers allow 200; choose at most 100. Runtime allows SaaS admins as well as technicians to commission devices. This reference does not fix that visibility boundary or the latest-state cellular entitlement gap.', '',
  '## Topic index', '',
];

const topics = [...new Set(operations.map(item => item.topic))];
for (const name of topics) lines.push(`- [${name}](#${name.toLowerCase().replace(/[^a-z0-9\s-]/g, '').replace(/\s/g, '-')}) — ${operations.filter(item => item.topic === name).length} operations`);
lines.push('', '## Authentication schemes', '', jsonFence(inventory.security_schemes));

for (const name of topics) {
  const items = operations.filter(item => item.topic === name);
  lines.push(`## ${name}`, '', '| Method | Path | Operation ID | Browser boundary |', '| --- | --- | --- | --- |');
  for (const op of items) lines.push(`| ${op.method} | ${code(op.path)} | ${code(op.operation_id)} | ${op.browser.classification}${op.browser.classification === 'platform' ? ` / ${op.browser.authority}` : ''}${op.browser.response_kind === 'binary' ? ' / binary' : ''} |`);
  lines.push('');
  for (const op of items) {
    lines.push(`### ${op.method} ${op.path}`, '', `Operation ID: ${code(op.operation_id)}. ${op.summary}`, '');
    if (op.description) lines.push(op.description, '');
    lines.push(`Browser boundary: **${op.browser.classification}**; authority: **${op.browser.authority}**; response: **${op.browser.response_kind}**.`, '', `Effective OpenAPI security (${op.security_source}): ${code(JSON.stringify(op.security))}.`, '');
    for (const note of op.browser.notes) lines.push(`- ${note}`);
    if (op.browser.notes.length) lines.push('');
    if (op.parameters.length) {
      lines.push('Parameters (merged path and operation declarations):', '', '| Location | Name | Required | Type and constraints | Description |', '| --- | --- | --- | --- | --- |');
      for (const p of op.parameters) lines.push(`| ${cell(p.in)} | ${code(p.name)} | ${p.required ? 'yes' : 'no'} | ${cell(describeSchema(p.schema))} | ${cell(p.description ?? '')} |`);
      lines.push('');
    } else lines.push('Parameters: none declared.', '');
    if (op.request_body) {
      lines.push(`Request body: **${op.request_body.required ? 'required' : 'optional'}**.`, '');
      if (op.request_body.description) lines.push(op.request_body.description, '');
      for (const [media, body] of Object.entries(op.request_body.content ?? {})) lines.push(`Content type: ${code(media)}. Fully resolved declared schema:`, jsonFence(body.schema ?? {}));
    } else lines.push('Request body: none declared. Do not invent a payload.', '');
    lines.push(`Declared response codes: ${op.declared_response_codes.map(code).join(', ')}.`, '', '| Code | Declared description | Media/schema shape |', '| --- | --- | --- |');
    for (const [status, response] of Object.entries(op.responses)) {
      const shapes = Object.entries(response.content ?? {}).map(([media, body]) => `${media}: ${body.schema?.format === 'binary' ? 'binary' : body.schema?.properties?.data ? 'envelope; inspect data schema in inventory' : describeSchema(body.schema)}`);
      lines.push(`| ${status} | ${cell(response.description)} | ${cell(shapes.join('; ') || 'no response content declared')} |`);
    }
    lines.push('', 'Response completeness: these are contract declarations. A generic object/envelope does not provide a complete TypeScript model for business data; the inventory preserves the full declared schemas without adding fields.', '', `Schema SHA-256: ${code(op.schema_sha256)}.`, '');
  }
}
lines.push('## Component schema appendix', '', 'These are all declared component schemas, resolved using the same rules as operation request bodies. They include both request and response components; their presence alone does not imply an executable browser operation.', '');
for (const [name, item] of Object.entries(inventory.component_schemas)) lines.push(`### ${name}`, '', `Schema SHA-256: ${code(item.schema_sha256)}.`, jsonFence(item.schema));

const outputs = new Map([
  [markdownPath, `${lines.join('\n').trimEnd()}\n`],
  [inventoryPath, `${JSON.stringify(inventory, null, 2)}\n`],
]);
const check = process.argv.includes('--check');
if (process.argv.slice(2).some(argument => argument !== '--check')) throw new Error('Usage: node scripts/build-frontend-reference.mjs [--check]');
let stale = false;
for (const [path, expected] of outputs) {
  if (check) {
    let actual = null;
    try { actual = readFileSync(resolve(root, path), 'utf8'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
    if (actual !== expected) { process.stderr.write(`Stale or missing: ${path}\n`); stale = true; }
  } else {
    mkdirSync(dirname(resolve(root, path)), { recursive: true });
    writeFileSync(resolve(root, path), expected);
    process.stdout.write(`Generated ${path}\n`);
  }
}
if (stale) process.exitCode = 1;
else process.stdout.write(`${check ? 'Fresh' : 'Complete'}: ${operations.length} operations; source SHA-256 ${sourceHash}\n`);
