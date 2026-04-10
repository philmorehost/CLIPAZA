<?php
declare(strict_types=1);

class BruteForceProtection {

    public static function recordAttempt(string $username, string $ip, bool $success): void {
        try {
            $db = db();
            $stmt = $db->prepare(
                'INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)'
            );
            $stmt->execute([$username, $ip, $success ? 1 : 0]);

            if (!$success) {
                self::checkAndApplyLocks($username, $ip);
            } else {
                self::clearAttempts($username, $ip);
            }
        } catch (Throwable) {}
    }

    private static function checkAndApplyLocks(string $username, string $ip): void {
        $ipEnabled    = getSecuritySetting('ip_protection_enabled', '1') === '1';
        $userEnabled  = getSecuritySetting('username_protection_enabled', '1') === '1';
        $ipPeriod     = (int)getSecuritySetting('ip_period_minutes', '15');
        $ipMax        = (int)getSecuritySetting('ip_max_failures', '10');
        $userPeriod   = (int)getSecuritySetting('username_period_minutes', '30');
        $userMax      = (int)getSecuritySetting('username_max_failures', '5');
        $blockMinutes = (int)getSecuritySetting('block_duration_minutes', '60');

        if ($ipEnabled) {
            $ipCount = self::countRecentAttempts(null, $ip, $ipPeriod);
            if ($ipCount >= $ipMax && !self::isIpBlocked($ip)) {
                self::blockIp($ip, 'temporary', $blockMinutes, 'Automatic: too many failed login attempts');
            }
        }

        if ($userEnabled) {
            $userCount = self::countRecentAttempts($username, null, $userPeriod);
            if ($userCount >= $userMax && !self::isAccountLocked($username)) {
                self::lockAccount($username, $blockMinutes, 'Automatic: too many failed login attempts');
            }
        }
    }

    private static function countRecentAttempts(?string $username, ?string $ip, int $minutes): int {
        try {
            $db = db();
            if ($username !== null) {
                $stmt = $db->prepare(
                    'SELECT COUNT(*) FROM login_attempts
                     WHERE username = ? AND success = 0
                     AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
                );
                $stmt->execute([$username, $minutes]);
            } else {
                $stmt = $db->prepare(
                    'SELECT COUNT(*) FROM login_attempts
                     WHERE ip_address = ? AND success = 0
                     AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
                );
                $stmt->execute([$ip, $minutes]);
            }
            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public static function isIpBlocked(string $ip): bool {
        try {
            $db = db();
            $stmt = $db->prepare(
                'SELECT id FROM ip_blocks
                 WHERE ip_address = ?
                 AND (blocked_until IS NULL OR blocked_until > NOW())
                 LIMIT 1'
            );
            $stmt->execute([$ip]);
            return (bool)$stmt->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    public static function isAccountLocked(string $username): bool {
        try {
            $db = db();
            $stmt = $db->prepare(
                'SELECT id FROM account_locks
                 WHERE username = ?
                 AND (locked_until IS NULL OR locked_until > NOW())
                 LIMIT 1'
            );
            $stmt->execute([$username]);
            return (bool)$stmt->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    public static function blockIp(
        string $ip,
        string $type = 'temporary',
        int $durationMinutes = 60,
        string $reason = '',
        ?int $createdBy = null
    ): bool {
        try {
            $db = db();
            $until = $type === 'permanent' ? null
                : date('Y-m-d H:i:s', time() + $durationMinutes * 60);
            $stmt = $db->prepare(
                'INSERT INTO ip_blocks (ip_address, block_type, blocked_until, reason, created_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   block_type = VALUES(block_type),
                   blocked_until = VALUES(blocked_until),
                   reason = VALUES(reason),
                   blocked_at = NOW()'
            );
            return $stmt->execute([$ip, $type, $until, $reason, $createdBy]);
        } catch (Throwable) {
            return false;
        }
    }

    public static function unblockIp(string $ip): bool {
        try {
            $db = db();
            $stmt = $db->prepare('DELETE FROM ip_blocks WHERE ip_address = ?');
            return $stmt->execute([$ip]);
        } catch (Throwable) {
            return false;
        }
    }

    public static function lockAccount(string $username, int $durationMinutes = 60, string $reason = ''): bool {
        try {
            $db = db();
            $until = date('Y-m-d H:i:s', time() + $durationMinutes * 60);
            $stmt = $db->prepare(
                'INSERT INTO account_locks (username, locked_until, lock_reason)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until), lock_reason = VALUES(lock_reason), locked_at = NOW()'
            );
            return $stmt->execute([$username, $until, $reason]);
        } catch (Throwable) {
            return false;
        }
    }

    public static function unlockAccount(string $username): bool {
        try {
            $db = db();
            $stmt = $db->prepare('DELETE FROM account_locks WHERE username = ?');
            return $stmt->execute([$username]);
        } catch (Throwable) {
            return false;
        }
    }

    private static function clearAttempts(string $username, string $ip): void {
        try {
            $db = db();
            $stmt = $db->prepare('DELETE FROM login_attempts WHERE username = ? OR ip_address = ?');
            $stmt->execute([$username, $ip]);
        } catch (Throwable) {}
    }

    public static function isIpWhitelisted(string $ip): bool {
        try {
            $db = db();
            $stmt = $db->prepare('SELECT id FROM ip_whitelist WHERE ip_address = ? LIMIT 1');
            $stmt->execute([$ip]);
            return (bool)$stmt->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    public static function getBlockedIps(int $limit = 100): array {
        try {
            $db = db();
            $stmt = $db->prepare(
                'SELECT b.*, u.username as blocked_by_user
                 FROM ip_blocks b
                 LEFT JOIN users u ON u.id = b.created_by
                 WHERE b.blocked_until IS NULL OR b.blocked_until > NOW()
                 ORDER BY b.blocked_at DESC
                 LIMIT ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    public static function getLockedAccounts(): array {
        try {
            $db = db();
            $stmt = $db->query(
                'SELECT * FROM account_locks
                 WHERE locked_until IS NULL OR locked_until > NOW()
                 ORDER BY locked_at DESC'
            );
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
