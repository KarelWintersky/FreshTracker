<?php

declare(strict_types=1);

namespace FreshTracker\Access;

use Arris\Toolkit\FireWall;
use Psr\Log\LoggerInterface;

final class AccessPolicy
{
    private FireWall $localRanges;

    /** @var list<array{address: string, bits: int}> */
    private array $localV6 = [];

    private bool $localVisible;
    private string $localPassword;
    private bool $publicVisible;
    private string $publicPassword;
    private string $publicEditable;

    public function __construct(
        array $localNetworks,
        bool $localVisible,
        string $localPassword,
        bool $publicVisible,
        string $publicPassword,
        string $publicEditable,
        private readonly LoggerInterface $logger,
    ) {
        $this->localVisible = $localVisible;
        $this->localPassword = $localPassword;
        $this->publicVisible = $publicVisible;
        $this->publicPassword = $publicPassword;
        $this->publicEditable = strtolower($publicEditable);

        $this->localRanges = new FireWall(defaultState: false);
        $ipv4 = [];

        foreach ($localNetworks as $network) {
            if (!is_string($network) || $network === '') {
                continue;
            }

            if (str_contains($network, ':')) {
                if ($this->addIpv6Range($network)) {
                    continue;
                }
                $this->logger->warning('Ignoring invalid IPv6 entry in access.local.networks', ['network' => $network]);
                continue;
            }

            $ipv4[] = $network;
        }

        foreach ($ipv4 as $network) {
            try {
                $this->localRanges->addWhiteList($network);
            } catch (\InvalidArgumentException) {
                $this->logger->warning('Ignoring invalid access.local.networks entry', ['network' => $network]);
            }
        }
    }

    public static function fromConfig(array $access, LoggerInterface $logger): self
    {
        $local = $access['local'] ?? null;
        if (!is_array($local)) {
            $legacyAdmin = $access['admin'] ?? null;
            $logger->notice('access.local is not configured, using legacy access.admin as local networks');
            $local = [
                'networks'  => is_array($legacyAdmin) ? array_values($legacyAdmin) : ['127.0.0.1', '::1'],
                'visible'   => true,
                'password'  => '',
            ];
        }

        $public = $access['public'] ?? null;
        if (!is_array($public)) {
            $public = [
                'visible'   => 'always',
                'password'  => '',
                'editable'  => 'never',
            ];
        }

        $networks = $local['networks'] ?? ['127.0.0.1', '::1'];
        if (is_string($networks)) {
            $networks = [$networks];
        }

        $publicVisible = $public['visible'] ?? 'always';
        if (is_string($publicVisible)) {
            $publicVisible = !in_array(strtolower($publicVisible), ['false', 'never', 'hidden', 'no', '0'], true);
        }

        return new self(
            array_values(array_filter((array) $networks, 'is_string')),
            (bool) ($local['visible'] ?? true),
            (string) ($local['password'] ?? ''),
            (bool) $publicVisible,
            (string) ($public['password'] ?? ''),
            (string) ($public['editable'] ?? 'never'),
            $logger,
        );
    }

    public function context(string $ip, EditSession $session): AccessContext
    {
        if ($this->isLocal($ip)) {
            if (!$this->localVisible) {
                return AccessContext::denied();
            }

            return new AccessContext(
                'local',
                true,
                $this->localPassword === '' || $session->hasGrant('local'),
                $this->localPassword === '' ? '' : 'local',
            );
        }

        if (!$this->publicVisible) {
            return AccessContext::denied();
        }

        if ($this->publicEditable === 'always') {
            return new AccessContext('public', true, true, '');
        }

        if ($this->publicEditable === 'password' && $this->publicPassword !== '') {
            return new AccessContext('public', true, $session->hasGrant('public'), 'public');
        }

        return new AccessContext('public', true, false, '');
    }

    public function canRequestPassword(string $scope): bool
    {
        return match ($scope) {
            'local'  => $this->localPassword !== '',
            'public' => $this->publicVisible
                && $this->publicEditable === 'password'
                && $this->publicPassword !== '',
            default  => false,
        };
    }

    public function verifyPassword(string $scope, string $password): bool
    {
        $expected = match ($scope) {
            'local'  => $this->localPassword,
            'public' => $this->publicPassword,
            default  => '',
        };

        if ($expected === '' || $password === '') {
            return false;
        }

        return hash_equals($expected, $password);
    }

    public function isLocal(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isLocalIpv6($ip);
        }

        try {
            return $this->localRanges->validate($ip)->isAllowed();
        } catch (\Throwable) {
            return false;
        }
    }

    private function addIpv6Range(string $range): bool
    {
        $bits = 128;
        $address = $range;

        if (str_contains($range, '/')) {
            [$address, $rawBits] = explode('/', $range, 2);
            if (!ctype_digit($rawBits) || (int) $rawBits < 0 || (int) $rawBits > 128) {
                return false;
            }
            $bits = (int) $rawBits;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $this->localV6[] = ['address' => $address, 'bits' => $bits];

        return true;
    }

    private function isLocalIpv6(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($this->localV6 as $range) {
            $subnet = inet_pton($range['address']);
            if ($subnet === false) {
                continue;
            }

            $bits = $range['bits'];
            $fullBytes = intdiv($bits, 8);

            if ($fullBytes > 0 && substr_compare($packed, $subnet, 0, $fullBytes) !== 0) {
                continue;
            }

            $remainder = $bits % 8;
            if ($remainder === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $remainder)) & 0xFF;
            if ((ord($packed[$fullBytes]) & $mask) === (ord($subnet[$fullBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
