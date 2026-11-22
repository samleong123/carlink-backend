#!/usr/bin/env ruby
require 'yaml'
require 'json'
require_relative 'saas-openapi'
require_relative 'private-assets-openapi'

root = File.expand_path('..', __dir__)
read = ->(path) { YAML.safe_load(File.read(File.join(root, path)), aliases: true) }
spec = read.call('contracts/base.yaml')
fragments = %w[contracts/fleet-paths.yaml contracts/payment-paths.yaml contracts/driver-shifts-paths.yaml contracts/maintenance-paths.yaml contracts/compliance-paths.yaml contracts/notification-paths.yaml contracts/mileage-paths.yaml contracts/journeys-paths.yaml contracts/foundation-paths.yaml contracts/operations-paths.yaml contracts/signup-paths.yaml].map { |path| read.call(path) }
(fragments + [SaasOpenapi.build,PrivateAssetsOpenapi.build]).each do |fragment|
  (fragment['paths'] || {}).each do |name, operation|
    spec['paths'][name] ||= {}
    operation.each do |method, value|
      raise "Duplicate path operation: #{name} #{method}" if spec['paths'][name].key?(method)
      spec['paths'][name][method] = value
    end
  end
  (fragment['components'] || {}).each do |group, entries|
    spec['components'][group] ||= {}
    entries.each do |name, value|
      raise "Duplicate component: #{group}/#{name}" if spec['components'][group].key?(name)
      spec['components'][group][name] = value
    end
  end
end
PrivateAssetsOpenapi.paginate(spec)
used_ids = {}
spec['paths'].each do |path, methods|
  methods.each do |method, operation|
    next unless %w[get post put patch delete].include?(method)
    if path.match?(%r{/organizations/\{organization\}/devices}) && %w[post patch].include?(method)
      operation['description'] = 'Managed-device mode (default): returns 403. Organizations cannot create, activate or relink devices. Register a vehicle to generate a pending device; contact SaaS staff for installation/relinking. Legacy behavior is available only when SAAS_MANAGED_DEVICES=false. ' + (operation['description'] || '')
    end
  end
end
spec['paths'].each do |path, methods|
  methods.each do |method, operation|
    next unless %w[get post put patch delete options head].include?(method)
    operation['operationId'] ||= "#{method}_#{path.gsub(/[{}]/, '').gsub(/[^a-zA-Z0-9]+/, '_').sub(/^_/, '')}"
    raise "Duplicate operationId: #{operation['operationId']}" if used_ids[operation['operationId']]
    used_ids[operation['operationId']] = true
  end
end
File.write(File.join(root, 'contracts/openapi.json'), JSON.pretty_generate(spec) + "\n")
File.write(File.join(root, 'public/docs/openapi.json'), JSON.pretty_generate(spec) + "\n")
