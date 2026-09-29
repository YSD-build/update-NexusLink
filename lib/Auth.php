<?php
/**
 * 蓝天内网穿透 · 鉴权
 * ===========================================================================
 * db.php 里已有 requireAuth() / requireRole() / currentPrincipal() 的完整实现
 * （它们与 hasColumn、会话过期校验紧密耦合，且被 api.php 的历史代码依赖）。
 *
 * 本文件**不重复实现**那套逻辑，而是：
 *   1. 提供面向控制器的新式 API（更明确的语义、更易读）
 *   2. 承载 v1.1.5 新增的登录安全能力（失败锁定 / 2FA 校验 / 登录日志）
 *
 * 这样既保住了老路径的兼容性，又让新代码有干净的入口。
 * ===========================================================================
 */

if (defined('LT_AUTH_LOADED')) {
    return;
}
define('LT_AUTH_LOADED', 1);

final class Auth
{
    /** 登录失败：同账号在窗口期内允许的最大失败次数 */
    public const MAX_FAILS_PER_USER = 5;

    /** 登录失败：同 IP 在窗口期内允许的最大失败次数 */
    public const MAX_FAILS_PER_IP = 20;

    /** 锁定窗口（秒）。账号锁定 15 分钟，IP 锁定 1 小时。 */
    public const LOCK_USER_SECONDS = 900;
    public const LOCK_IP_SECONDS   = 3600;

    /** 触发图形验证码的失败次数阈值 */
    public const CAPTCHA_AFTER_FAILS = 2;

    /* ==================== 主体查询 ==================== */

    /** 当前登录主体（未登录返回 null） */
    public static function principal(): ?array
    {
        return currentPrincipal();
    }

    /** 当前用户名（未登录返回空串） */
    public static function username(): string
    {
        $p = self::principal();
        return (string) ($p['username'] ?? '');
    }

    /** 当前角色 */
    public static function role(): string
    {
        $p = self::principal();
        return (string) ($p['role'] ?? '');
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** 强制要求已登录，否则 401 */
    public static function require(): void
    {
        requireAuth();
    }

    /** 强制要求指定角色之一，否则 403 */
    public static function requireRole(string ...$roles): void
    {
        requireRole(...$roles);
    }

    /* ==================== 登录失败限流 ==================== */

    /**
     * 记录一次登录尝试。
     *
     * 【为什么用数据库而不是 session/文件】
     * 攻击者换一个浏览器就绕过了 session 计数；文件计数又在多进程下
     * 需要加锁。这张表天然是全局一致视图，且顺便成了审计日志。
     */
    public static function recordAttempt(PDO $db, string $username, string $ip, bool $success, string $detail = ''): void
    {
        try {
            $db->prepare(
                'INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)'
            )->execute([$username, $ip, $success ? 1 : 0]);

            // 成功登录时把该账号的失败记录清掉，避免「昨天错 4 次今天再错 1 次就被锁」
            if ($success && $username !== '') {
                $db->prepare(
                    'DELETE FROM login_attempts WHERE username = ? AND success = 0'
                )->execute([$username]);
            }
        } catch (Throwable $e) {
            // 限流表不存在（老库）时不阻断登录
        }
    }

    /**
     * 检查是否处于锁定状态。
     *
     * @return array{locked:bool, scope:string, retryAfter:int, needCaptcha:bool, fails:int}
     */
    public static function checkLock(PDO $db, string $username, string $ip): array
    {
        $out = ['locked' => false, 'scope' => '', 'retryAfter' => 0, 'needCaptcha' => false, 'fails' => 0];

        try {
            // 该账号最近一次失败之后的失败次数
            $st = $db->prepare(
                'SELECT COUNT(*) AS c, MAX(created_at) AS last
                   FROM login_attempts
                  WHERE username = ? AND success = 0
                    AND created_at > NOW() - INTERVAL ? SECOND'
            );
            $st->execute([$username, self::LOCK_USER_SECONDS]);
            $row = $st->fetch() ?: ['c' => 0, 'last' => null];
            $userFails = (int) $row['c'];
            $out['fails'] = $userFails;

            if ($userFails >= self::MAX_FAILS_PER_USER) {
                $last = $row['last'] ? strtotime((string) $row['last']) : time();
                $left = self::LOCK_USER_SECONDS - (time() - $last);
                if ($left > 0) {
                    $out['locked'] = true;
                    $out['scope'] = 'user';
                    $out['retryAfter'] = $left;
                    return $out;
                }
            }

            // 同 IP 失败次数
            $st2 = $db->prepare(
                'SELECT COUNT(*) AS c, MAX(created_at) AS last
                   FROM login_attempts
                  WHERE ip = ? AND success = 0
                    AND created_at > NOW() - INTERVAL ? SECOND'
            );
            $st2->execute([$ip, self::LOCK_IP_SECONDS]);
            $row2 = $st2->fetch() ?: ['c' => 0, 'last' => null];
            if ((int) $row2['c'] >= self::MAX_FAILS_PER_IP) {
                $last2 = $row2['last'] ? strtotime((string) $row2['last']) : time();
                $left2 = self::LOCK_IP_SECONDS - (time() - $last2);
                if ($left2 > 0) {
                    $out['locked'] = true;
                    $out['scope'] = 'ip';
                    $out['retryAfter'] = $left2;
                    return $out;
                }
            }

            $out['needCaptcha'] = $userFails >= self::CAPTCHA_AFTER_FAILS;
        } catch (Throwable $e) {
            // 表不存在：视为无锁定
        }

        return $out;
    }

    /** 写登录日志 */
    public static function logLogin(PDO $db, string $username, string $ip, string $ua, string $result, string $detail = ''): void
    {
        try {
            $db->prepare(
                'INSERT INTO login_logs (username, ip, ua, result, detail) VALUES (?, ?, ?, ?, ?)'
            )->execute([$username, $ip, mb_substr($ua, 0, 255), $result, mb_substr($detail, 0, 255)]);
        } catch (Throwable $e) {
            // 忽略
        }
    }

    /* ==================== 2FA（TOTP） ==================== */

    /** 该用户是否已启用 2FA */
    public static function is2FAEnabled(PDO $db, string $username): bool
    {
        try {
            $st = $db->prepare('SELECT enabled FROM user_2fa WHERE username = ? LIMIT 1');
            $st->execute([$username]);
            return (int) $st->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 校验 TOTP 动态码（RFC 6238）。
     *
     * 允许前后各 1 个时间窗（±30 秒）—— 补偿客户端与服务器的时间偏差，
     * 这是通行做法；再放宽会显著降低安全性。
     */
    public static function verifyTotp(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        $key = self::base32Decode($secret);
        if ($key === '') {
            return false;
        }
        $t = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::totpAt($key, $t + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** 生成当前动态码（供测试与「手动输入密钥」场景校验） */
    public static function currentTotp(string $secret): string
    {
        $key = self::base32Decode($secret);
        return $key === '' ? '' : self::totpAt($key, (int) floor(time() / 30));
    }

    private static function totpAt(string $key, int $counter): string
    {
        $bin = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $off  = ord(substr($hash, -1)) & 0x0F;
        $part = substr($hash, $off, 4);
        $val  = unpack('N', $part)[1] & 0x7FFFFFFF;
        return str_pad((string) ($val % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** 生成新的 Base32 密钥（160 位，符合 RFC 4226 推荐） */
    public static function newTotpSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** 生成 otpauth:// 地址，供二维码使用 */
    public static function otpauthUrl(string $secret, string $account, string $issuer = '蓝天内网穿透'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=6&period=30';
    }

    /** 生成备用码（10 个，用于手机丢失时登录） */
    public static function newBackupCodes(int $n = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }

    /** 备用码哈希（不存明文） */
    public static function hashBackupCodes(array $codes): string
    {
        return json_encode(array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $codes));
    }

    /**
     * 校验并消费一个备用码。
     * 成功返回剩余备用码的 JSON，失败返回 null。
     */
    public static function consumeBackupCode(PDO $db, string $username, string $code): ?string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if ($code === '') {
            return null;
        }
        try {
            $st = $db->prepare('SELECT backup_codes FROM user_2fa WHERE username = ? LIMIT 1');
            $st->execute([$username]);
            $json = (string) $st->fetchColumn();
            $hashes = json_decode($json, true);
            if (!is_array($hashes)) {
                return null;
            }
            foreach ($hashes as $i => $h) {
                if (password_verify($code, (string) $h)) {
                    unset($hashes[$i]);
                    $left = json_encode(array_values($hashes));
                    $db->prepare('UPDATE user_2fa SET backup_codes = ? WHERE username = ?')
                       ->execute([$left, $username]);
                    return $left;
                }
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    /** 清理过期的登录尝试记录（保留 7 天） */
    public static function gcAttempts(PDO $db): void
    {
        try {
            $db->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 7 DAY');
            $db->exec('DELETE FROM login_logs WHERE created_at < NOW() - INTERVAL 30 DAY');
        } catch (Throwable $e) {
            // 忽略
        }
    }

    /* ==================== Base32（RFC 4648，无填充） ==================== */

    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $data): string
    {
        $out = '';
        $bits = '';
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::B32[bindec($chunk)];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
        if ($b32 === '') {
            return '';
        }
        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $pos = strpos(self::B32, $b32[$i]);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
