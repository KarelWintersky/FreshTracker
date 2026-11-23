<?php

declare(strict_types=1);

namespace FreshTracker\Access;

use FreshTracker\AppDatabase;

final class Throttle
{
    public function __construct(
        private readonly AppDatabase $db,
        private readonly int $maxAttempts = 8,
        private readonly int $windowSeconds = 900,
    ) {
    }

    public function tooMany(string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - $this->windowSeconds);
        $count = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?',
            [$ip, $since]
        );

        return $count >= $this->maxAttempts;
    }

    public function hit(string $ip): void
    {
        $this->db->execute(
            'INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)',
            [$ip, date('Y-m-d H:i:s')]
        );

        $this->db->execute(
            'DELETE FROM login_attempts WHERE attempted_at < ?',
            [date('Y-m-d H:i:s', time() - $this->windowSeconds * 4)]
        );
    }

    public function clear(string $ip): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
    }
}
