<?php
namespace App\Services;

class DtcPrivacy
{
    /** Preserve only presence, never diagnostic codes, raw scan transcripts or AI explanations. */
    public function scrub(mixed $value): mixed
    {
        if (is_object($value)) {
            $result=new \stdClass();
            foreach (get_object_vars($value) as $key=>$item) {
                if ($key === 'has_dtc_fault') { $result->$key=($item === true) || ($result->$key ?? false); continue; }
                if (preg_match('/dtc|fault_codes|diagnostic_codes|scan_raw|scan_result/i',$key)) {
                    if ($key === 'dtc_codes' && is_array($item)) $result->has_dtc_fault=count($item)>0 || ($result->has_dtc_fault ?? false);
                    continue;
                }
                $result->$key=$this->scrub($item);
            }
            return $result;
        }
        if (is_array($value)) {
            $result=[];
            foreach ($value as $key=>$item) {
                if ($key === 'has_dtc_fault') { $result[$key]=($item === true) || ($result[$key] ?? false); continue; }
                if (is_string($key) && preg_match('/dtc|fault_codes|diagnostic_codes|scan_raw|scan_result/i',$key)) {
                    if ($key === 'dtc_codes' && is_array($item)) $result['has_dtc_fault']=count($item)>0 || ($result['has_dtc_fault'] ?? false);
                    continue;
                }
                $result[$key]=$this->scrub($item);
            }
            return $result;
        }
        return $value;
    }
}
