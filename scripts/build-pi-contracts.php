<?php
/** Generate the Pi JSON Schema from the same limits used by the runtime validator. */
require __DIR__.'/../vendor/autoload.php';

use App\Services\Telemetry\PiTelemetryValidator as V;

$schema=['$schema'=>'https://json-schema.org/draft/2020-12/schema','$id'=>'https://carlink.example/contracts/pi_telemetry.schema.json',
    'title'=>'Pi packet-snapshot telemetry v1','description'=>'Device serial and plate are checked against broker route identity and historical server installations. No boot, sequence, sensor timestamp or clock quality is invented.',
    'type'=>'object','additionalProperties'=>false,'required'=>['message_type','event_id','timestamp','gateway'],'properties'=>[]];
$set=function (string $path,array $definition) use (&$schema) {
    $node=&$schema; foreach (explode('.',$path) as $key) { $node['properties'][$key] ??= ['type'=>'object','additionalProperties'=>false,'properties'=>[]]; $node=&$node['properties'][$key]; }
    $node=$definition;
};
foreach (V::OBJECTS as $path) $set($path,['type'=>'object','additionalProperties'=>false,'properties'=>[]]);
$set('message_type',['const'=>'telemetry']); $set('event_id',['type'=>'string','format'=>'uuid']);
foreach (['device_id'=>160,'vehicle_id'=>32,'edge_version'=>100] as $key=>$length) $set('gateway.'.$key,['type'=>'string','minLength'=>1,'maxLength'=>$length]);
$schema['properties']['gateway']['required']=['device_id','vehicle_id','edge_version'];
foreach (V::NUMBERS as $path=>$metrics) foreach ($metrics as $key=>[$min,$max]) $set("$path.$key",['type'=>['number','null'],'minimum'=>$min,'maximum'=>$max]);
foreach (V::BOOLEANS as $path) $set($path,['type'=>['boolean','null']]);
$set('vehicle.has_dtc_fault',['type'=>['boolean','null'],'description'=>'Presence only; preferred over code arrays. Unknown/null or false does not prove a complete ECU scan or confirmed fault clearance.']);
foreach (V::STRINGS as $path) $set($path,['type'=>['string','null'],'maxLength'=>160]);
foreach (V::TIMESTAMPS as $path) $set($path,['type'=>$path === 'timestamp' ? 'string' : ['string','null'],'format'=>'date-time','pattern'=>'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$']);
$set('vehicle.dtc_codes',['type'=>'array','maxItems'=>100,'items'=>['type'=>'string','pattern'=>'^[PCBU][0-3][0-9A-Fa-f]{3}$'],'deprecated'=>true,'description'=>'Legacy input only. Backend strips codes before persistence. New collectors must remove codes before MQTT/spooling and publish has_dtc_fault instead.']);
$set('vehicle.calculated_fuel_consumption.rateSource',['type'=>['string','null'],'enum'=>['direct','maf','unknown',null]]);
$set('driver_session.session_id',['type'=>['string','null'],'format'=>'uuid']);
$set('driver_session.status',['type'=>['string','null'],'enum'=>['active','ended','inactive','unknown',null]]);
$schema['properties']['gps']['allOf']=[['if'=>['required'=>['fix_valid'],'properties'=>['fix_valid'=>['const'=>true]]],
    'then'=>['required'=>['latitude','longitude','timestamp'],'properties'=>['latitude'=>['type'=>'number'],'longitude'=>['type'=>'number'],'timestamp'=>['type'=>'string']]]]];
file_put_contents(__DIR__.'/../contracts/json-schema/v1/pi_telemetry.schema.json',json_encode($schema,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
