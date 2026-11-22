<?php

namespace App\Services\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** Packet snapshots, not canonical sensor observations. No implicit boot, sequence or clock quality. */
class PiTelemetryValidator
{
    public const NUMBERS = [
        'gps'=>['latitude'=>[-90,90], 'longitude'=>[-180,180], 'altitude_m'=>[-1000,20000], 'speed_kmh'=>[0,250], 'heading_deg'=>[0,360], 'satellites'=>[0,100], 'hdop'=>[0,100]],
        'vehicle'=>['engine_rpm'=>[0,20000], 'speed_kmh'=>[0,250], 'coolant_temp_c'=>[-40,215], 'engine_load_pct'=>[0,100], 'throttle_pct'=>[0,100], 'intake_temp_c'=>[-40,215], 'maf_gps'=>[0,1000], 'fuel_rate_lph'=>[0,1000], 'fuel_level_pct'=>[0,100], 'odometer_km'=>[0,10000000], 'engine_runtime_s'=>[0,4294967295], 'ecu_voltage_v'=>[0,100]],
        'network'=>['lte_band'=>[0,1000], 'earfcn'=>[0,4000000], 'pci'=>[0,1007], 'rssi_dbm'=>[-150,0], 'rsrp_dbm'=>[-160,-20], 'rsrq_db'=>[-50,10], 'sinr_db'=>[-30,50]],
        'device_health'=>['cpu_temp_c'=>[-40,150], 'cpu_usage_pct'=>[0,100], 'memory_usage_pct'=>[0,100], 'disk_usage_pct'=>[0,100], 'uptime_s'=>[0,4294967295], 'pending_records'=>[0,10000000]],
        'driving_behaviour.acceleration'=>['x_mps2'=>[-200,200], 'y_mps2'=>[-200,200], 'z_mps2'=>[-200,200]],
        'driving_behaviour.gyroscope'=>['x_dps'=>[-4000,4000], 'y_dps'=>[-4000,4000], 'z_dps'=>[-4000,4000]],
        'driving_behaviour.threshold'=>['harsh_braking'=>[0,200], 'rapid_acceleration'=>[0,200], 'sharp_cornering'=>[0,200]],
        'vehicle.calculated_fuel_consumption'=>['rateLph'=>[0,1000], 'instantL100km'=>[0,1000000], 'totalLitres'=>[0,100000000], 'distanceKm'=>[0,100000000], 'averageL100km'=>[0,1000000], 'averageKmPerL'=>[0,1000000]],
    ];
    public const BOOLEANS = ['gps.fix_valid','vehicle.obd_connected','vehicle.engine_running','vehicle.mil_on','vehicle.has_dtc_fault','network.roaming','network.connected','device_health.ble_obd_connected','device_health.lte_connected','driving_behaviour.harsh_braking','driving_behaviour.rapid_acceleration','driving_behaviour.sharp_cornering'];
    public const STRINGS = ['network.modem_model','network.imei','network.imsi','network.registered_plmn','network.operator','network.network_type','network.registration_status','network.cell_id','network.tac','driving_behaviour.sensor','driver_session.driver_id'];
    public const OBJECTS = ['gateway','network','gps','vehicle','device_health','driving_behaviour','driver_session','driving_behaviour.acceleration','driving_behaviour.gyroscope','driving_behaviour.threshold','vehicle.calculated_fuel_consumption'];
    public const TIMESTAMPS = ['timestamp','gps.timestamp','driver_session.started_at','driver_session.ended_at','device_health.last_sync_at'];

    public function reason(array $message, object $object): ?string
    {
        $rules = ['message_type'=>'required|in:telemetry', 'event_id'=>'required|uuid', 'timestamp'=>'required|string',
            'gateway'=>'required|array', 'gateway.device_id'=>'required|string|max:160', 'gateway.vehicle_id'=>'required|string|max:32', 'gateway.edge_version'=>'required|string|max:100',
            'vehicle.dtc_codes'=>'sometimes|array|max:100', 'vehicle.dtc_codes.*'=>['string','regex:/^[PCBU][0-3][0-9A-Fa-f]{3}$/D'],
            'vehicle.calculated_fuel_consumption.rateSource'=>'sometimes|nullable|in:direct,maf,unknown',
            'driver_session.session_id'=>'sometimes|nullable|uuid', 'driver_session.status'=>'sometimes|nullable|in:active,ended,inactive,unknown'];
        $allowed = ['message_type','event_id','timestamp'];
        foreach (self::OBJECTS as $path) { $rules[$path] ??= 'sometimes|array'; $allowed[] = $path; }
        foreach (self::NUMBERS as $path=>$metrics) foreach ($metrics as $key=>[$min,$max]) {
            $name="$path.$key"; $allowed[]=$name;
            $rules[$name]=['sometimes','nullable',function ($attribute,$value,$fail) use ($min,$max) {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max) $fail('Expected a bounded JSON number.');
            }];
        }
        foreach (self::BOOLEANS as $path) { $allowed[]=$path; $rules[$path]=['sometimes','nullable',function ($attribute,$value,$fail) { if (!is_bool($value)) $fail('Expected a JSON boolean.'); }]; }
        foreach (self::STRINGS as $path) { $allowed[]=$path; $rules[$path]='sometimes|nullable|string|max:160'; }
        foreach (self::TIMESTAMPS as $path) {
            $allowed[]=$path; $rules[$path] ??= 'sometimes|nullable|string';
            $value=data_get($message,$path);
            if ($value !== null && !self::utcTimestamp($value)) return 'invalid_timestamp';
        }
        foreach (array_keys($rules) as $path) if (!str_contains($path,'*')) $allowed[]=$path;
        foreach (self::OBJECTS as $path) {
            $value=data_get($object,$path);
            if ($value !== null && !is_object($value)) return 'invalid_pi_object';
            if (is_object($value)) foreach (array_keys(get_object_vars($value)) as $key) if (!in_array("$path.$key",$allowed,true)) return 'unknown_pi_field';
        }
        foreach (array_keys($message) as $key) if (!in_array($key,$allowed,true)) return 'unknown_pi_field';
        if (Validator::make($message,$rules)->fails()) return 'invalid_pi_packet';
        if (isset($message['vehicle']['dtc_codes']) && (!is_array($object->vehicle->dtc_codes) || !array_is_list($message['vehicle']['dtc_codes']))) return 'invalid_pi_dtc_list';
        if (($message['gps']['fix_valid'] ?? false) === true && (!isset($message['gps']['latitude'],$message['gps']['longitude'],$message['gps']['timestamp']))) return 'invalid_pi_gps';
        return null;
    }

    public static function utcTimestamp(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D',$value)) return false;
        try { return CarbonImmutable::parse($value)->format('Y-m-d\TH:i:s') === substr($value,0,19); }
        catch (\Throwable $e) { return false; }
    }
}
