<?php
/**
 * 蓝天内网穿透 · 认证控制器
 * ===========================================================================
 * 覆盖：登录（含 2FA 两阶段）、登出、2FA 管理、登录日志、验证码
 *
 * 【v1.1.5 的重构重点】
 * 登录流程从「一步发 token」改为「两阶段」：
 *   阶段一 POST /auth/login     → 校验账号密码，若开启 2FA 则只发 ticket
 *   阶段二 POST /auth/2fa/verify → 校验动态码或备用码，才发正式 session
 *
 * 为什么必须两阶段：如果第一阶段就发 session，那么"已通过密码验证"
 * 和"已通过二次验证"在服务端不可区分，任何漏判都会让 2FA 形同虚设。
 * 用一个短时效的 ticket 把中间态显式建模出来，才不会出错。
 * ===========================================================================
 */

final class AuthController
{
    /** 2FA 中间态票据的有效期（秒） */
    private const TICKET_TTL = 300;

    /** 票据存放目录 */
    private static function ticketDir(): string
    {
        return __DIR__ . '/../storage/tickets';
    }

    /* ==================== 登录 ==================== */

    /**
     * POST /v1/auth/login
     *
     * 返回二选一：
     *   { success:true, token, expiresIn, user }            —— 无需 2FA
     *   { success:true, need2FA:true, ticket, user }        —— 需二次验证
     */
    public static function login(): void
    {
        $db = pdo();
        $b  = Req::body();

        $u = trim((string) ($b['username'] ?? ''));
        $p = (string) ($b['password'] ?? '');
        $ip = Req::ip();
        $ua = Req::ua();

        if ($u === '' || $p === '') {
            respond([
                'success' => false,
                'error'   => 'INVALID_CREDENTIALS',
                'message' => '请输入账号与密码',
            ], 401);
        }

        checkLen($u, 'user_name', '账号');

        // ---- 1) 锁定检查 ----
        $lock = Auth::checkLock($db, $u, $ip);
        if ($lock['locked']) {
            Auth::logLogin($db, $u, $ip, $ua, 'locked', 'scope=' . $lock['scope']);
            respond([
                'success'    => false,
                'error'      => 'ACCOUNT_LOCKED',
                'message'    => $lock['scope'] === 'ip'
                    ? '该 IP 登录失败次数过多，请稍后再试'
                    : '该账号登录失败次数过多，请稍后再试',
                'retryAfter' => $lock['retryAfter'],
            ], 429);
        }

        // ---- 2) 图形验证码（失败 2 次后强制） ----
        if ($lock['needCaptcha']) {
            $cap = strtoupper(trim((string) ($b['captcha'] ?? '')));
            $sig = (string) ($b['captchaSig'] ?? '');

            // 签名式校验：无需服务端存储，天然支持多机与 API Key 场景
            $okSig = ($sig !== '' && $cap !== '') && self::verifyCaptchaSig($sig, $cap);

            if (!$okSig) {
                Auth::logLogin($db, $u, $ip, $ua, 'captcha_fail', '');
                $bundle = self::captchaBundle();
                respond([
                    'success'      => false,
                    'error'        => 'CAPTCHA_REQUIRED',
                    'message'      => '请先完成图形验证码',
                    'needCaptcha'  => true,
                    'fails'        => $lock['fails'],
                    'captchaImage' => $bundle['image'],
                    'captchaSig'   => $bundle['sig'],
                ], 400);
            }
        }

        // ---- 3) 账号密码校验 ----
        $st = $db->prepare('SELECT id, username, password, role, status, email, real_name FROM users WHERE username = ? LIMIT 1');
        $st->execute([$u]);
        $row = $st->fetch();

        $passOk = $row && password_verify($p, (string) $row['password']);

        if (!$passOk) {
            Auth::recordAttempt($db, $u, $ip, false);
            Auth::logLogin($db, $u, $ip, $ua, 'fail', 'bad password');
            $after = Auth::checkLock($db, $u, $ip);
            respond([
                'success'     => false,
                'error'       => 'INVALID_CREDENTIALS',
                'message'     => '账号或密码不正确',
                'fails'       => $after['fails'],
                'needCaptcha' => $after['needCaptcha'],
            ], 401);
        }

        if (($row['status'] ?? 'active') === 'banned') {
            Auth::recordAttempt($db, $u, $ip, false);
            Auth::logLogin($db, $u, $ip, $ua, 'banned', '');
            respond([
                'success' => false,
                'error'   => 'ACCOUNT_BANNED',
                'message' => '该账号已被停用，请联系管理员',
            ], 403);
        }

        // ---- 4) 密码正确 ----
        // 注意：此处**还不算登录成功**，先不记录成功尝试（要等 2FA 通过）。
        // 若开了 2FA，这一步只是"通过第一关"。

        if (Auth::is2FAEnabled($db, $u)) {
            $ticket = self::issueTicket($u, (string) $row['role'], $ip);
            Auth::logLogin($db, $u, $ip, $ua, '2fa_required', '');
            respond([
                'success' => true,
                'need2FA' => true,
                'ticket'  => $ticket,
                'expires' => self::TICKET_TTL,
                'message' => '请输入身份验证器中的 6 位动态码',
                'user'    => [
                    'username' => $row['username'],
                    'role'     => $row['role'],
                ],
            ]);
        }

        self::finishLogin($db, $row, $ip, $ua);
    }

    /**
     * POST /v1/auth/2fa/verify
     * body: { ticket, code }  —— code 可以是 6 位动态码或备用码
     */
    public static function verify2FA(): void
    {
        $db = pdo();
        $b  = Req::body();

        $ticket = trim((string) ($b['ticket'] ?? ''));
        $code   = trim((string) ($b['code'] ?? ''));
        $ip     = Req::ip();
        $ua     = Req::ua();

        if ($ticket === '' || $code === '') {
            respond([
                'success' => false,
                'error'   => 'BAD_REQUEST',
                'message' => '缺少 ticket 或 code',
            ], 400);
        }

        $t = self::readTicket($ticket);
        if ($t === null) {
            respond([
                'success' => false,
                'error'   => 'TICKET_EXPIRED',
                'message' => '验证超时，请重新登录',
            ], 401);
        }

        // 票据绑定 IP：防止 ticket 被中间人劫持后在别处使用
        if (($t['ip'] ?? '') !== '' && $t['ip'] !== $ip) {
            Auth::logLogin($db, (string) $t['username'], $ip, $ua, '2fa_ip_mismatch', '');
            respond([
                'success' => false,
                'error'   => 'TICKET_INVALID',
                'message' => '验证环境已变化，请重新登录',
            ], 401);
        }

        // 失败计数：2FA 也要限流，否则可以暴力穷举 6 位码
        $fails = (int) ($t['fails'] ?? 0);
        if ($fails >= 5) {
            self::dropTicket($ticket);
            Auth::logLogin($db, (string) $t['username'], $ip, $ua, '2fa_locked', '');
            respond([
                'success' => false,
                'error'   => 'TOO_MANY_ATTEMPTS',
                'message' => '动态码错误次数过多，请重新登录',
            ], 429);
        }

        $st = $db->prepare('SELECT secret, backup_codes FROM user_2fa WHERE username = ? LIMIT 1');
        $st->execute([(string) $t['username']]);
        $row2 = $st->fetch() ?: ['secret' => '', 'backup_codes' => ''];

        $ok = false;
        $usedBackup = false;

        if (Auth::verifyTotp((string) $row2['secret'], $code)) {
            $ok = true;
        } else {
            // 尝试当作备用码
            $left = Auth::consumeBackupCode($db, (string) $t['username'], $code);
            if ($left !== null) {
                $ok = true;
                $usedBackup = true;
            }
        }

        if (!$ok) {
            self::bumpTicketFails($ticket, $fails + 1);
            Auth::logLogin($db, (string) $t['username'], $ip, $ua, '2fa_fail', '');
            respond([
                'success' => false,
                'error'   => 'INVALID_2FA_CODE',
                'message' => '动态码不正确',
                'left'    => max(0, 4 - $fails),
            ], 401);
        }

        self::dropTicket($ticket);

        // 取完整用户信息
        $st = $db->prepare('SELECT id, username, password, role, status, email, real_name FROM users WHERE username = ? LIMIT 1');
        $st->execute([(string) $t['username']]);
        $user = $st->fetch();
        if (!$user) {
            respond(['success' => false, 'error' => 'INVALID_CREDENTIALS'], 401);
        }

        Auth::logLogin($db, (string) $t['username'], $ip, $ua, $usedBackup ? '2fa_backup' : 'ok', '');
        self::finishLogin($db, $user, $ip, $ua, $usedBackup);
    }

    /**
     * POST /v1/auth/logout
     */
    public static function logout(): void
    {
        $db = pdo();
        $tok = bearerToken();
        if ($tok !== '') {
            try {
                $db->prepare('DELETE FROM sessions WHERE token = ?')->execute([$tok]);
            } catch (Throwable $e) {
            }
        }
        respond(['success' => true]);
    }

    /* ==================== 2FA 管理 ==================== */

    /**
     * POST /v1/auth/2fa/setup —— 生成密钥（此时尚未启用）
     * 用户需用身份验证器扫码，然后调 enable 提交一次动态码激活。
     */
    public static function setup2FA(): void
    {
        $db = pdo();
        Auth::require();

        $username = Auth::username();
        $secret   = Auth::newTotpSecret();
        $url      = Auth::otpauthUrl($secret, $username);

        try {
            $db->prepare(
                'INSERT INTO user_2fa (username, secret, enabled, backup_codes, created_at)
                 VALUES (?, ?, 0, NULL, NOW())
                 ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled = 0, backup_codes = NULL'
            )->execute([$username, $secret]);
        } catch (Throwable $e) {
            respond([
                'success' => false,
                'error'   => 'SCHEMA_NOT_READY',
                'message' => '2FA 数据表不可用，请检查数据库迁移',
            ], 500);
        }

        respond([
            'success' => true,
            'secret'  => $secret,
            'otpauth' => $url,
            // 前端可用这个 URL 生成二维码（不依赖任何外部服务）
            'account' => $username,
            'message' => '请用身份验证器扫描，然后提交一次动态码完成启用',
        ]);
    }

    /**
     * POST /v1/auth/2fa/enable —— 提交动态码确认启用
     */
    public static function enable2FA(): void
    {
        $db = pdo();
        Auth::require();

        $b    = Req::body();
        $code = trim((string) ($b['code'] ?? ''));
        $username = Auth::username();

        if ($code === '') {
            respond(['success' => false, 'error' => 'BAD_REQUEST', 'message' => '请输入动态码'], 400);
        }

        $st = $db->prepare('SELECT secret FROM user_2fa WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        $secret = (string) $st->fetchColumn();
        if ($secret === '') {
            respond([
                'success' => false,
                'error'   => 'NOT_SETUP',
                'message' => '请先调用 2fa/setup 生成密钥',
            ], 400);
        }

        if (!Auth::verifyTotp($secret, $code)) {
            respond([
                'success' => false,
                'error'   => 'INVALID_2FA_CODE',
                'message' => '动态码不正确，请确认设备时间是否准确',
            ], 400);
        }

        // 生成备用码并启用
        $codes = Auth::newBackupCodes(10);
        $db->prepare(
            'UPDATE user_2fa SET enabled = 1, backup_codes = ?, confirmed_at = NOW() WHERE username = ?'
        )->execute([Auth::hashBackupCodes($codes), $username]);

        Auth::logLogin($db, $username, Req::ip(), Req::ua(), '2fa_enabled', '');

        respond([
            'success'     => true,
            'enabled'     => true,
            'backupCodes' => $codes,
            'message'     => '双因素认证已启用。以下备用码只显示这一次，请妥善保存 —— '
                           . '手机丢失时它是唯一的登录途径。',
        ]);
    }

    /**
     * POST /v1/auth/2fa/disable —— 需密码 + 动态码双重确认
     */
    public static function disable2FA(): void
    {
        $db = pdo();
        Auth::require();

        $b    = Req::body();
        $pass = (string) ($b['password'] ?? '');
        $code = trim((string) ($b['code'] ?? ''));
        $username = Auth::username();

        if ($pass === '' || $code === '') {
            respond([
                'success' => false,
                'error'   => 'BAD_REQUEST',
                'message' => '关闭 2FA 需要同时提供登录密码与动态码',
            ], 400);
        }

        // 验证密码
        $st = $db->prepare('SELECT password FROM users WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        if (!password_verify($pass, (string) $st->fetchColumn())) {
            Auth::logLogin($db, $username, Req::ip(), Req::ua(), '2fa_disable_badpass', '');
            respond(['success' => false, 'error' => 'INVALID_CREDENTIALS', 'message' => '密码不正确'], 401);
        }

        // 验证动态码
        $st2 = $db->prepare('SELECT secret FROM user_2fa WHERE username = ? LIMIT 1');
        $st2->execute([$username]);
        $secret = (string) $st2->fetchColumn();

        $ok = $secret !== '' && Auth::verifyTotp($secret, $code);
        if (!$ok) {
            $ok = Auth::consumeBackupCode($db, $username, $code) !== null;
        }
        if (!$ok) {
            respond(['success' => false, 'error' => 'INVALID_2FA_CODE', 'message' => '动态码不正确'], 401);
        }

        $db->prepare('DELETE FROM user_2fa WHERE username = ?')->execute([$username]);
        Auth::logLogin($db, $username, Req::ip(), Req::ua(), '2fa_disabled', '');

        respond(['success' => true, 'enabled' => false, 'message' => '双因素认证已关闭']);
    }

    /** GET /v1/auth/2fa/status —— 当前用户 2FA 状态 */
    public static function status2FA(): void
    {
        $db = pdo();
        Auth::require();

        $username = Auth::username();
        $enabled = Auth::is2FAEnabled($db, $username);

        $left = 0;
        try {
            $st = $db->prepare('SELECT backup_codes FROM user_2fa WHERE username = ? LIMIT 1');
            $st->execute([$username]);
            $j = json_decode((string) $st->fetchColumn(), true);
            $left = is_array($j) ? count($j) : 0;
        } catch (Throwable $e) {
        }

        respond([
            'success'      => true,
            'enabled'      => $enabled,
            'backupLeft'   => $left,
        ]);
    }

    /* ==================== 登录日志与安全 ==================== */

    /** GET /v1/auth/logs —— 登录日志（管理员看全部，普通用户看自己） */
    public static function logs(): void
    {
        $db = pdo();
        Auth::require();

        $limit = (int) Req::query('limit', 100);
        if ($limit < 1 || $limit > 500) {
            $limit = 100;
        }

        if (Auth::isAdmin()) {
            $st = $db->prepare(
                'SELECT username, ip, ua, result, detail, created_at AS createdAt
                   FROM login_logs ORDER BY id DESC LIMIT ' . $limit
            );
            $st->execute();
        } else {
            $st = $db->prepare(
                'SELECT username, ip, ua, result, detail, created_at AS createdAt
                   FROM login_logs WHERE username = ? ORDER BY id DESC LIMIT ' . $limit
            );
            $st->execute([Auth::username()]);
        }

        respond(['success' => true, 'logs' => $st->fetchAll()]);
    }

    /** GET /v1/auth/captcha —— 生成图形验证码 */
    public static function captcha(): void
    {
        $b = self::captchaBundle();
        respond([
            'success' => true,
            'image'   => $b['image'],
            'sig'     => $b['sig'],
        ]);
    }

    /* ==================== 内部实现 ==================== */

    /**
     * 完成登录：写 session + 返回 token。
     */
    private static function finishLogin(PDO $db, array $row, string $ip, string $ua, bool $usedBackup = false): void
    {
        $username = (string) $row['username'];

        Auth::recordAttempt($db, $username, $ip, true);
        if (!$usedBackup) {
            Auth::logLogin($db, $username, $ip, $ua, 'ok', '');
        }

        // 会话有效期取自设置。
        //
        // 【单位是分钟，不是秒】历史实现（api.php 旧 login）用的是
        // `DATE_ADD(NOW(), INTERVAL ? MINUTE)`，settings 里的 sessionTimeout
        // 默认值 60 指的是 60 分钟。这里必须与旧行为保持一致 ——
        // 若误按秒处理，会话会从 1 小时缩到 1 分钟，用户操作两步就掉线。
        $ttlMin = settingsGet($db, 'sessionTimeout', 60);
        $ttlMin = is_numeric($ttlMin) ? (int) $ttlMin : 60;
        if ($ttlMin <= 0) {
            $ttlMin = 60;
        }
        $ttlSec = $ttlMin * 60;   // 响应里报给前端的 expiresIn 用秒

        $token = bin2hex(random_bytes(32));
        $hasExpiry = hasColumn($db, 'sessions', 'expires_at');

        try {
            if ($hasExpiry) {
                $db->prepare(
                    'INSERT INTO sessions (token, username, created_at, expires_at, last_active)
                     VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())'
                )->execute([$token, $username, $ttlMin]);
            } else {
                $db->prepare('INSERT INTO sessions (token, username) VALUES (?, ?)')
                   ->execute([$token, $username]);
            }
        } catch (Throwable $e) {
            respond([
                'success' => false,
                'error'   => 'SESSION_CREATE_FAILED',
                'message' => '会话创建失败，请检查数据库',
            ], 500);
        }

        // 顺手清理过期会话与陈旧日志（低频，失败无所谓）
        try {
            if ($hasExpiry) {
                $db->exec('DELETE FROM sessions WHERE expires_at IS NOT NULL AND expires_at < NOW()');
            }
            Auth::gcAttempts($db);
        } catch (Throwable $e) {
        }

        respond([
            'success'   => true,
            'token'     => $token,
            'expiresIn' => $ttlSec,
            'usedBackupCode' => $usedBackup,
            'user'      => [
                'username' => $row['username'],
                'role'     => $row['role'],
                'email'    => $row['email'] ?? '',
                'realName' => $row['real_name'] ?? '',
            ],
        ]);
    }

    /* ---- 2FA 票据（短时效，落盘存储，不占数据库） ---- */

    private static function issueTicket(string $username, string $role, string $ip): string
    {
        $dir = self::ticketDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $ticket = bin2hex(random_bytes(24));
        @file_put_contents(
            $dir . '/' . hash('sha256', $ticket) . '.json',
            json_encode([
                'username' => $username,
                'role'     => $role,
                'ip'       => $ip,
                'exp'      => time() + self::TICKET_TTL,
                'fails'    => 0,
            ]),
            LOCK_EX
        );
        return $ticket;
    }

    private static function ticketFile(string $ticket): string
    {
        return self::ticketDir() . '/' . hash('sha256', $ticket) . '.json';
    }

    private static function readTicket(string $ticket): ?array
    {
        $f = self::ticketFile($ticket);
        if (!is_file($f)) {
            return null;
        }
        $j = json_decode((string) @file_get_contents($f), true);
        if (!is_array($j)) {
            @unlink($f);
            return null;
        }
        if (($j['exp'] ?? 0) < time()) {
            @unlink($f);
            return null;
        }
        return $j;
    }

    private static function bumpTicketFails(string $ticket, int $fails): void
    {
        $f = self::ticketFile($ticket);
        $j = json_decode((string) @file_get_contents($f), true);
        if (is_array($j)) {
            $j['fails'] = $fails;
            @file_put_contents($f, json_encode($j), LOCK_EX);
        }
    }

    private static function dropTicket(string $ticket): void
    {
        @unlink(self::ticketFile($ticket));
    }

    /* ---- 图形验证码 ---- */

    /**
     * 生成验证码（图片 + 签名）。
     *
     * 【签名机制说明】
     * 传统实现把答案存 session，但本系统支持纯 API（API Key）访问，
     * 那类请求没有 session，验证码就无法工作。
     * 改用「签名式验证码」：把答案与过期时间用服务端密钥签名后一起下发，
     * 提交时校验签名即可，无需服务端存储，也天然支持多机部署。
     *
     * @return array{image:string, sig:string, text:string}
     */
    private static function captchaBundle(): array
    {
        $code = self::randomCaptchaText();
        $sig  = self::issueCaptchaSig($code);

        if (!function_exists('imagecreatetruecolor')) {
            // 无 GD 库：返回空图片，前端降级为纯文本提示，
            // 但签名校验依然有效（答案由服务端生成并签名，前端看不到）
            return ['image' => '', 'sig' => $sig, 'text' => ''];
        }

        $w = 120; $h = 40;
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 245, 247, 250);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);

        // 干扰线
        for ($i = 0; $i < 5; $i++) {
            $c = imagecolorallocate($im, random_int(180, 220), random_int(180, 220), random_int(180, 220));
            imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }

        // 字符
        $len = strlen($code);
        for ($i = 0; $i < $len; $i++) {
            $c = imagecolorallocate($im, random_int(30, 90), random_int(30, 90), random_int(120, 180));
            imagestring($im, 5, 14 + $i * 20, random_int(10, 18), $code[$i], $c);
        }

        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return [
            'image' => 'data:image/png;base64,' . base64_encode($png),
            'sig'   => $sig,
            'text'  => '',
        ];
    }

    private static function randomCaptchaText(): string
    {
        // 去掉易混字符 0/O/1/I/L
        $pool = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= $pool[random_int(0, strlen($pool) - 1)];
        }
        return $out;
    }

    /** 验证码签名密钥（从 config 取，取不到则用固定派生值） */
    private static function captchaKey(): string
    {
        try {
            $c = cfg();
            $seed = (string) ($c['db']['pass'] ?? '') . '|' . (string) ($c['db']['name'] ?? '');
            return hash('sha256', 'lt_captcha|' . $seed);
        } catch (Throwable $e) {
            return hash('sha256', 'lt_captcha|fallback');
        }
    }

    private static function issueCaptchaSig(string $code = ''): string
    {
        if ($code === '') {
            $code = self::randomCaptchaText();
        }
        $exp = time() + 180;
        $payload = strtoupper($code) . '.' . $exp;
        $mac = hash_hmac('sha256', $payload, self::captchaKey());
        return base64_encode($payload . '.' . substr($mac, 0, 16));
    }

    private static function verifyCaptchaSig(string $sig, string $code): bool
    {
        $raw = base64_decode($sig, true);
        if ($raw === false) {
            return false;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 3) {
            return false;
        }
        [$expect, $exp, $mac] = $parts;
        if ((int) $exp < time()) {
            return false;
        }
        $calc = substr(hash_hmac('sha256', $expect . '.' . $exp, self::captchaKey()), 0, 16);
        if (!hash_equals($calc, $mac)) {
            return false;
        }
        return strtoupper($code) === $expect;
    }
}
