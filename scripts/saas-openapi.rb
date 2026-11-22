# Expanded SaaS operations. Keep schemas explicit; route-parity tests catch missing/extra paths.
module SaasOpenapi
  def self.build
    paths = {}
    uuid = {type: 'string', format: 'uuid'}
    version = {type: 'integer', minimum: 1}
    text = ->(max) { {type: 'string', maxLength: max} }
    enum = ->(*values) { {type: 'string', enum: values} }
    body = ->(properties, required) { {type: 'object', additionalProperties: false, properties: properties, required: required} }
    plan_properties = {name: text.call(100), price_minor: {type: 'integer', minimum: 1, maximum: 1000000000}, currency: enum.call('MYR'),
      period_days: {type: 'integer', minimum: 1, maximum: 365}, vehicle_limit: {type: 'integer', minimum: 1, maximum: 100000}, driver_limit: {type: 'integer', minimum: 1, maximum: 100000},
      retention_days: {type: 'integer', enum: [30,90,180,365]}, cellular_enabled: {type: 'boolean'}, safety_enabled: {type: 'boolean'}, telemetry_min_seconds: {type: 'integer', minimum: 2, maximum: 30}, status: enum.call('active','inactive')}
    plan_required = plan_properties.keys.reject { |key| key == :status }
    schemas = {
      SaasPlanCreate: body.call(plan_properties,plan_required), SaasPlanUpdate: body.call(plan_properties.merge(version: version),[:version]),
      SaasInvoiceIssue: body.call({plan_id: uuid},[:plan_id]), SaasVersion: body.call({version: version},[:version]),
      SaasSubscriptionStatus: body.call({version: version,status: enum.call('active','suspended','inactive')},[:version,:status]),
      SaasStaffCreate: body.call({email: {type: 'string',format: 'email',maxLength: 255},display_name: text.call(160),role: enum.call('admin','technician')},[:email,:display_name,:role]),
      SaasStaffUpdate: body.call({version: version,status: enum.call('active','suspended')},[:version,:status]),
      SaasBluetooth: body.call({version: version,obd_bluetooth_mac: {type: 'string',pattern: '^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$'}},[:version,:obd_bluetooth_mac]),
      SaasActivation: body.call({version: version,installed: {type: 'boolean',enum: [true]},verified_broker_acls: {type: 'boolean',enum: [true]}},[:version,:installed,:verified_broker_acls]),
      SaasLink: body.call({vehicle_id: uuid},[:vehicle_id]), SaasUnlink: body.call({reason: text.call(255)},[:reason]),
      SaasDtcRequest: body.call({code: {type: 'string',pattern: '^[PCBU][0-3][0-9A-F]{3}$',example: 'P0730'}},[:code]),
      SaasDtcExplanation: body.call({code: {type: 'string'},description: {type: 'string',description: 'At most 50 whitespace-separated words.'},component: enum.call('TCM','ECU','OBD'),possible_solution: {type: 'string',description: '20–30 whitespace-separated words.'}},[:code,:description,:component,:possible_solution]),
      SaasExtendedScan: body.call({acknowledge_interruption: {type: 'boolean',enum: [true]}},[:acknowledge_interruption]),
      SaasCommandResult: body.call({state: enum.call('running','completed','failed'),result_status: enum.call('started','completed','not_stationary','unsupported','transport_failed','timed_out'),speed_kmh: {type: 'number'},speed_observed_at: {type: 'string',format: 'date-time'}},[:state,:result_status]),
      SaasPhoto: body.call({version: version,content_base64: {type: 'string',format: 'byte',maxLength: 2796204,description: 'PNG/JPEG only, decoded maximum 2 MiB, dimensions at most 4096×4096 and 8 megapixels. Content MIME check and full GD decode/re-encode remove metadata/extra payload. Not antivirus-scanned; format validation does not prove malware-free contents. Private server-mediated S3 storage.'}},[:version,:content_base64]),
      SaasAppeal: body.call({event_id: uuid,reason: text.call(2000)},[:event_id,:reason]),
      SaasAppealDecision: body.call({version: version,decision: enum.call('approved','rejected'),reason: text.call(2000)},[:version,:decision,:reason]),
      SaasDriverAuth: body.call({event_id: uuid,local_session_id: uuid,credential_type: enum.call('nfc_uid_sha256'),credential_fingerprint: {type: 'string',pattern: '^[0-9a-fA-F]{64}$',writeOnly: true},occurred_at: {type: 'string',format: 'date-time'},action: enum.call('authenticate','toggle','tap_in','tap_out')},[:event_id,:local_session_id,:credential_type,:credential_fingerprint,:occurred_at]),
      SaasAiWindow90: body.call({days: {type: 'integer',minimum: 1,maximum: 90}},[]),
      SaasAiWindow30: body.call({days: {type: 'integer',minimum: 1,maximum: 30}},[])
    }
    query = ->(name, schema, required=false) { {name: name,in: 'query',required: required,schema: schema} }
    page = [query.call('per_page',{type: 'integer',minimum: 1,maximum: 100}),query.call('cursor',text.call(512))]
    window = [query.call('from',{type: 'string',format: 'date-time'},true),query.call('to',{type: 'string',format: 'date-time'},true)]
    add = lambda do |method,path,summary,description,schema=nil,parameters=[],status=200,edge=false|
      paths[path] ||= {}
      path_params = path.scan(/\{([^}]+)\}/).flatten.map { |name| {name: name,in: 'path',required: true,schema: (name == 'segment' ? {type: 'string', pattern: '^[0-9a-f]{64}$'} : uuid)} }
      responses = {status.to_s => {'$ref'=>'#/components/responses/Success'}}
      [401,402,403,404,409,422,429,502,503].each { |code| responses[code.to_s] = {'$ref'=>'#/components/responses/Error'} }
      op = {summary: summary,description: description,parameters: path_params+parameters,responses: responses,
        'x-carlink-audience'=>edge ? 'device' : path.include?('/platform/') ? 'platform' : 'organization'}
      if schema
        op[:requestBody] = {required: true,content: {'application/json'=>{schema: {'$ref'=>"#/components/schemas/#{schema}"}}}}
      end
      op[:security] = [{deviceToken: []}] if edge
      paths[path][method] = op
    end
    platform = '/api/v1/platform'
    org = '/api/v1/organizations/{organization}'
    add.call('get',"#{platform}/plans",'List SaaS plans','Active independent SaaS admin only. Includes inactive plans.')
    add.call('post',"#{platform}/plans",'Create subscription plan','SaaS admin only. MYR prices are integer minor units. No default/free plan is seeded.','SaasPlanCreate',[],201)
    add.call('patch',"#{platform}/plans/{plan}",'Update or deactivate plan','SaaS admin only. Version required. Existing paid subscription and invoice snapshots remain unchanged. Inactive plans cannot receive new invoices.','SaasPlanUpdate')
    add.call('get',"#{platform}/subscriptions",'List subscribers','SaaS admin only. access_active accounts for expiry; status alone does not prove paid access.',nil,page+[query.call('status',enum.call('pending','active','suspended','inactive'))])
    add.call('patch',"#{platform}/organizations/{organization}/subscription",'Suspend, activate or deactivate subscription','SaaS admin only. Activation cannot grant time: an unexpired paid period is required. Payment does not override suspension/deactivation.','SaasSubscriptionStatus')
    add.call('get',"#{platform}/admins",'List SaaS administrators and technicians','SaaS admin only; tenant roles confer no platform authority.')
    add.call('post',"#{platform}/admins",'Add SaaS staff','SaaS admin and email-link verification within 10 minutes required. New staff must request their own email link. No password is generated.','SaasStaffCreate',[],201)
    add.call('patch',"#{platform}/admins/{user}",'Suspend or reactivate SaaS staff','SaaS admin and recent email-link verification required. The last active administrator cannot be suspended.','SaasStaffUpdate')
    add.call('get',"#{platform}/reports/summary",'Platform subscription and invoice summary','SaaS admin only. MYR paid-invoice total is not independently reconciled bank revenue.')
    add.call('get',"#{platform}/request-logs",'Global API request metadata','SaaS admin only. At most 31 days per query. No tenant association, raw URL, bodies or credentials are returned.',nil,window+page)
    add.call('post',"#{platform}/health-triage-ai",'Platform Health Triage AI brief','SaaS admin only, 10/minute. Sends strictly PII-scrubbed platform device, subscription, alert, and API error aggregates to Google Gemini using configured gemini-3.5-flash-lite. Transient; prompts and outputs are not persisted.','SaasAiWindow30')
    add.call('get',"#{platform}/devices",'List restricted device inventory','SaaS admin/technician. Device UUID, organization name and vehicle plate/make/model only; no static token.',nil,page+[query.call('organization_id',uuid)])
    add.call('get',"#{platform}/devices/{device}",'Device freshness and network status','SaaS staff only. online is null without samples, otherwise packet freshness within 180 seconds—not proven connectivity. Technicians additionally receive network and health claims.')
    add.call('post',"#{platform}/devices/{device}/bluetooth",'Save OBD Bluetooth MAC','Active SaaS technician only; ordinary SaaS admins receive 403. Optimistic version guard; this does not itself test a BLE connection.','SaasBluetooth')
    add.call('post',"#{platform}/devices/{device}/commissioning",'Retrieve private Pi commissioning configuration','Active SaaS technician only, 6/minute; ordinary SaaS admins receive 403. Credential-bearing response, private/no-store. Returns one-line setup command to download carlink-telemetry-agent executable and configure carlink-device.json. Does not provision RabbitMQ.')
    add.call('post',"#{platform}/devices/{device}/activate",'Attest installation and activate device','Active SaaS technician only; ordinary SaaS admins receive 403. Saved MAC, managed token and BROKER_VERIFIED_TOPIC_ACLS gate required. Records operator attestation, not proof of broker provisioning or hardware installation.','SaasActivation')
    add.call('post',"#{platform}/devices/{device}/link",'Link device by vehicle UUID','SaaS admin only. Same-organization, one open link per device and per vehicle; append-only history starts now.','SaasLink',[],201)
    add.call('post',"#{platform}/devices/{device}/unlink",'Unlink installed device','SaaS admin only. Closes active driver attribution and cancels outstanding commands. Historical links remain.','SaasUnlink')
    add.call('get',"#{platform}/organizations/{organization}/vehicles",'List organization vehicles for device linking','SaaS admin only. Returns vehicle UUID, registration number, make, model, status and linked device UUID only; no location, driver or telemetry fields.',nil,page)
    [org,"#{platform}/organizations/{organization}"].each do |prefix|
      add.call('get',"#{prefix}/invoices",'List invoices','Organization subscription.read or independent SaaS admin required.',nil,page)
      add.call('post',"#{prefix}/invoices",'Issue subscription invoice','Organization subscription.manage or SaaS admin required. Snapshot plan/price; no activation until confirmation. Only one outstanding issued invoice per organization.','SaasInvoiceIssue',[],201)
      add.call('get',"#{prefix}/invoices/{invoice}",'Retrieve invoice','Invoice is scoped to the selected organization; SaaS admin may use the platform path.')
    end
    add.call('post',"#{platform}/organizations/{organization}/invoices/{invoice}/void",'Void an unpaid unlinked invoice','SaaS admin only. An invoice with any provider payment attempt cannot be locally voided because provider checkout links are permanent.','SaasVersion')
    add.call('get',"#{org}/plans",'Available subscription plans','subscription.read; remains available without operational paid access.')
    add.call('get',"#{org}/subscription",'Current paid terms, expiry and usage','subscription.read; snapshots survive later plan edits. No subscription means no paid access when enforcement is enabled.')
    add.call('post',"#{org}/invoices/{invoice}/pay",'Request invoice checkout','payments.manage. Invoice amount and payee come from server records. Existing permanent payment attempts must be reconciled before another attempt.',nil,[],202)
    add.call('post',"#{org}/dtc-ai",'Transient DTC AI explanation','telemetry.read and paid access, 10/minute. Sends the code to Google using configured gemini-3.5-flash-lite stream JSON configuration. TCU is normalized to TCM; invalid/malformed/word-limit output returns 502. No prompts, codes or explanations are persisted. Not a confirmed diagnosis.','SaasDtcRequest')
    add.call('post',"#{org}/vehicles/{vehicle}/extended-dtc",'Queue read-only extended DTC scan','diagnostics.run. Fresh received/observed speed exactly 0 km/h, current installation and no pending command required. Pi must independently check stationary OBD speed, pause polling, bound execution to 300 seconds and restore polling. No diagnostic result is stored.','SaasExtendedScan',[],202)
    add.call('get',"#{org}/device-commands/{command}",'Read command state only','devices.read; no codes, AI explanation, command credentials or raw scans are returned.')
    add.call('post',"#{org}/vehicles/{vehicle}/configuration",'Queue signed telemetry and fuel settings','device_config.publish. Applies plan minimum interval and cellular/safety gates. Queued is not proof of application by the Pi.',nil,[],202)
    add.call('get',"#{org}/driver-assignments",'Current driver vehicle assignments','drivers.read. Coordinates require matching current NFC shift; packet time is returned and must not be presented as verified live location.')
    add.call('get',"#{org}/coverage-map",'Coverage observations or CSV','coverage.read or coverage.export, plus cellular plan feature. UTC window at most 24 hours. export=true returns CSV inside the seven-field JSON envelope; at most 10000 rows, formula-leading strings escaped, unknown RSRP/SINR blank.',nil,window+page+[query.call('export',{type: 'boolean'})])
    add.call('get',"#{org}/reports/summary",'Organization report counts','reports.read. Snapshot counts, not a full calibrated analytics/reporting suite.')
    add.call('get',"#{org}/compliance-records",'List compliance records','compliance.read. Cursor-paginated compliance records with obligation and subject metadata.',nil,page+[query.call('state',enum.call('pending','verified')),query.call('vehicle_id',uuid)])
    add.call('get',"#{org}/maintenance/due-summary",'Fleet maintenance due summary','maintenance.read. Evaluates active vehicle maintenance assignments and current cycles.',nil,[query.call('vehicle_id',uuid)])
    add.call('post',"#{org}/drivers/{driver}/photo",'Save private driver photo','drivers.write. Versioned PNG/JPEG upload; never served through a public storage symlink.','SaasPhoto')
    add.call('get',"#{org}/drivers/{driver}/photo",'Read private driver photo','drivers.read or matching active own driver with profile.own. S3 photos return item plus a direct S3 SigV4 bearer link valid for at most 300 seconds. Authorization is checked at issuance; issued URLs do not require a CarLink session and cannot be immediately revoked by app access changes. Existing private local files are preserved but return reupload_required=true without file bytes until explicitly reuploaded through validation; never silently deleted.')
    add.call('get',"#{org}/drivers/{driver}/safety-score",'Experimental individual safety score','safety.read and safety feature. Experimental 100 minus 5 per observation; null without sufficient samples, uncalibrated and not distance-normalized. Approved appeals excluded.')
    add.call('get',"#{org}/safety-events",'List speed-difference safety observations','safety.read and safety feature. Retention-limited; edge harsh-driving flags do not determine scores.',nil,page+[query.call('driver_id',uuid),query.call('vehicle_id',uuid)])
    add.call('get',"#{org}/safety-appeals",'List appeals for compliance review','safety.appeals.decide and safety feature. Retention-limited.')
    add.call('post',"#{org}/safety-appeals/{appeal}/decision",'Approve or reject appeal','safety.appeals.decide and safety feature. Submitters cannot review their own appeals, including dual-role users. Versioned once-only decision; approval excludes the observed event from score.','SaasAppealDecision')
    add.call('post',"#{org}/audit-ai",'AI explanation of audit log actions','audit.read and paid access. Explains audit actions.')
    add.call('post',"#{org}/fleet-carbon-ai",'Fleet fuel & carbon reduction AI advisor','reports.read and paid access, 10/minute. Sends strictly organization-scoped, PII-free vehicle fuel, CO2, idle, and maintenance aggregates to Google Gemini. Transient; prompts and outputs are not persisted.','SaasAiWindow90')
    %w[profile records score events appeals energy trips vehicle-info].each do |name|
      description = 'Own active driver, active membership and paid access required; tenant operational grants are not conferred. Records and events are retention-limited.'
      description += ' Safety feature required; uncalibrated experimental score.' if %w[score events].include?(name)
      description += ' Both energy endpoints must belong to the same authorized shift; efficiency uses aligned intervals only, gaps over 15 seconds remain unknown.' if name == 'energy'
      description += ' Only own observed journey intersections are returned; shared journey bounds and other drivers are hidden. Not a fare history.' if name == 'trips'
      description += ' Maintenance/compliance only for the currently authorized unexpired NFC vehicle shift.' if name == 'vehicle-info'
      add.call('get',"#{org}/driver/me/#{name}","Own driver #{name}",description,nil,name == 'records' ? page : [])
    end
    add.call('get',"#{org}/driver/me/trips/{segment}",'Own driver trip detail','Own active driver, active membership and paid access required. Scoped to driver shift bounds and retention cutoff; returns segment metadata, chronological telemetry samples, and non-superseded safety events within segment bounds.')
    add.call('post',"#{org}/driver/me/appeals",'Submit own safety appeal','Own active driver and safety feature. Event must be this driver’s retained observed event; one appeal per event.','SaasAppeal',[],201)
    add.call('post',"#{org}/driver/me/eco-coach-ai",'Personal driver eco-driving AI coach','Own active driver, active membership, profile.own and paid access, 10/minute. Sends only this driver’s anonymized distance, fuel, CO2, idle, and safety counts to Google Gemini. Transient; prompts and outputs are not persisted.','SaasAiWindow90')
    edge = '/api/v1/device/{device}'
    add.call('get',"#{edge}/settings",'Poll device organization settings','EDGE ONLY. Per-device static Bearer token, active installation/subscription. Returns organization settings synchronized to device.',nil,[],200,true)
    add.call('get',"#{edge}/commands",'Poll signed device commands','EDGE ONLY. Per-device static Bearer token, active installation/subscription. wire_payload is the exact UTF-8 HMAC-SHA256 input, keyed by the device token. Do not reserialize before verifying.',nil,[],200,true)
    add.call('post',"#{edge}/commands/{command}/result",'Record device command state','EDGE ONLY. Per-device token and current installation. Start extended scan with local speed exactly zero observed within two seconds; server freshness checked again. Terminal results contain status only. Unknown fields and codes/raw/result are prohibited.','SaasCommandResult',[],200,true)
    add.call('post',"#{edge}/dtc-ai",'Edge transient DTC AI explanation','EDGE ONLY. Per-device token and paid access; 10/minute. Sends transient code to Google; does not persist input/output or confer human authentication.','SaasDtcRequest',[],200,true)
    add.call('post',"#{edge}/driver-auth",'Authenticate driver via NFC tap','EDGE ONLY. Per-device static Bearer token, active installation/subscription. Server resolves active installation; unknown fields and client installation_id/driver_id/organization_id/codes are prohibited. Never returns credential fingerprints.','SaasDriverAuth',[],200,true)
    # Convert symbol keys/required entries to JSON-compatible string values for the shared builder.
    JSON.parse(JSON.generate({paths: paths,components: {schemas: schemas,securitySchemes: {deviceToken: {type: 'http',scheme: 'bearer',description: 'Device routes ONLY; not human API authentication.'}}}}))
  end
end
