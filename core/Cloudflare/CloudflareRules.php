<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

/** Safe form-to-Rulesets builder for Cloudflare Free-plan features. */
final class CloudflareRules
{
    public const KINDS = [
        'redirect' => ['phase' => 'http_request_dynamic_redirect', 'limit' => 10, 'label' => 'Redirect rules'],
        'cache' => ['phase' => 'http_request_cache_settings', 'limit' => 10, 'label' => 'Cache rules'],
        'firewall' => ['phase' => 'http_request_firewall_custom', 'limit' => 5, 'label' => 'Firewall rules'],
    ];

    public const MATCHES = [
        'all' => 'All requests', 'host' => 'Hostname is', 'path' => 'URL path is',
        'path_prefix' => 'URL path starts with', 'path_contains' => 'URL path contains',
        'extension' => 'File extension is one of', 'country' => 'Visitor country is one of',
        'ip' => 'Visitor IP is one of', 'asn' => 'Visitor network (AS number) is one of',
        'user_agent' => 'User agent contains',
    ];

    public const KIND_MATCHES = [
        'redirect' => ['all', 'host', 'path', 'path_prefix', 'path_contains'],
        'cache' => ['all', 'host', 'path', 'path_prefix', 'path_contains', 'extension'],
        'firewall' => ['host', 'path', 'path_prefix', 'path_contains', 'country', 'ip', 'asn', 'user_agent'],
    ];

    public const REDIRECT_CODES = [301, 302, 307, 308];
    public const FIREWALL_ACTIONS = [
        'block' => 'Block', 'managed_challenge' => 'Managed challenge',
        'js_challenge' => 'JavaScript challenge', 'challenge' => 'Interactive challenge',
    ];
    public const EDGE_TTLS = [0 => 'Use origin headers', 3600 => '1 hour', 7200 => '2 hours', 86400 => '1 day', 604800 => '7 days', 2592000 => '30 days'];

    /** @return array<string, array{kind:string,label:string,input:array<string,string>}> */
    public static function presets(string $zone): array
    {
        return [
            'www_to_apex' => ['kind' => 'redirect', 'label' => 'Redirect www to ' . $zone, 'input' => ['description' => 'www to ' . $zone, 'match' => 'host', 'value' => 'www.' . $zone, 'target_url' => 'https://' . $zone, 'status_code' => '301', 'keep_path' => '1', 'preserve_query' => '1']],
            'apex_to_www' => ['kind' => 'redirect', 'label' => 'Redirect ' . $zone . ' to www', 'input' => ['description' => 'Apex to www', 'match' => 'host', 'value' => $zone, 'target_url' => 'https://www.' . $zone, 'status_code' => '301', 'keep_path' => '1', 'preserve_query' => '1']],
            'bypass_admin' => ['kind' => 'cache', 'label' => 'Never cache /wp-admin (WordPress)', 'input' => ['description' => 'Bypass cache for wp-admin', 'match' => 'path_prefix', 'value' => '/wp-admin', 'cache_mode' => 'bypass']],
            'cache_static' => ['kind' => 'cache', 'label' => 'Cache images, CSS and JS for 7 days', 'input' => ['description' => 'Cache static files', 'match' => 'extension', 'value' => 'jpg, jpeg, png, gif, webp, svg, css, js, woff2', 'cache_mode' => 'cache', 'edge_ttl' => '604800']],
            'challenge_login' => ['kind' => 'firewall', 'label' => 'Challenge visitors to wp-login.php', 'input' => ['description' => 'Protect WordPress login', 'match' => 'path', 'value' => '/wp-login.php', 'action' => 'managed_challenge']],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|null */
    public static function build(string $kind, array $input, string $zone, ?string &$error = null): ?array
    {
        $error = null;

        if (!isset(self::KINDS[$kind])) {
            $error = 'Unknown rule type.';
            return null;
        }

        $match = (string) ($input['match'] ?? '');
        if (!in_array($match, self::KIND_MATCHES[$kind], true)) {
            $error = 'Choose when the rule applies.';
            return null;
        }

        $value = trim((string) ($input['value'] ?? ''));
        $expression = self::expression($match, $value, $zone, $error);
        if ($expression === null) {
            return null;
        }

        $description = trim((string) preg_replace('/[\x00-\x1f"\\\\]+/', ' ', (string) ($input['description'] ?? '')));
        if ($description === '') {
            $description = ucfirst($kind) . ': ' . self::MATCHES[$match] . ($match === 'all' ? '' : ' ' . $value);
        }
        $rule = ['description' => mb_substr($description, 0, 100), 'expression' => $expression, 'enabled' => true];

        if ($kind === 'redirect') {
            $actionParameters = self::redirect($input, $zone, $match, $value, $error);
            return $actionParameters === null ? null : $rule + ['action' => 'redirect', 'action_parameters' => ['from_value' => $actionParameters]];
        }

        if ($kind === 'cache') {
            $mode = (string) ($input['cache_mode'] ?? '');
            if ($mode === 'bypass') {
                return $rule + ['action' => 'set_cache_settings', 'action_parameters' => ['cache' => false]];
            }
            if ($mode !== 'cache') {
                $error = 'Choose whether to cache or bypass the cache.';
                return null;
            }
            $ttl = (int) ($input['edge_ttl'] ?? 0);
            if (!array_key_exists($ttl, self::EDGE_TTLS)) {
                $error = 'Choose a cache duration.';
                return null;
            }
            $params = ['cache' => true];
            if ($ttl > 0) {
                $params['edge_ttl'] = ['mode' => 'override_origin', 'default' => $ttl];
            }
            return $rule + ['action' => 'set_cache_settings', 'action_parameters' => $params];
        }

        $action = (string) ($input['action'] ?? '');
        if (!isset(self::FIREWALL_ACTIONS[$action])) {
            $error = 'Choose what happens to matching visitors.';
            return null;
        }
        return $rule + ['action' => $action];
    }

    public static function expression(string $match, string $value, string $zone, ?string &$error = null): ?string
    {
        $error = null;
        $value = trim($value);
        if ($match !== 'all' && $value === '') {
            $error = 'Enter a value for the condition.';
            return null;
        }
        if (preg_match('/["\\\\\x00-\x1f]/', $value) === 1) {
            $error = 'Quotes and backslashes are not allowed in rule values.';
            return null;
        }

        switch ($match) {
            case 'all': return 'true';
            case 'host':
                $host = strtolower(rtrim($value, '.'));
                if (($host !== $zone && !str_ends_with($host, '.' . $zone)) || preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host) !== 1) {
                    $error = 'The hostname must be ' . $zone . ' or one of its subdomains.';
                    return null;
                }
                return '(http.host eq "' . $host . '")';
            case 'path':
            case 'path_prefix':
                if (preg_match('#^/[A-Za-z0-9._~%+\-/=&@:,;!*]{0,200}$#', $value) !== 1) {
                    $error = 'Enter a URL path starting with / (letters, numbers, and common URL characters only).';
                    return null;
                }
                return $match === 'path' ? '(http.request.uri.path eq "' . $value . '")' : '(starts_with(http.request.uri.path, "' . $value . '"))';
            case 'path_contains':
                if (preg_match('#^[A-Za-z0-9._~%+\-/=&@:,;!*]{1,100}$#', $value) !== 1) {
                    $error = 'Enter part of a URL path using letters, numbers and common URL characters.';
                    return null;
                }
                return '(http.request.uri.path contains "' . $value . '")';
            case 'extension':
                $items = self::list(strtolower($value), '/^[a-z0-9]{1,10}$/', 20, false);
                if ($items === null) { $error = 'Enter file extensions separated by commas, e.g. jpg, png, css.'; return null; }
                return '(http.request.uri.path.extension in {"' . implode('" "', $items) . '"})';
            case 'country':
                $items = self::list($value, '/^[A-Z][A-Z0-9]$/', 50, true);
                if ($items === null) { $error = 'Enter two-letter country codes separated by commas, e.g. NG, GH.'; return null; }
                return '(ip.src.country in {"' . implode('" "', $items) . '"})';
            case 'ip':
                $items = self::list($value, null, 50, false);
                if ($items === null) { $error = 'Enter IP addresses or ranges separated by commas.'; return null; }
                foreach ($items as $ip) { if (!self::validIpOrCidr($ip)) { $error = 'Enter valid IP addresses or ranges, e.g. 203.0.113.8 or 203.0.113.0/24.'; return null; } }
                return '(ip.src in {' . implode(' ', $items) . '})';
            case 'asn':
                $items = self::list(str_ireplace('AS', '', $value), '/^\d{1,10}$/', 50, true);
                if ($items === null) { $error = 'Enter AS numbers separated by commas, e.g. 13335, AS15169.'; return null; }
                return '(ip.src.asnum in {' . implode(' ', $items) . '})';
            case 'user_agent':
                if (preg_match('/^[A-Za-z0-9 ._\-\/()+;:,]{2,100}$/', $value) !== 1) { $error = 'Enter part of the user agent using letters, numbers and common punctuation.'; return null; }
                return '(http.user_agent contains "' . $value . '")';
        }

        $error = 'Choose when the rule applies.';
        return null;
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|null */
    private static function redirect(array $input, string $zone, string $match, string $value, ?string &$error): ?array
    {
        $url = trim((string) ($input['target_url'] ?? ''));
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 500 || preg_match('/["\\\\\s\x00-\x1f]/', $url) === 1 || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $error = 'Enter a full destination URL, starting with http:// or https://.';
            return null;
        }
        $status = (int) ($input['status_code'] ?? 301);
        if (!in_array($status, self::REDIRECT_CODES, true)) { $error = 'Choose a redirect type.'; return null; }

        $host = strtolower((string) $parts['host']);
        $sameZone = $host === $zone || str_ends_with($host, '.' . $zone);
        if (($match === 'all' && $sameZone) || ($match === 'host' && strtolower(rtrim($value, '.')) === $host)) {
            $error = 'That redirect would send visitors back to the same address in a loop.';
            return null;
        }
        $keep = !empty($input['keep_path']);
        if ($keep) {
            if ($host === $zone && in_array($match, ['all','path','path_prefix','path_contains'], true)) {
                $error='That redirect would send visitors back to a matching address in a loop.';
                return null;
            }
            if (isset($parts['query']) || isset($parts['fragment']) || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
                $error = 'To keep the visitor path, enter only the destination site, without a path or query.';
                return null;
            }
            $base = strtolower((string) $parts['scheme']) . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
            $target = ['expression' => 'concat("' . $base . '", http.request.uri.path)'];
        } else {
            $target = ['value' => $url];
            $targetPath = (string) ($parts['path'] ?? '/');
            if (($match === 'path' && $host === $zone && $targetPath === $value)
                || ($match === 'path_prefix' && $host === $zone && str_starts_with($targetPath, $value))
                || ($match === 'path_contains' && $host === $zone && str_contains($targetPath, $value))) {
                $error = 'That redirect would match its own destination and create a loop.';
                return null;
            }
        }
        return ['status_code' => $status, 'target_url' => $target, 'preserve_query_string' => !empty($input['preserve_query'])];
    }

    /** @return array<int,string>|null */
    private static function list(string $value, ?string $pattern, int $max, bool $upper): ?array
    {
        $items = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $value) ?: []), 'strlen')));
        if ($items === [] || count($items) > $max) { return null; }
        if ($upper) { $items = array_map('strtoupper', $items); }
        if ($pattern !== null) { foreach ($items as $item) { if (preg_match($pattern, $item) !== 1) { return null; } } }
        return $items;
    }

    private static function validIpOrCidr(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) { return true; }
        if (!str_contains($value, '/')) { return false; }
        [$ip, $bits] = explode('/', $value, 2);
        if (!ctype_digit($bits)) { return false; }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) { return (int) $bits >= 8 && (int) $bits <= 32; }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) { return (int) $bits >= 16 && (int) $bits <= 128; }
        return false;
    }

    /** @param array<string,mixed> $rule */
    public static function actionSummary(string $kind, array $rule): string
    {
        $action = (string) ($rule['action'] ?? '');
        $params = (array) ($rule['action_parameters'] ?? []);
        if ($kind === 'redirect' || $action === 'redirect') {
            $from = (array) ($params['from_value'] ?? []);
            $target = (array) ($from['target_url'] ?? []);
            $dest = (string) ($target['value'] ?? '');
            if ($dest === '' && preg_match('/concat\("([^"]+)"/', (string) ($target['expression'] ?? ''), $m)) { $dest = $m[1] . '/…'; }
            return 'Redirect ' . (int) ($from['status_code'] ?? 301) . ' to ' . ($dest ?: '(dynamic destination)');
        }
        if ($kind === 'cache' || $action === 'set_cache_settings') {
            if (($params['cache'] ?? null) === false) { return 'Bypass the cache'; }
            $ttl = (int) ($params['edge_ttl']['default'] ?? 0);
            return 'Cache' . ($ttl ? ' for ' . (self::EDGE_TTLS[$ttl] ?? $ttl . ' seconds') : ' (origin headers)');
        }
        return self::FIREWALL_ACTIONS[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }
}
