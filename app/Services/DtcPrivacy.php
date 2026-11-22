<?php

namespace App\Services;

class DtcPrivacy
{
    private const CODE_RE = '/^[PCBU][0-3][0-9A-Fa-f]{3}$/D';

    /** Normalize valid OBD-II DTC codes and scrub internal AI/raw-scan fields. */
    public function scrub(mixed $value): mixed
    {
        if (is_object($value)) {
            $result = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                if ($key === 'has_dtc_fault') {
                    $result->$key = ($item === true) || ($result->$key ?? false);
                    continue;
                }
                if ($key === 'dtc_codes') {
                    $codes = $this->normalizeCodes($item);
                    $result->dtc_codes = $codes;
                    $result->has_dtc_fault = count($codes) > 0 || ($result->has_dtc_fault ?? false);
                    continue;
                }
                if (preg_match('/^(?:dtc_ai|fault_codes|diagnostic_codes|scan_raw|scan_result)$/i', $key)) {
                    continue;
                }
                $result->$key = $this->scrub($item);
            }
            return $result;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if ($key === 'has_dtc_fault') {
                    $result[$key] = ($item === true) || ($result[$key] ?? false);
                    continue;
                }
                if ($key === 'dtc_codes') {
                    $codes = $this->normalizeCodes($item);
                    $result['dtc_codes'] = $codes;
                    $result['has_dtc_fault'] = count($codes) > 0 || ($result['has_dtc_fault'] ?? false);
                    continue;
                }
                if (is_string($key) && preg_match('/^(?:dtc_ai|fault_codes|diagnostic_codes|scan_raw|scan_result)$/i', $key)) {
                    continue;
                }
                $result[$key] = $this->scrub($item);
            }
            return $result;
        }
        return $value;
    }

    private function normalizeCodes(mixed $item): array
    {
        if (!is_array($item)) {
            return [];
        }
        $upper = array_map(fn ($c) => is_string($c) ? strtoupper($c) : null, $item);
        $codes = array_values(array_unique(array_filter(
            $upper,
            fn ($c) => is_string($c) && preg_match(self::CODE_RE, $c),
        )));
        return array_slice($codes, 0, 100);
    }
}
