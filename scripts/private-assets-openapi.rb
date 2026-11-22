module PrivateAssetsOpenapi
  def self.build
    uuid = {type: 'string',format: 'uuid'}
    ref = ->(name) { {'$ref'=>"#/components/schemas/#{name}"} }
    object = ->(properties, required=properties.keys) { {type: 'object',additionalProperties: false,properties: properties}.merge(required.empty? ? {} : {required: required}) }
    types = {type: 'string',enum: %w[organization driver vehicle compliance_record maintenance_order mileage]}
    modes = {type: 'string',enum: %w[view download]}
    schemas = {
      CursorPagination: object.call({next_cursor: {type: 'string',nullable: true},previous_cursor: {type: 'string',nullable: true},per_page: {type: 'integer',minimum: 1,maximum: 200}}),
      PrivateAsset: object.call({id: uuid,target_type: types,target_id: uuid,purpose: {type: 'string'},filename: {type: 'string'},mime_type: {type: 'string',enum: %w[image/jpeg image/png application/pdf]},size_bytes: {type: 'integer',minimum: 1,maximum: 10485760},version: {type: 'integer'},created_at: {type: 'string',format: 'date-time'}}),
      PrivateFileLink: object.call({url: {type: 'string',format: 'uri',description: 'HTTPS S3 GetObject presigned URL using AWS SigV4 (HMAC-SHA256). Confidential bearer URL: contains object path, AWS credential identifier and signature; do not log, persist or share. Authorization is checked at issuance, not by S3 on each use.'},expires_at: {type: 'string',format: 'date-time'},valid_for_seconds: {type: 'integer',enum: [300]},requires_authenticated_session: {type: 'boolean',enum: [false]}}),
      PrivateAssetUpload: object.call({target_type: types,target_id: uuid,purpose: {type: 'string',enum: %w[photo road_tax insurance mileage_evidence maintenance_evidence document]},filename: {type: 'string',maxLength: 160},version: {type: 'integer',minimum: 1,description: 'Required for driver photo replacement; current driver version.'},content_base64: {type: 'string',format: 'byte',maxLength: 13981016,description: 'Canonical base64, decoded 10 MiB maximum (photos 2 MiB). Content MIME check; JPEG/PNG full decode/re-encode; PDF header/version and EOF checks only. No full PDF structure/encryption validation, external binary or antivirus scan; not proof of malware-free contents. No SVG/HTML/archives.'}},%i[target_type target_id purpose filename content_base64]),
      PrivateAssetLinkRequest: object.call({mode: modes},[]),
      PrivateAssetRevoke: object.call({version: {type: 'integer',minimum: 1}})
    }
    response = ->(data) { {description: 'Seven-field JSON envelope. Link responses contain a confidential S3 bearer URL; never AWS secret keys.',content: {'application/json'=>{schema: {allOf: [ref.call('Envelope'),{type: 'object',properties: {data: data}}]}}}} }
    query = ->(name,schema,required=false) { {name: name,in: 'query',required: required,schema: schema} }
    paths = {}
    add = lambda do |path,method,summary,description,data,body=nil,parameters=[],status=200|
      paths[path] ||= {}
      op = {tags: ['Private attachments'],summary: summary,description: description,security: [{cookieAuth: []}],parameters: path.scan(/\{([^}]+)\}/).flatten.map { |name| {name: name,in: 'path',required: true,schema: uuid} }+parameters,responses: {status.to_s=>response.call(data)}}
      [401,402,403,404,409,413,415,422,429,503].each { |code| op[:responses][code.to_s] = {'$ref'=>'#/components/responses/Error'} }
      op[:requestBody] = {required: true,content: {'application/json'=>{schema: ref.call(body)}}} if body
      paths[path][method] = op
    end
    base='/api/v1/organizations/{organization}/files'
    permissions='Active tenant membership and subscription required. Target-specific organizations/drivers/vehicles/compliance/maintenance/mileage read or write permission is checked, never an unrelated files.manage bypass. Matching active drivers may read own driver attachments and their own/currently NFC-authorized compliance/maintenance evidence, never unrelated fleet files.'
    add.call(base,'get','List target attachments',permissions,object.call({items: {type: 'array',items: ref.call('PrivateAsset')},pagination: ref.call('CursorPagination')}),nil,[query.call('target_type',types,true),query.call('target_id',uuid,true)]+page)
    add.call(base,'post','Upload private attachment through server',permissions+' JSON/base64 server upload only, 12/minute. MIME comes from contents, safe generated extension/name, bounded image dimensions, image decode/re-encode and metadata removal. PDFs receive basic header/version and EOF checks only, not full structure/encryption validation. No external validator binary or antivirus scan; not proof of malware-free contents. No direct-to-S3 uploads. Photos attach to organizations/drivers; driver replacement requires current version. Upload does not manually verify compliance or mileage.',object.call({item: ref.call('PrivateAsset')}),'PrivateAssetUpload',[],201)
    signing=' Authorization/readiness is checked when issuing a private S3 GetObject SigV4 URL, valid for at most 300 seconds (temporary AWS credentials can expire sooner). Anyone holding the URL can use it without a CarLink session. Revocation, replacement, logout or membership suspension blocks new issuance, not already-issued URLs until expiry or object deletion. Signed response MIME/disposition/cache settings; images may be inline, PDFs always download. No PHP byte proxy or download-time hash check; successful presigning does not prove the object exists or is readable.'
    add.call("#{base}/{asset}/link",'post','Issue a five-minute direct S3 download link',permissions+signing,object.call({item: ref.call('PrivateAsset'),link: ref.call('PrivateFileLink')}),'PrivateAssetLinkRequest')
    add.call("#{base}/{asset}/revoke",'post','Revoke attachment and block new link issuance',permissions+' Versioned revocation commits with audit responsibility. Private S3 deletion is retried by files:cleanup; metadata remains. Issued bearer URLs can remain usable until expiry or object deletion.',object.call({}),'PrivateAssetRevoke')
    add.call("#{base}/{asset}/content",'get','Retrieve a direct S3 link in JSON',permissions+signing+' Breaking change from the former binary CarLink-HMAC route: now a standard JSON envelope containing item/link, never file bytes or a redirect.',object.call({item: ref.call('PrivateAsset'),link: ref.call('PrivateFileLink')}),nil,[query.call('mode',modes)])
    JSON.parse(JSON.generate({paths: paths,components: {schemas: schemas}}))
  end
  def self.page(maximum=100)
    [{name: 'per_page',in: 'query',schema: {type: 'integer',minimum: 1,maximum: maximum,default: 50}}, {name: 'cursor',in: 'query',schema: {type: 'string',maxLength: 2048}}]
  end
  def self.paginate(spec)
    org='/api/v1/organizations/{organization}'
    ["#{org}/{resource}","#{org}/devices/{device}/installations","#{org}/driver-assignments","#{org}/driver/me/trips"].each do |path|
      op=spec['paths'].fetch(path).fetch('get')
      params=JSON.parse(JSON.generate(page(path.end_with?('/driver-assignments') ? 200 : 100)))
      op['parameters']=(op['parameters'] || []).reject { |p| %w[per_page cursor].include?(p['name']) }+params
      op['responses']['422']={'$ref'=>'#/components/responses/Error'}
      op['description']=(op['description'] || '').gsub('Returns up to 100 records in the active tenant.','Returns cursor-paginated records in the active tenant.')+' data.pagination includes next_cursor, previous_cursor and per_page; follow cursors until null. No pre-pagination history cap; ordering has unique tie-breakers.'
      op['description']+=' Own-trip cursors are encrypted and organization/driver-bound; shared journey and shift IDs remain hidden.' if path.end_with?('/trips')
    end
    spec['components']['responses']['FleetListResponse']['description']='Cursor-paginated data.items and data.pagination (next_cursor, previous_cursor, per_page); follow pages to access all retained records.'
  end
end
