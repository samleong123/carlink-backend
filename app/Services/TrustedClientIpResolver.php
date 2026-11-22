<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class TrustedClientIpResolver
{
    public const CLOUDFLARE_DEFAULT_PROXIES = [
        // Official Cloudflare IPv4 CIDRs
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // Official Cloudflare IPv6 CIDRs
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    private const PRIVATE_AND_LOOPBACK_PROXIES = [
        '127.0.0.0/8',
        '::1/128',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
    ];

    private const LOOPBACK_PROXIES = [
        '127.0.0.0/8',
        '::1/128',
    ];

    private const FALLBACK_COUNTRY_MAP = [
        'MY' => 'Malaysia',
        'SG' => 'Singapore',
        'ID' => 'Indonesia',
        'TH' => 'Thailand',
        'VN' => 'Vietnam',
        'PH' => 'Philippines',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'CA' => 'Canada',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'JP' => 'Japan',
        'CN' => 'China',
        'HK' => 'Hong Kong',
        'TW' => 'Taiwan',
        'KR' => 'South Korea',
        'IN' => 'India',
        'DE' => 'Germany',
        'FR' => 'France',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'NL' => 'Netherlands',
        'CH' => 'Switzerland',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'DK' => 'Denmark',
        'FI' => 'Finland',
        'IE' => 'Ireland',
        'BE' => 'Belgium',
        'AT' => 'Austria',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'GR' => 'Greece',
        'TR' => 'Turkey',
        'RU' => 'Russia',
        'UA' => 'Ukraine',
        'BR' => 'Brazil',
        'MX' => 'Mexico',
        'AR' => 'Argentina',
        'CL' => 'Chile',
        'CO' => 'Colombia',
        'ZA' => 'South Africa',
        'EG' => 'Egypt',
        'NG' => 'Nigeria',
        'KE' => 'Kenya',
        'AE' => 'United Arab Emirates',
        'SA' => 'Saudi Arabia',
        'QA' => 'Qatar',
        'KW' => 'Kuwait',
    ];

    public function resolve(Request $request): array
    {
        $peer = $this->ip($request->server('REMOTE_ADDR'));
        $result = [
            'ip_address' => $peer,
            'peer_ip' => $peer,
            'ip_country' => null,
            'ip_country_name' => null,
            'ip_country_display' => null,
            'ip_source' => $peer ? 'remote_peer' : 'unavailable',
            'proxy_chain' => [],
            'cf_ray' => null,
        ];

        if (!$peer) {
            return $result;
        }

        $configuredCloudflare = array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : '',
            (array) config('ingress.cloudflare_proxies', [])
        )));
        $cloudflareRanges = config('ingress.disable_default_cloudflare_proxies', false)
            ? $configuredCloudflare
            : array_values(array_unique(array_merge(self::CLOUDFLARE_DEFAULT_PROXIES, $configuredCloudflare)));
        $forwardedRanges = array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : '',
            (array) config('ingress.forwarded_proxies', [])
        )));

        if ($this->trusted($peer, $cloudflareRanges)) {
            return $this->resolveCloudflare($request, $result, $cloudflareRanges, $forwardedRanges);
        }

        if ($this->trusted($peer, $forwardedRanges)) {
            $ips = $this->parseForwardedChain($request, $peer);
            if ($ips === null) {
                return $result;
            }
            $result['proxy_chain'] = $ips;
            // Walk right-to-left; never trust the arbitrary leftmost claim.
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                $hop = $ips[$i];
                if ($this->trusted($hop, $forwardedRanges)) {
                    continue;
                }
                if ($this->trusted($hop, $cloudflareRanges)) {
                    return $this->resolveCloudflare($request, $result, $cloudflareRanges, $forwardedRanges);
                }
                $result['ip_address'] = $hop;
                $result['ip_source'] = 'trusted_forwarded';
                break;
            }
            return $result;
        }

        if ($this->trusted($peer, self::PRIVATE_AND_LOOPBACK_PROXIES)) {
            $ips = $this->parseForwardedChain($request, $peer);
            if ($ips === null) {
                return $result;
            }
            $isLoopback = $this->trusted($peer, self::LOOPBACK_PROXIES);
            $hasCloudflareClientHeader = $this->headerIp($request, 'CF-Connecting-IP') !== null
                || $this->headerIp($request, 'True-Client-IP') !== null;

            for ($i = count($ips) - 1; $i >= 0; $i--) {
                $hop = $ips[$i];
                if ($this->trusted($hop, self::PRIVATE_AND_LOOPBACK_PROXIES)) {
                    continue;
                }
                if ($this->trusted($hop, $cloudflareRanges) && (!$isLoopback || $hasCloudflareClientHeader)) {
                    $result['proxy_chain'] = $ips;
                    return $this->resolveCloudflare(
                        $request,
                        $result,
                        $cloudflareRanges,
                        array_values(array_unique(array_merge($forwardedRanges, self::PRIVATE_AND_LOOPBACK_PROXIES)))
                    );
                }
                break;
            }
        }

        return $result;
    }

    private function resolveCloudflare(Request $request, array $result, array $cloudflareRanges, array $forwardedRanges): array
    {
        $visitor = $this->headerIp($request, 'CF-Connecting-IP');
        if (config('ingress.cloudflare_pseudo_ipv4')) {
            $visitor = $this->headerIp($request, 'CF-Connecting-IPv6') ?? $visitor;
        }
        if (!$visitor) {
            $visitor = $this->headerIp($request, 'True-Client-IP');
        }
        if (!$visitor) {
            $peer = $result['peer_ip'];
            $ips = is_string($peer) ? $this->parseForwardedChain($request, $peer) : null;
            if ($ips !== null) {
                $skipRanges = array_merge($cloudflareRanges, $forwardedRanges);
                for ($i = count($ips) - 1; $i >= 0; $i--) {
                    if (!$this->trusted($ips[$i], $skipRanges)) {
                        $visitor = $ips[$i];
                        break;
                    }
                }
                $result['proxy_chain'] = $ips;
            }
        }

        if ($visitor) {
            $result['ip_address'] = $visitor;
            $result['ip_source'] = 'trusted_cloudflare';
        }

        $cfRay = trim((string) $request->header('CF-Ray'));
        $result['cf_ray'] = preg_match('/^[a-fA-F0-9]{1,32}(?:-[A-Z]{3})?$/D', $cfRay) ? $cfRay : null;

        $rawCountry = strtoupper(trim((string) $request->header('CF-IPCountry')));
        if (preg_match('/^[A-Z0-9]{2}$/D', $rawCountry)) {
            $result['ip_country'] = $rawCountry;
            $countryName = $this->resolveCountryName($rawCountry);
            $result['ip_country_name'] = $countryName;
            $result['ip_country_display'] = match ($rawCountry) {
                'XX' => 'Unknown (XX)',
                'T1' => 'Tor Network (T1)',
                default => $countryName ? "{$countryName} ({$rawCountry})" : $rawCountry,
            };
        }

        return $result;
    }

    private function parseForwardedChain(Request $request, string $peer): ?array
    {
        $raw = (string) $request->header('X-Forwarded-For');
        if (trim($raw) === '' || strlen($raw) > 2048) {
            return null;
        }
        $chain = explode(',', $raw);
        if (count($chain) > 16) {
            return null;
        }
        $ips = array_map(fn ($value) => $this->ip($value), $chain);
        if (in_array(null, $ips, true)) {
            return null;
        }
        $ips[] = $peer;
        return $ips;
    }

    private function headerIp(Request $request, string $header): ?string
    {
        $raw = $request->header($header);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        foreach (explode(',', $raw) as $part) {
            $ip = $this->ip($part);
            if ($ip !== null) {
                return $ip;
            }
        }
        return null;
    }

    private function resolveCountryName(string $code): ?string
    {
        if ($code === 'XX') {
            return 'Unknown';
        }
        if ($code === 'T1') {
            return 'Tor Network';
        }

        $name = null;
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion("-{$code}", 'en');
            if ($name === $code || $name === '') {
                $name = null;
            }
        }

        return $name ?: (self::FALLBACK_COUNTRY_MAP[$code] ?? null);
    }

    private function ip(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed !== '' && filter_var($trimmed, FILTER_VALIDATE_IP) ? $trimmed : null;
    }

    private function trusted(string $ip, array $ranges): bool
    {
        return $ranges !== [] && IpUtils::checkIp($ip, $ranges);
    }
}
