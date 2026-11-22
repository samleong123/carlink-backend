<?php

namespace App\Services;

use Illuminate\Http\Request;

/** Bounded client claims, not authentication or a unique-device identifier. */
class BrowserMetadataResolver
{
    public function resolve(Request $request): array
    {
        $raw = []; $truncated = false;
        foreach (['User-Agent', 'Sec-CH-UA', 'Sec-CH-UA-Full-Version-List', 'Sec-CH-UA-Platform', 'Sec-CH-UA-Mobile'] as $name) {
            $value = $request->header($name);
            $truncated = $truncated || (is_string($value) && strlen($value) > 4096);
            $raw[strtolower($name)] = is_string($value) ? substr(preg_replace('/[\x00-\x1f\x7f]/', '', $value), 0, 4096) : null;
        }
        $result = ['user_agent_raw' => $raw['user-agent'], 'client_hints' => array_diff_key($raw, ['user-agent' => true]),
            'browser_name' => null, 'browser_version' => null, 'browser_version_precision' => 'unknown', 'browser_version_source' => 'unavailable',
            'user_agent_parser_version' => 'carlink-conservative-v1', 'platform_name' => null, 'is_mobile' => null, 'headers_truncated' => $truncated];
        foreach (['sec-ch-ua-full-version-list' => ['full', 'client_hints_full'], 'sec-ch-ua' => ['major', 'client_hints']] as $header => [$precision, $source]) {
            preg_match_all('/"((?:[^"\\\\]|\\\\.)+)"\s*;\s*v="([0-9]+(?:\.[0-9]+)*)"/', $raw[$header] ?? '', $matches, PREG_SET_ORDER);
            $brands = [];
            foreach ($matches as $match) $brands[$match[1]] = $match[2];
            foreach (['Microsoft Edge', 'Google Chrome', 'Opera', 'Chromium'] as $brand) if (isset($brands[$brand])) {
                $result['browser_name'] = $brand; $result['browser_version'] = substr($brands[$brand], 0, 100);
                $result['browser_version_precision'] = $precision === 'full' && str_contains($brands[$brand], '.') ? 'full' : 'major';
                $result['browser_version_source'] = $source; break 2;
            }
        }
        if (!$result['browser_name']) {
            foreach (['Edg' => 'Microsoft Edge', 'Firefox' => 'Firefox', 'Chrome' => 'Google Chrome', 'Version' => 'Safari'] as $token => $name) {
                if (preg_match('#\b'.$token.'/([0-9]+(?:\.[0-9]+)*)#', $raw['user-agent'] ?? '', $match)) {
                    $result['browser_name'] = $name; $result['browser_version'] = $match[1];
                    $result['browser_version_precision'] = 'reduced'; $result['browser_version_source'] = 'user_agent'; break;
                }
            }
        }
        if (preg_match('/^"([A-Za-z0-9 ._-]{1,80})"$/D', $raw['sec-ch-ua-platform'] ?? '', $platform)) $result['platform_name'] = $platform[1];
        $result['is_mobile'] = match ($raw['sec-ch-ua-mobile']) { '?1' => true, '?0' => false, default => null };
        return $result;
    }
}
