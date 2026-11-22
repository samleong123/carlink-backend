<?php

use App\Services\TrustedClientIpResolver;

$defaultCloudflareProxies = TrustedClientIpResolver::CLOUDFLARE_DEFAULT_PROXIES;

$configuredCloudflare = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_CLOUDFLARE_PROXIES', '')))));

return [
    'cloudflare_default_proxies' => $defaultCloudflareProxies,
    // Explicit peer CIDRs only. Defaults to official Cloudflare IP ranges, merged with custom proxies.
    'cloudflare_proxies' => array_values(array_unique(array_merge($defaultCloudflareProxies, $configuredCloudflare))),
    'forwarded_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_FORWARDED_PROXIES', ''))))),
    'cloudflare_pseudo_ipv4' => (bool) env('CLOUDFLARE_PSEUDO_IPV4', false),
    'disable_default_cloudflare_proxies' => (bool) env('DISABLE_DEFAULT_CLOUDFLARE_PROXIES', false),
];
