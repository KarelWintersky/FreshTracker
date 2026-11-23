<?php

declare(strict_types=1);

namespace FreshTracker\Access;

use Arris\Toolkit\FireWall;

final class ClientIp
{
    /**
     * X-Forwarded-For is trusted only when the direct peer is a configured
     * proxy. The rightmost non-proxy address is used.
     */
    public static function resolve(array $trustedProxies): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if ($trustedProxies === [] || !self::isTrusted($remote, $trustedProxies)) {
            return $remote;
        }

        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded === '') {
            return $remote;
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(',', $forwarded)),
            static fn (string $part): bool => $part !== ''
        ));

        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $candidate = $parts[$i];
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (self::isTrusted($candidate, $trustedProxies)) {
                continue;
            }

            return $candidate;
        }

        return $remote;
    }

    private static function isTrusted(string $ip, array $proxies): bool
    {
        $ipv4 = [];
        $exact = [];

        foreach ($proxies as $proxy) {
            if (!is_string($proxy) || $proxy === '') {
                continue;
            }
            if (filter_var($proxy, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $exact[] = $proxy;
            } else {
                $ipv4[] = $proxy;
            }
        }

        if (in_array($ip, $exact, true)) {
            return true;
        }

        if ($ipv4 === []) {
            return false;
        }

        try {
            return (new FireWall(defaultState: false))
                ->addWhiteList($ipv4)
                ->validate($ip)
                ->isAllowed();
        } catch (\Throwable) {
            return false;
        }
    }
}
