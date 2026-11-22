# CarLink Backend API

Multi-tenant IoT fleet operations and telematics backend built with **Laravel 13 / PHP 8.4**, dual **PostgreSQL 17** databases, **Redis 7.4**, and **RabbitMQ 4.2** (AMQPS + MQTT over WSS).

Powers the CarLink static web frontend (via credentialed CORS + Laravel Sanctum CSRF) and Orange Pi Zero 3 on-board telematics gateways (`TelemetryAgent`).

---

## 1. Architecture Overview

```text
Static Web SPA (FRONTEND_URL)
  └── HTTPS + CORS + Sanctum Cookie/CSRF ──▶ Nginx (deploy/nginx.conf.template)
                                                │
Orange Pi Gateway (TelemetryAgent)              ▼
  ├── HTTPS + Static Device Bearer ──────▶ PHP-FPM API (/api/v1/device/{device}/)
  └── MQTT over WSS (/mqtt → :15675) ────▶ RabbitMQ 4.2 (TLS AMQPS :5671)
                                                │
                         ┌──────────────────────┴──────────────────────┐
                         ▼                                             ▼
             telemetry:consume / device-events:consume        Laravel Scheduler & Redis Worker
                         │                                             │
                         ▼                                             ▼
            PostgreSQL Primary DB (carlink)             Amazon SES / S3 SigV4 / Google Gemini API
            PostgreSQL Logging DB (carlink_logs)        (Transient AI prompts; zero DTC/AI storage)
```

### Standard API Response Envelope

All JSON API responses—including validation and error responses—use a consistent 8-field envelope (`App\Support\ApiResponse`):

```json
{
  "status": 200,
  "timestamp": "2026-11-22T08:00:00.000Z",
  "ip_address": "203.0.113.10",
  "ip_country": "MY",
  "request_id": "96d4cbf2-4d22-48a8-b6ff-b4e6fa1e2b60",
  "title": "Success",
  "desc": "",
  "data": {}
}
```

- `ip_address` and `ip_country` are resolved by `App\Services\TrustedClientIpResolver` (supports official Cloudflare IPv4/IPv6 edge CIDRs, multi-hop reverse proxies, `CF-Connecting-IP`, and `CF-IPCountry` without permitting spoofing from untrusted public peers).
- Private file downloads issue short-lived HTTPS **Amazon S3 SigV4 presigned URLs** (`≤ 300s`) inside JSON rather than streaming file bytes through PHP.

---

## 2. Requirements

### Software & Runtime

- **PHP:** `8.4+` with **Composer 2**
- **Required PHP Extensions:**
  - `pdo_pgsql` — Primary application DB + isolated logging DB connections
  - `pdo_sqlite` — Isolated in-memory PHPUnit feature/unit test suite
  - `redis` (`phpredis`) — Queue worker, cache, and rate limiting
  - `gd` (with JPEG & PNG support) — Server-side image decode/re-encode & metadata stripping (`≤ 4096×4096`, `≤ 8 MP`)
  - `fileinfo` — Byte-level MIME inspection for uploads (`JPEG`, `PNG`, `PDF`)
  - `intl` — Country name resolution (`Locale::getDisplayRegion`) for login alerts
  - `mbstring`, `xml`, `openssl`, `zip`, `bcmath`, `pcntl`, `opcache`
- **Recommended `php.ini` limits (`deploy/php.ini`):**
  - `memory_limit=256M`, `max_execution_time=120`, `upload_max_filesize=10M`, `post_max_size=15M`, `expose_php=Off`
- **Optional Developer / Tooling Runtimes:**
  - **Node.js `22+`:** Edge protocol tests (`node --test tests/Edge/mock-pi.test.mjs`) and telemetry simulator (`scripts/mock-pi.mjs`)
  - **Ruby:** Regenerating OpenAPI JSON from YAML/Ruby specs (`scripts/build-openapi.rb`)
  - **Python 3:** RabbitMQ broker provisioning (`scripts/provision-broker.py`, `scripts/requirements-mqtt.txt`) and contract validation (`scripts/validate-contracts.py`)

### Database Requirements

CarLink uses **two logically isolated PostgreSQL databases** (PostgreSQL **17** recommended, tested against `postgres:17-bookworm`) plus **Redis 7.4+**:

1. **Primary Application Database (`pgsql` connection — `DB_DATABASE=carlink`):**
   - Stores multi-tenant core domain tables: users, sessions, magic-link & signup intents, `organizations`, `memberships`, `saas_admins`, `subscription_plans`, `organization_subscriptions`, `billing_invoices`, `payment_attempts`, `vehicles`, `drivers`, `devices`, `device_installations`, `device_commands`, `telemetry_inbox`, `telemetry_samples`, `mileage_*`, `journeys`, `driver_shifts`, `driver_credentials`, `safety_events`, `safety_appeals`, `maintenance_*`, `compliance_*`, `email_notifications`, `mail_outbox` (encrypted), `audit_outbox`, `private_assets`, and `signup_financial_archives`.
   - Schema requirements: UUID primary/foreign keys, `timestampTz` UTC columns (`timezone => 'UTC'` in `config/database.php`), row-level locking (`SELECT ... FOR UPDATE`), and atomic transactions.
   - Migrations: **25 migration files** in `database/migrations/`.

2. **Isolated Audit & Request Logging Database (`logging` connection — `LOG_DB_DATABASE=carlink_logs`):**
   - Dedicated database & PostgreSQL role (`LOG_DB_USERNAME`, separate from `DB_USERNAME`) storing `request_log_events`, `http_request_logs`, `audit_logs`, and `log_ingestion_receipts`.
   - Migrations: **2 migration files** in `database/migrations/logging/`.
   - Includes a local NDJSON disk spool fallback (`REQUEST_LOG_SPOOL_PATH`, `REQUEST_LOG_SPOOL_MAX_BYTES`) recovered via `logs:flush-request-spool`.

3. **PostgreSQL Role & Permission Isolation (`deploy/postgres-init.sh`):**
   - Both databases revoke public access (`REVOKE ALL ON DATABASE ... FROM PUBLIC`) and grant ownership strictly to their respective roles (`DB_USERNAME` and `LOG_DB_USERNAME`).

4. **Redis (`redis:7.4-alpine`):**
   - Required for prioritized background job queues (`auth-mail`, `notifications`, `default`), caching (`CACHE_STORE=redis`), and API rate limiters.
   - Run with `--appendonly yes` and a strong `REDIS_PASSWORD`.

5. **SQLite (`pdo_sqlite`):**
   - Used exclusively for automated tests (`phpunit.xml` sets `DB_CONNECTION=sqlite` `:memory:`). Not supported for production deployment.

### Infrastructure & External Services

- **RabbitMQ `4.2+` (Broker profile):** Required for live Pi MQTT telemetry & NFC event ingestion. Uses plugins `rabbitmq_management`, `rabbitmq_mqtt`, and `rabbitmq_web_mqtt` (`deploy/enabled_plugins`), TLS-only listeners (AMQPS `:5671`, Web-MQTT WSS proxied on `/mqtt` from `:15675`, Management bound to `127.0.0.1:15672`), and virtual host `carlink`.
- **Amazon S3 (Private Bucket):** Stores validated vehicle/driver/compliance/maintenance files (`FILESYSTEM_DISK=s3`). Presigned `GetObject` links require an IAM principal authorized to read/write/delete objects in the private bucket.
- **Amazon SES (`MAIL_MAILER=ses`):** Delivers transactional login links, sign-in security alerts, compliance/maintenance reminders, and analytics digests.
- **Google Gemini API (`GEMINI_API_KEY`):** Powers transient AI features using `gemini-3.5-flash-lite` (no prompts, codes, PII, or AI responses are stored in the database).
- **Payment Gateway:** Configured via `PAYMENT_GATEWAY_URL`, `PAYMENT_GATEWAY_CHECKOUT_HOST`, `PAYMENT_GATEWAY_TOKEN`, and `PAYMENT_GATEWAY_CALLBACK_BASE_URL` (local simulator included in `postman/mocks/payment-gateway/`).

---

## 3. Local Development Installation

1. **Install PHP dependencies:**

   ```bash
   composer install
   ```

2. **Create and configure `.env`:**

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Edit `.env` to configure:
   - **App & Logging PostgreSQL credentials:** `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and `LOG_DB_HOST`, `LOG_DB_DATABASE`, `LOG_DB_USERNAME`, `LOG_DB_PASSWORD`.
   - **Redis:** `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`.
   - **NFC signing secret (≥ 32 chars):** generate with `openssl rand -hex 32` for `NFC_HMAC_KEY`.
   - **Local origins (same-origin dev):**
     ```dotenv
     APP_URL=http://127.0.0.1:8000
     FRONTEND_URL=http://127.0.0.1:8000
     SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8000,localhost:8000
     SESSION_SECURE_COOKIE=false
     ```
   - **Optional integrations:** `GEMINI_API_KEY`, `AWS_*` (or local `FILESYSTEM_DISK=local` during offline dev), `MAIL_MAILER=log` or `ses`, `PAYMENT_GATEWAY_*`.

3. **Run database migrations (both databases):**

   ```bash
   php artisan migrate
   php artisan migrate --database=logging --path=database/migrations/logging
   ```

4. **Bootstrap initial accounts (passwordless — login uses single-use email links):**

   ```bash
   php artisan carlink:provision-saas-admin admin@example.com --name="Platform Owner"
   php artisan carlink:provision-owner owner@example.com "Demo Fleet" --name="Fleet Owner"
   ```

5. **Run API server, queue worker, and scheduler (separate terminals):**

   ```bash
   php artisan serve
   php artisan queue:work redis --queue=auth-mail,notifications,default --sleep=1 --tries=3 --timeout=60
   php artisan schedule:work
   ```

   *(Start `php artisan telemetry:consume` and `php artisan device-events:consume` only after RabbitMQ TLS & ACLs are provisioned.)*

---

## 4. Production Deployment Guide

### Option A — Docker Compose (`compose.yaml`)

The repository includes production container definitions under [`deploy/`](deploy/) and [`compose.yaml`](compose.yaml):

- **`app`** — PHP 8.4-FPM API container (`deploy/Dockerfile`, `deploy/php.ini`, `deploy/entrypoint.sh`)
- **`worker`** — Redis queue worker (`--queue=auth-mail,notifications,default`)
- **`scheduler`** — Laravel task scheduler (`php artisan schedule:work`)
- **`nginx`** — TLS-terminating HTTP/FastCGI & WSS `/mqtt` proxy (`deploy/nginx.Dockerfile`, `deploy/nginx.conf.template`)
- **`postgres`** — PostgreSQL 17 initializing both `carlink` and `carlink_logs` via `deploy/postgres-init.sh`
- **`redis`** — Redis 7.4 with AOF persistence and password authentication
- **`rabbitmq`** — RabbitMQ 4.2 TLS-only MQTT/AMQPS broker (`deploy/rabbitmq.conf`, management UI restricted to `127.0.0.1:15672`)
- **`telemetry-consumer` & `events-consumer` (`broker` profile)** — Long-running AMQPS telemetry & NFC shift consumers

#### Step-by-Step Compose Deployment

1. **Create private environment files (never commit these):**

   ```bash
   cp .env.example .env
   cp deploy/compose.env.example deploy/compose.env
   ```

   Generate strong random secrets (`openssl rand -hex 32` or `php artisan key:generate --show`) for:
   - `APP_KEY`, `NFC_HMAC_KEY`, `DEVICE_CONFIG_SIGNING_KEY`
   - `DB_PASSWORD`, `LOG_DB_PASSWORD`, `REDIS_PASSWORD`
   - `POSTGRES_USER`, `POSTGRES_PASSWORD`, `RABBITMQ_DEFAULT_USER`, `RABBITMQ_DEFAULT_PASS` in `deploy/compose.env`
   - `API_HOST=api.example.com` in `deploy/compose.env`
   - Service credentials: `AWS_*` (S3 + SES), `GEMINI_API_KEY`, `PAYMENT_GATEWAY_*`, `DEVICE_MQTT_URL=wss://api.example.com/mqtt`

2. **Configure split-domain CORS & Sanctum cookies in `.env`** *(when frontend runs on a separate subdomain such as `https://app.example.com` and API on `https://api.example.com`):*

   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://api.example.com
   FRONTEND_URL=https://app.example.com
   SESSION_DOMAIN=.example.com
   SESSION_SECURE_COOKIE=true
   SANCTUM_STATEFUL_DOMAINS=app.example.com,api.example.com
   QUEUE_CONNECTION=redis
   CACHE_STORE=redis
   MAIL_MAILER=ses
   FILESYSTEM_DISK=s3
   SAAS_ENFORCE_SUBSCRIPTIONS=true
   SAAS_MANAGED_DEVICES=true
   ```

3. **Provision private TLS certificates (kept outside git via `deploy/tls/`):**

   - Web HTTPS: `deploy/tls/web/fullchain.pem` and `deploy/tls/web/privkey.pem`
   - RabbitMQ internal TLS: `deploy/tls/broker/ca.pem`, `deploy/tls/broker/server.pem`, and `deploy/tls/broker/server-key.pem`

4. **Build and start the core stack:**

   ```bash
   ./scripts/compose.sh up -d --build
   ```

5. **Run migrations & verify production readiness:**

   ```bash
   ./scripts/compose.sh exec app php artisan migrate --force
   ./scripts/compose.sh exec app php artisan migrate --database=logging --path=database/migrations/logging --force
   ./scripts/compose.sh exec app php artisan carlink:check-deployment
   ```

6. **Bootstrap the first SaaS Admin (terminal-only, once):**

   ```bash
   ./scripts/compose.sh exec app php artisan carlink:provision-saas-admin platform-owner@example.com --name="Platform Owner"
   ```

7. **Provision RabbitMQ broker users, queues, & start MQTT consumers:**

   ```bash
   python3 -m venv .venv && source .venv/bin/activate
   pip install -r scripts/requirements-mqtt.txt
   python scripts/provision-broker.py
   ```

   Update `.env` with `BROKER_VERIFIED_TOPIC_ACLS=true` and `RABBITMQ_MANAGEMENT_URL=http://rabbitmq:15672` (plus management/consumer credentials), refresh config, verify, and start the `broker` profile:

   ```bash
   ./scripts/compose.sh exec app php artisan config:clear
   ./scripts/compose.sh exec app php artisan carlink:check-deployment --broker
   ./scripts/compose.sh --profile broker up -d
   ```

### Option B — Cloudflare & Reverse Proxy Deployment Notes

- **Cloudflare Real IP & Country:** `App\Services\TrustedClientIpResolver` includes official Cloudflare IPv4 & IPv6 CIDRs by default and also resolves multi-hop private/Docker proxies (`127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) when `X-Forwarded-For` arrives from Cloudflare. Add extra proxy CIDRs to `TRUSTED_CLOUDFLARE_PROXIES` or `TRUSTED_FORWARDED_PROXIES` if needed.
- **Cloudflare Dashboard settings:**
  - **SSL/TLS → Overview:** Set mode to **Full (strict)**.
  - **Network → IP Geolocation:** Enable so Cloudflare forwards `CF-IPCountry` for `ip_country` in API envelopes, request/audit logs, and sign-in notification emails.
- **Nginx rules (`deploy/nginx.conf.template`):**
  - Masks payment callback tokens in access logs (`/api/v1/payment-gateway/callback/[redacted]`).
  - Sets `client_max_body_size 15m` (to support 10 MiB base64 JSON file uploads).
  - Proxies `/mqtt` WebSockets to `rabbitmq:15675/ws` and serves local Swagger assets at `/docs/` with strict CSP.
- **1Panel / Bare-Metal VPS:** Ensure PHP 8.4 includes all extensions listed in Section 2, run the 2 database migrations, and configure Supervisor/systemd daemons for `queue:work`, `schedule:work`, and (after broker setup) `telemetry:consume` and `device-events:consume`. Never skip `php artisan config:clear` after modifying `.env`.

---

## 5. Architecture Highlights & CLI Reference

### Modules & Features

- **SaaS Admin (`/api/v1/platform/*`):** Plan CRUD (vehicle/driver limits, `30/90/180/365` retention, cellular/safety gates, `2–30s` minimum telemetry interval), subscriber lifecycle (`active` / `suspended` / `inactive`), MYR invoice issuance/void, 1-to-1 UUID device link/unlink, device online & cellular operator status, global org-scrubbed request logs, platform summary reports, **Platform Fleet Health & Operations Triage AI**, archived unpaid signups, and SaaS staff management.
- **Device Technician (`/api/v1/platform/devices/{device}/*`):** Save OBD-II Bluetooth MAC address, generate one-line Orange Pi `carlink-telemetry-agent` commissioning command, attest & activate installed devices, and inspect detailed cellular/telemetry diagnostics.
- **Organization Admin & Staff (`/api/v1/organizations/{organization}/*`):** Self-serve email signup & 12-hour unpaid auto-deletion (`OrganizationSignups`), gateway checkout/renewal, vehicle registration (auto-provisions pending device + encrypted static token + random `obd_uuid`), driver registry & photos, granular module roles (`module_vehicle`, `module_driver`, `module_dashboard`, `module_compliance`, `module_coverage`), stationary `0 km/h` extended DUDU2 DTC scan, RSRP/SINR coverage map & CSV export, **Fault Code AI**, **Action Audit AI**, **Fleet Fuel & Carbon Reduction Advisor AI**, and HTML notification digests.
- **Driver Portal (`/api/v1/organizations/{organization}/driver/me/*`):** Strictly driver-isolated own driving records, NFC shift history, safety score, harsh events, trip routes with fuel/CO₂ (`EmissionFactors` + `PacketFuelLedger`), authorized vehicle compliance/maintenance view, inaccurate-event appeals, and **Personal Eco-Driving & Fuel Coach AI**.

### Artisan CLI & Scheduled Commands

| Command | Frequency / Usage | Purpose |
| :--- | :--- | :--- |
| `carlink:check-deployment [--broker]` | Manual / CI | Verify HTTPS, keys, extensions, DBs, Redis, tables & broker readiness |
| `carlink:provision-saas-admin <email>` | Once on bootstrap | Create the first passwordless SaaS platform admin |
| `carlink:provision-owner <email> <org>` | Manual setup | Provision an organization and initial owner |
| `accounts:set-status <uuid> active\|suspended` | Operator CLI | Globally suspend or activate a human user account |
| `mail:generate-notifications [--force]` | Hourly (Scheduler) | Generate compliance/maintenance reminders & analytics digests |
| `mail:dispatch-pending` | Every minute (Scheduler) | Recover & queue pending encrypted `mail_outbox` items |
| `compliance:evaluate` / `maintenance:evaluate` | 1m / 5m (Scheduler) | Evaluate road tax, insurance, PUSPAKOM & mileage maintenance triggers |
| `telemetry:project-pending` / `journeys:mark-gaps` | Every minute (Scheduler) | Project pending telemetry into mileage/safety & close stale journeys |
| `driver-shifts:expire` / `payments:expire` | Every minute (Scheduler) | Expire stale NFC shifts & 30-min payment attempts |
| `signups:delete-unpaid` | Every minute (Scheduler) | Delete unpaid self-service orgs after 12h while preserving financial archives |
| `privacy:retain-driver-history` | Daily (Scheduler) | Enforce purchased `30/90/180/365`-day driver history retention cutoff |
| `files:cleanup` / `logs:deliver-audit-outbox` | 5m / 1m (Scheduler) | Retry S3 private object deletions & deliver audit logs |
| `privacy:purge-legacy-dtc --acknowledge` | Explicit CLI only | Scrub legacy stored DTC codes if upgrading from historical telemetry |

---

## 6. Contracts & Automated Testing

### OpenAPI & Swagger

- Authoritative OpenAPI 3 specification: [`contracts/openapi.json`](contracts/openapi.json)
- Runtime Swagger UI (`https://<api-host>/docs/`): [`public/docs/openapi.json`](public/docs/openapi.json) (CSRF-aware `swagger-initializer.js`, `persistAuthorization: false`)
- Rebuild OpenAPI after changing YAML fragments or `scripts/*openapi.rb`:

  ```bash
  ruby scripts/build-openapi.rb
  ```

### Running Tests

The PHPUnit suite runs against isolated in-memory SQLite (`phpunit.xml`) so tests never touch production PostgreSQL databases:

```bash
php -d memory_limit=512M artisan test
```

*(Note: Ensure `.env` exists — e.g. `cp .env.example .env` — to avoid `vlucas/phpdotenv` missing-file warnings in Collision output; `phpunit.xml` still overrides all connections safely to `:memory:` SQLite.)*

Run edge protocol/telemetry simulator tests:

```bash
node --test tests/Edge/mock-pi.test.mjs
```

---

## 7. Security & Sensitive File Handling

This repository excludes secrets, certificates, logs, and temporary files via [`.gitignore`](.gitignore) and [`.dockerignore`](.dockerignore):

- **Never commit:** `.env`, `.env.*` (except `.env.example`), `deploy/compose.env`, `deploy/*.env`, `deploy/tls/`, `deploy/secrets/`, `*.pem`, `*.key`, `*.crt`, `storage/logs/*`, `storage/app/private/*`, `vendor/`, or `.DS_Store` / `__MACOSX/`.
- **AI Privacy:** Google Gemini AI calls (`DtcAi`, `AuditAi`, `FleetCarbonAi`, `DriverEcoCoachAi`, `PlatformHealthTriageAi`) strip PII, send API keys strictly in `x-goog-api-key` headers, and store no prompts or completions.
- **No Plaintext Passwords or Secrets Logged:** Human authentication is strictly passwordless email links; log scrubbers omit request bodies, S3 URLs, callback tokens, and PII.
