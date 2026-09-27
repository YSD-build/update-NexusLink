<?php
/**
 * 蓝天内网穿透 · 应急救援接口（Rescue Console）
 * ===========================================================================
 * 这是一个**独立于整套应用架构**的救援入口。
 *
 * 【它解决什么问题】
 * 当面板出现「所有接口都 UNAUTHORIZED」或「整站 500」时，你通常已经
 * 无法通过界面做任何事了 —— 而恰恰这种时候，最需要的是：
 *   · 把某个被锁定的账号解锁 / 重置密码
 *   · 清空会话表，让所有人重新登录
 *   · 看一眼到底哪一环坏了（库连不上？缺表？）
 *
 * 【为什么不能靠 /api.php】
 * 因为坏的可能就是 api.php 本身、lib/ 目录、controllers/、路由表、
 * 或者会话表。救援入口必须走**另一条路**，本文件因此：
 *   · 单文件、零外部依赖 —— 不 require lib/、不 require controllers/、
 *     不经过 Router，甚至不 require db.php
 *   · 自己连数据库（读 config.php，读不到就提示你手填）
 *   · 自己校验令牌，自己输出 HTML，不依赖任何前端资源
 *   这样只要「PHP 还能跑」+「这个文件还在」，救援就可用。
 *
 * 【安全性 —— 这是公网可达页面，必须读】
 * 1. **默认关闭**。必须先在站点根目录建一个文件才会启用：
 *         storage/state/rescue.key
 *    文件内容就是救援令牌（至少 16 位）。
 *    没这个文件 → 本页面只返回 404 风格提示，什么都不做、不透露任何信息。
 *
 * 2. **令牌只从 POST body 读**，不从 GET 读。
 *    理由：GET 会进 nginx access log、浏览器历史、Referer 头。
 *    把救援令牌写进 URL 等于把它公开。
 *
 * 3. **令牌文件必须放在 storage/**，而 storage/ 是发布白名单之外、
 *    永远不会被在线更新覆盖的目录。升级不会把它冲掉，也不会把它下发出去。
 *
 * 4. **失败限流**：同 IP 连续失败 5 次 → 锁定 1 小时。
 *    状态写在 storage/state/rescue.fail，不依赖数据库（库可能就是坏的）。
 *
 * 5. **恒定时间比较**（hash_equals），防时序侧信道。
 *
 * 6. **不泄漏信息**：未通过令牌校验前，任何分支都不返回版本号、
 *    不返回路径、不返回表名、不确认「这个页面存在」。
 *
 * 7. **每个动作都写审计日志**到 storage/logs/rescue.log。
 *
 * 【怎么启用】
 *     cd 站点根目录
 *     mkdir -p storage/state
 *     printf '%s' "$(head -c 32 /dev/urandom | base64 | tr -d '/+=')" > storage/state/rescue.key
 *     chmod 600 storage/state/rescue.key
 *     chown www:www storage/state/rescue.key      # 宝塔上是 www 用户
 *     cat storage/state/rescue.key                # 记下这个令牌
 *
 * 然后浏览器打开  http://你的域名/rescue.php
 *
 * 【用完请删除令牌文件】—— 关闭方式：
 *     rm storage/state/rescue.key
 * 不需要删 rescue.php 本身，留着以备下次。
 * ===========================================================================
 */

// ---------------------------------------------------------------------------
// 早期拦截：任何异常都不该把 PHP 错误路径暴露给公网
// ---------------------------------------------------------------------------
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

$ROOT = __DIR__;
$STATE_DIR = $ROOT . '/storage/state';
$LOG_DIR   = $ROOT . '/storage/logs';
$KEY_FILE  = $STATE_DIR . '/rescue.key';
$FAIL_FILE = $STATE_DIR . '/rescue.fail';
$LOG_FILE  = $LOG_DIR   . '/rescue.log';

/* ===========================================================================
 * 0. 总闸：没有令牌文件 = 本页不存在
 * ---------------------------------------------------------------------------
 * 注意这里返回 404 而不是 403。403 等于告诉扫描器「这里有个受保护的东西」，
 * 404 才是「这里什么都没有」。安全页面应当学会伪装成不存在。
 * =========================================================================== */
if (!is_file($KEY_FILE)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    exit;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/* ===========================================================================
 * 工具函数（自包含，不依赖 lib/Helpers.php）
 * =========================================================================== */

/** 恒定时间字符串比较 */
function rsSame(string $a, string $b): bool {
    return hash_equals($a, $b);
}

/** 记录审计日志（失败不阻断主流程） */
function rsLog(string $file, string $ip, string $action, string $detail = ''): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = sprintf(
        "[%s] ip=%s action=%s %s\n",
        date('Y-m-d H:i:s'),
        $ip,
        $action,
        $detail
    );
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/** 读取失败计数状态 */
function rsReadFails(string $file): array {
    if (!is_file($file)) {
        return [];
    }
    $j = json_decode((string) @file_get_contents($file), true);
    return is_array($j) ? $j : [];
}

/** 写失败计数状态 */
function rsWriteFails(string $file, array $data): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);
    @chmod($file, 0600);
}

/** 同 IP 是否处于锁定中 */
function rsIsLocked(string $failFile, string $ip): array {
    $all = rsReadFails($failFile);
    $rec = $all[$ip] ?? null;
    if (!is_array($rec)) {
        return ['locked' => false, 'retryAfter' => 0];
    }
    $n = (int) ($rec['n'] ?? 0);
    $last = (int) ($rec['t'] ?? 0);
    $lockSec = 3600;
    if ($n >= 5 && (time() - $last) < $lockSec) {
        return ['locked' => true, 'retryAfter' => $lockSec - (time() - $last)];
    }
    // 锁定窗口已过 → 自动重置
    if ($n >= 5 && (time() - $last) >= $lockSec) {
        unset($all[$ip]);
        rsWriteFails($failFile, $all);
    }
    return ['locked' => false, 'retryAfter' => 0];
}

/** 累加一次失败 */
function rsBumpFail(string $failFile, string $ip): void {
    $all = rsReadFails($failFile);
    $rec = $all[$ip] ?? ['n' => 0, 't' => 0];
    // 距上次失败超过 1 小时，重新计数
    if ((time() - (int) ($rec['t'] ?? 0)) > 3600) {
        $rec = ['n' => 0, 't' => 0];
    }
    $rec['n'] = (int) $rec['n'] + 1;
    $rec['t'] = time();
    $all[$ip] = $rec;
    rsWriteFails($failFile, $all);
}

/** 清空该 IP 的失败记录 */
function rsClearFail(string $failFile, string $ip): void {
    $all = rsReadFails($failFile);
    unset($all[$ip]);
    rsWriteFails($failFile, $all);
}

/** 自包含的数据库连接（不 require db.php，因为它可能正是坏的那一环） */
function rsConnect(string $root): array {
    $cfgFile = $root . '/config.php';
    if (!is_file($cfgFile)) {
        return ['ok' => false, 'error' => 'config.php 不存在'];
    }
    $cfg = @include $cfgFile;
    if (!is_array($cfg) || empty($cfg['db'])) {
        return ['ok' => false, 'error' => 'config.php 未返回有效的 db 配置'];
    }
    $d = $cfg['db'];
    $host = (string) ($d['host'] ?? '127.0.0.1');
    $port = (string) ($d['port'] ?? '3306');
    $name = (string) ($d['name'] ?? '');
    $user = (string) ($d['user'] ?? '');
    $pass = (string) ($d['pass'] ?? '');
    $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        return ['ok' => true, 'pdo' => $pdo, 'name' => $name];
    } catch (Throwable $e) {
        // 错误信息里可能含口令，这里只回通用原因
        return ['ok' => false, 'error' => '数据库连接失败（请检查 config.php 的 host/name/user/pass）'];
    }
}

/** HTML 转义 */
function h($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 自包含的密码哈希（优先 bcrypt，退化到 sha256，与原系统保持一致） */
function rsHashPassword(string $plain): string {
    if (function_exists('password_hash')) {
        return password_hash($plain, defined('PASSWORD_DEFAULT') ? PASSWORD_DEFAULT : 1);
    }
    return hash('sha256', $plain);
}

/* ===========================================================================
 * 1. 处理动作（仅 POST）
 * =========================================================================== */
$message = '';
$messageType = 'ok';
$lockInfo = rsIsLocked($FAIL_FILE, $clientIp);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($lockInfo['locked']) {
        $message = '尝试次数过多，已锁定。请在 ' . ceil($lockInfo['retryAfter'] / 60) . ' 分钟后重试。';
        $messageType = 'err';
        rsLog($LOG_FILE, $clientIp, 'LOCKED_BLOCKED');
    } else {
        // 令牌只从 POST body 读 —— 绝不从 GET / Cookie 读
        $token  = (string) ($_POST['token'] ?? '');
        $action = (string) ($_POST['action'] ?? '');
        $expected = trim((string) @file_get_contents($KEY_FILE));

        // 首次使用提示：令牌文件内容太短说明用户没认真设置
        if (strlen($expected) < 16) {
            $message = '救援令牌过短（至少 16 位）。请重新生成 storage/state/rescue.key。';
            $messageType = 'err';
            rsLog($LOG_FILE, $clientIp, 'WEAK_KEY');
        } elseif ($token === '' || !rsSame($token, $expected)) {
            rsBumpFail($FAIL_FILE, $clientIp);
            $left = 5 - ((rsReadFails($FAIL_FILE)[$clientIp]['n'] ?? 0));
            $message = '令牌不正确。' . ($left > 0 ? "还可尝试 {$left} 次。" : '已达上限，即将锁定。');
            $messageType = 'err';
            rsLog($LOG_FILE, $clientIp, 'BAD_TOKEN', 'action=' . $action);
        } else {
            // ---------- 令牌正确，执行动作 ----------
            rsClearFail($FAIL_FILE, $clientIp);
            $conn = rsConnect($ROOT);

            if ($action === 'diagnose') {
                $message = '诊断完成，见下方结果。';
                rsLog($LOG_FILE, $clientIp, 'DIAGNOSE');

            } elseif ($action === 'clear_sessions') {
                if (!$conn['ok']) {
                    $message = '数据库不可用：' . $conn['error'];
                    $messageType = 'err';
                } else {
                    try {
                        $n = (int) $conn['pdo']->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
                        $conn['pdo']->exec('DELETE FROM sessions');
                        $message = "已清空 {$n} 条会话。所有用户（含你自己）需重新登录。";
                        rsLog($LOG_FILE, $clientIp, 'CLEAR_SESSIONS', "cleared={$n}");
                    } catch (Throwable $e) {
                        $message = '清空会话失败：sessions 表可能不存在。';
                        $messageType = 'err';
                        rsLog($LOG_FILE, $clientIp, 'CLEAR_SESSIONS_FAIL');
                    }
                }

            } elseif ($action === 'unlock_user') {
                $u = trim((string) ($_POST['username'] ?? ''));
                if (!$conn['ok']) {
                    $message = '数据库不可用：' . $conn['error'];
                    $messageType = 'err';
                } elseif ($u === '') {
                    $message = '请填写要解锁的用户名。';
                    $messageType = 'err';
                } else {
                    try {
                        $st = $conn['pdo']->prepare('DELETE FROM login_attempts WHERE username = ?');
                        $st->execute([$u]);
                        $n = $st->rowCount();
                        $message = "已清除用户「{$u}」的 {$n} 条失败记录，账号解锁。";
                        rsLog($LOG_FILE, $clientIp, 'UNLOCK_USER', "user={$u} removed={$n}");
                    } catch (Throwable $e) {
                        $message = '解锁失败：login_attempts 表不存在（该版本可能还没启用登录限流）。';
                        $messageType = 'err';
                        rsLog($LOG_FILE, $clientIp, 'UNLOCK_USER_FAIL', "user={$u}");
                    }
                }

            } elseif ($action === 'reset_password') {
                $u = trim((string) ($_POST['username'] ?? ''));
                $p = (string) ($_POST['password'] ?? '');
                if (!$conn['ok']) {
                    $message = '数据库不可用：' . $conn['error'];
                    $messageType = 'err';
                } elseif ($u === '') {
                    $message = '请填写用户名。';
                    $messageType = 'err';
                } elseif (strlen($p) < 8) {
                    $message = '新密码至少 8 位。';
                    $messageType = 'err';
                } else {
                    try {
                        // 先确认用户存在，避免「重置成功」的假象
                        $st = $conn['pdo']->prepare('SELECT username FROM users WHERE username = ?');
                        $st->execute([$u]);
                        if (!$st->fetch()) {
                            $message = "用户「{$u}」不存在。";
                            $messageType = 'err';
                        } else {
                            $hash = rsHashPassword($p);
                            $up = $conn['pdo']->prepare('UPDATE users SET password = ? WHERE username = ?');
                            $up->execute([$hash, $u]);
                            // 顺手清该用户会话，强制用新密码重新登录
                            try {
                                $conn['pdo']->prepare('DELETE FROM sessions WHERE username = ?')->execute([$u]);
                            } catch (Throwable $e) {
                            }
                            $message = "用户「{$u}」密码已重置，其原有会话已清空。请立即用新密码登录。";
                            rsLog($LOG_FILE, $clientIp, 'RESET_PASSWORD', "user={$u}");
                        }
                    } catch (Throwable $e) {
                        $message = '重置密码失败：数据库结构异常。';
                        $messageType = 'err';
                        rsLog($LOG_FILE, $clientIp, 'RESET_PASSWORD_FAIL', "user={$u}");
                    }
                }

            } elseif ($action === 'create_admin') {
                $u = trim((string) ($_POST['username'] ?? ''));
                $p = (string) ($_POST['password'] ?? '');
                if (!$conn['ok']) {
                    $message = '数据库不可用：' . $conn['error'];
                    $messageType = 'err';
                } elseif ($u === '' || strlen($p) < 8) {
                    $message = '用户名必填，密码至少 8 位。';
                    $messageType = 'err';
                } else {
                    try {
                        $hash = rsHashPassword($p);
                        $st = $conn['pdo']->prepare('SELECT username FROM users WHERE username = ?');
                        $st->execute([$u]);
                        if ($st->fetch()) {
                            $up = $conn['pdo']->prepare('UPDATE users SET password = ?, role = ?, status = ? WHERE username = ?');
                            $up->execute([$hash, 'admin', 'active', $u]);
                            $message = "用户「{$u}」已存在，已将其重置为管理员并更新密码。";
                            rsLog($LOG_FILE, $clientIp, 'ELEVATE_ADMIN', "user={$u}");
                        } else {
                            $ins = $conn['pdo']->prepare('INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, ?)');
                            $ins->execute([$u, $hash, 'admin', 'active']);
                            $message = "已创建管理员「{$u}」。请立即登录并删除本页令牌文件。";
                            rsLog($LOG_FILE, $clientIp, 'CREATE_ADMIN', "user={$u}");
                        }
                    } catch (Throwable $e) {
                        $message = '创建管理员失败：' . (string) $e->getMessage();
                        $messageType = 'err';
                        rsLog($LOG_FILE, $clientIp, 'CREATE_ADMIN_FAIL', "user={$u}");
                    }
                }

            } else {
                $message = '未知动作。';
                $messageType = 'err';
            }
        }
    }
}

/* ===========================================================================
 * 2. 诊断数据（仅在令牌正确时收集）
 * ---------------------------------------------------------------------------
 * 这里刻意不缓存、每次现算 —— 救援场景下要的就是「此刻的真实状态」。
 * =========================================================================== */
$diag = null;
$authed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !$lockInfo['locked']
    && ($_POST['token'] ?? '') !== ''
) {
    $expected = trim((string) @file_get_contents($KEY_FILE));
    if (strlen($expected) >= 16 && rsSame((string) $_POST['token'], $expected)) {
        $authed = true;
    }
}

if ($authed) {
    $diag = [
        'php'      => PHP_VERSION,
        'root'     => $ROOT,
        'stateWritable' => is_writable($STATE_DIR),
        'logWritable'   => (is_dir($LOG_DIR) ? is_writable($LOG_DIR) : is_writable($ROOT . '/storage')),
    ];

    $conn = rsConnect($ROOT);
    $diag['dbOk'] = $conn['ok'];
    $diag['dbName'] = $conn['name'] ?? '';
    $diag['dbError'] = $conn['error'] ?? '';

    $diag['tables'] = [];
    $diag['missingTables'] = [];
    $diag['counts'] = [];
    $expect = ['users', 'sessions', 'nodes', 'clients', 'proxies', 'settings', 'api_keys'];
    if ($conn['ok']) {
        try {
            $rows = $conn['pdo']->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $diag['tables'] = $rows;
            $diag['missingTables'] = array_values(array_diff($expect, $rows));
            foreach (['users', 'sessions', 'nodes', 'proxies'] as $t) {
                if (in_array($t, $rows, true)) {
                    try {
                        $diag['counts'][$t] = (int) $conn['pdo']->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                    } catch (Throwable $e) {
                        $diag['counts'][$t] = '?';
                    }
                }
            }
            // 会话表是否具备过期列（决定鉴权行为）
            $diag['sessionsHasExpiry'] = false;
            try {
                $cols = $conn['pdo']->query("SHOW COLUMNS FROM sessions LIKE 'expires_at'")->fetchAll();
                $diag['sessionsHasExpiry'] = !empty($cols);
            } catch (Throwable $e) {
            }
            // 管理员账号
            $diag['admins'] = [];
            $diag['disabledAdmins'] = [];
            try {
                $as = $conn['pdo']->query("SELECT username, status FROM users WHERE role = 'admin'")->fetchAll();
                foreach ($as as $a) {
                    $diag['admins'][] = $a['username'] . ' (' . $a['status'] . ')';
                    if (($a['status'] ?? '') !== 'active') {
                        $diag['disabledAdmins'][] = $a['username'];
                    }
                }
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
            $diag['dbError'] = 'SHOW TABLES 失败';
        }
    }

    // 时区一致性 —— 这是「心跳显示离线」的经典病因
    $diag['phpTz']   = date('Y-m-d H:i:s');
    $diag['phpZone'] = date_default_timezone_get();
    if ($conn['ok']) {
        try {
            $diag['mysqlTz'] = (string) $conn['pdo']->query('SELECT NOW()')->fetchColumn();
        } catch (Throwable $e) {
            $diag['mysqlTz'] = '?';
        }
    }

    // 关键文件在位情况
    $diag['files'] = [];
    foreach (['api.php', 'index.php', 'db.php', 'routes.php', 'updater.php', 'health.php',
              'lib/Bootstrap.php', 'lib/Router.php', 'controllers/AuthController.php'] as $f) {
        $diag['files'][$f] = is_file($ROOT . '/' . $f);
    }
}

/* ===========================================================================
 * 3. 渲染页面
 * =========================================================================== */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$remaining = 0;
if (!$lockInfo['locked']) {
    $rec = rsReadFails($FAIL_FILE)[$clientIp] ?? ['n' => 0];
    $remaining = max(0, 5 - (int) ($rec['n'] ?? 0));
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>应急救援 · 蓝天内网穿透</title>
<style>
  :root{
    --bg:#0b0f17; --card:#141a26; --line:#232c3d; --fg:#e6ebf5;
    --dim:#8b97ac; --brand:#3b82f6; --ok:#22c55e; --err:#ef4444; --warn:#f59e0b;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--fg);
       font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif}
  .wrap{max-width:760px;margin:0 auto;padding:28px 18px 60px}
  h1{font-size:19px;margin:0 0 4px;display:flex;align-items:center;gap:9px}
  .sub{color:var(--dim);font-size:12.5px;margin-bottom:20px}
  .badge{background:rgba(245,158,11,.14);color:var(--warn);border:1px solid rgba(245,158,11,.3);
         border-radius:5px;padding:1px 7px;font-size:11px;font-weight:600}
  .card{background:var(--card);border:1px solid var(--line);border-radius:10px;
        padding:16px 17px;margin-bottom:14px}
  .card h2{font-size:13.5px;margin:0 0 12px;color:var(--fg);font-weight:600}
  .card h2 .note{font-weight:400;color:var(--dim);font-size:11.5px;margin-left:6px}
  label{display:block;font-size:12px;color:var(--dim);margin:10px 0 5px}
  input[type=text],input[type=password]{width:100%;background:#0e131d;border:1px solid var(--line);
       border-radius:7px;color:var(--fg);padding:9px 11px;font-size:13.5px;font-family:ui-monospace,monospace}
  input:focus{outline:none;border-color:var(--brand)}
  .row{display:flex;gap:10px;flex-wrap:wrap}
  .row>div{flex:1;min-width:180px}
  button{background:var(--brand);color:#fff;border:0;border-radius:7px;padding:9px 16px;
         font-size:13px;font-weight:600;cursor:pointer;margin-top:14px}
  button:hover{filter:brightness(1.1)}
  button.ghost{background:transparent;color:var(--fg);border:1px solid var(--line)}
  button.danger{background:var(--err)}
  .msg{border-radius:8px;padding:11px 13px;margin-bottom:14px;font-size:13px;
       border:1px solid transparent;white-space:pre-wrap;word-break:break-word}
  .msg.ok{background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.3);color:#86efac}
  .msg.err{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:#fca5a5}
  table{width:100%;border-collapse:collapse;font-size:12.5px}
  td{padding:5px 0;vertical-align:top;border-bottom:1px solid #1b2230}
  td:first-child{color:var(--dim);width:150px;white-space:nowrap}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
  .yes{color:var(--ok)} .no{color:var(--err)} .wd{color:var(--warn)}
  .hint{font-size:11.5px;color:var(--dim);margin-top:9px;line-height:1.65}
  code{background:#0e131d;border:1px solid var(--line);border-radius:4px;
       padding:1px 5px;font-family:ui-monospace,monospace;font-size:11.5px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media(max-width:620px){.grid2{grid-template-columns:1fr}}
  ul.chk{margin:0;padding-left:18px;font-size:12.5px;color:var(--dim)}
  ul.chk li{margin:3px 0}
</style>
</head>
<body>
<div class="wrap">

  <h1>🛟 应急救援控制台 <span class="badge">RESCUE</span></h1>
  <div class="sub">独立于应用架构的救援入口 · 不依赖 lib/ 与 controllers/ · 每个动作都记账</div>

  <?php if ($message !== ''): ?>
    <div class="msg <?= $messageType === 'ok' ? 'ok' : 'err' ?>"><?= h($message) ?></div>
  <?php endif; ?>

  <?php if ($lockInfo['locked']): ?>
    <div class="msg err">该 IP 已被锁定，请稍后再试。</div>
  <?php endif; ?>

  <!-- ============ 登录令牌 ============ -->
  <?php if (!$authed): ?>
  <form method="post" class="card">
    <h2>输入救援令牌<span class="note">位于服务器 storage/state/rescue.key</span></h2>
    <label>救援令牌</label>
    <input type="password" name="token" autocomplete="off" autofocus
           placeholder="粘贴 rescue.key 的内容">
    <div class="hint">
      还剩 <?= (int) $remaining ?> 次尝试机会（连续失败 5 次将锁定 1 小时）。<br>
      令牌只通过 POST 提交，不会进入 URL、浏览器历史或访问日志。
    </div>
    <button type="submit" name="action" value="diagnose">验证并诊断</button>
  </form>
  <?php else: ?>

  <!-- ============ 诊断结果 ============ -->
  <div class="card">
    <h2>环境诊断<span class="note">此刻的真实状态</span></h2>
    <table>
      <tr><td>PHP 版本</td><td class="mono"><?= h($diag['php']) ?></td></tr>
      <tr><td>站点根目录</td><td class="mono"><?= h($diag['root']) ?></td></tr>
      <tr><td>storage/state 可写</td>
          <td class="<?= $diag['stateWritable'] ? 'yes' : 'no' ?>">
            <?= $diag['stateWritable'] ? '✓ 是' : '✗ 否（权限问题会让会话写不进去）' ?></td></tr>
      <tr><td>数据库连接</td>
          <td class="<?= $diag['dbOk'] ? 'yes' : 'no' ?>">
            <?= $diag['dbOk'] ? '✓ 正常（库 ' . h($diag['dbName']) . '）' : '✗ ' . h($diag['dbError']) ?></td></tr>
      <?php if ($diag['dbOk']): ?>
      <tr><td>表数量</td><td class="mono"><?= count($diag['tables']) ?></td></tr>
      <?php if ($diag['missingTables']): ?>
      <tr><td>缺失的表</td><td class="no mono"><?= h(implode(', ', $diag['missingTables'])) ?></td></tr>
      <?php endif; ?>
      <tr><td>用户 / 会话</td>
          <td class="mono">
            users=<?= h($diag['counts']['users'] ?? '?') ?>
            sessions=<?= h($diag['counts']['sessions'] ?? '?') ?>
            nodes=<?= h($diag['counts']['nodes'] ?? '?') ?>
            proxies=<?= h($diag['counts']['proxies'] ?? '?') ?>
          </td></tr>
      <tr><td>管理员账号</td>
          <td class="mono"><?= $diag['admins'] ? h(implode(', ', $diag['admins'])) : '<span class="no">无管理员！</span>' ?></td></tr>
      <?php if ($diag['disabledAdmins']): ?>
      <tr><td>被禁用的管理员</td>
          <td class="wd mono"><?= h(implode(', ', $diag['disabledAdmins'])) ?></td></tr>
      <?php endif; ?>
      <tr><td>sessions.expires_at</td>
          <td class="<?= $diag['sessionsHasExpiry'] ? 'yes' : 'wd' ?>">
            <?= $diag['sessionsHasExpiry'] ? '✓ 存在' : '⚠ 不存在（会话永不超时，鉴权走兼容分支）' ?></td></tr>
      <tr><td>PHP 时间 / 时区</td>
          <td class="mono"><?= h($diag['phpTz']) ?> (<?= h($diag['phpZone']) ?>)</td></tr>
      <?php if (isset($diag['mysqlTz'])): ?>
      <tr><td>MySQL 时间</td>
          <td class="mono"><?= h($diag['mysqlTz']) ?>
            <?php
              $diff = strtotime($diag['mysqlTz']) - strtotime($diag['phpTz']);
              if (abs($diff) > 60): ?>
                <span class="wd">⚠ 相差 <?= (int) round($diff / 3600, 1) ?> 小时（时区错位）</span>
              <?php else: ?><span class="yes">✓ 一致</span><?php endif; ?>
          </td></tr>
      <?php endif; ?>
      <?php endif; ?>
    </table>

    <?php
      $missingFiles = array_keys(array_filter($diag['files'], fn($v) => !$v));
      if ($missingFiles): ?>
      <div class="hint" style="color:#fca5a5">
        ⚠ 关键文件缺失：<span class="mono"><?= h(implode(', ', $missingFiles)) ?></span><br>
        这说明上次在线更新不完整。请用完整包重新覆盖安装。
      </div>
    <?php else: ?>
      <div class="hint"><span class="yes">✓</span> 关键文件齐全</div>
    <?php endif; ?>
  </div>

  <!-- ============ 救援动作 ============ -->
  <div class="grid2">

    <form method="post" class="card">
      <h2>① 清空全部会话</h2>
      <div class="hint">用途：所有接口报 <code>UNAUTHORIZED</code> / <code>SESSION_EXPIRED</code> 时的首选。<br>
        清空后所有人（含你自己）需重新登录。</div>
      <input type="hidden" name="token" value="<?= h((string) $_POST['token']) ?>">
      <button type="submit" name="action" value="clear_sessions" class="danger"
              onclick="return confirm('确认清空全部会话？所有用户都需要重新登录。')">执行清空</button>
    </form>

    <form method="post" class="card">
      <h2>② 解锁账号</h2>
      <div class="hint">用途：连续输错密码导致账号被锁定 15 分钟 / IP 被锁 1 小时。</div>
      <label>用户名</label>
      <input type="text" name="username" value="admin" autocomplete="off">
      <input type="hidden" name="token" value="<?= h((string) $_POST['token']) ?>">
      <button type="submit" name="action" value="unlock_user">解除锁定</button>
    </form>

    <form method="post" class="card">
      <h2>③ 重置用户密码</h2>
      <div class="hint">用途：密码忘了、或账号状态异常进不去。会同时清空该用户的会话。</div>
      <label>用户名</label>
      <input type="text" name="username" value="admin" autocomplete="off">
      <label>新密码（至少 8 位）</label>
      <input type="password" name="password" autocomplete="new-password">
      <input type="hidden" name="token" value="<?= h((string) $_POST['token']) ?>">
      <button type="submit" name="action" value="reset_password">重置密码</button>
    </form>

    <form method="post" class="card">
      <h2>④ 创建 / 提升管理员</h2>
      <div class="hint">用途：管理员账号被误删、被改成普通用户、或状态被禁用进不去后台。</div>
      <label>用户名</label>
      <input type="text" name="username" autocomplete="off" placeholder="新任管理员用户名">
      <label>密码（至少 8 位）</label>
      <input type="password" name="password" autocomplete="new-password">
      <input type="hidden" name="token" value="<?= h((string) $_POST['token']) ?>">
      <button type="submit" name="action" value="create_admin"
              onclick="return confirm('确认创建或提升为管理员？')">执行</button>
    </form>

  </div>

  <div class="card">
    <h2>善后提醒</h2>
    <ul class="chk">
      <li>确认系统恢复后，删除令牌文件关闭本页：<code>rm storage/state/rescue.key</code></li>
      <li>本页所有动作已记录到 <code>storage/logs/rescue.log</code>，可自行核查。</li>
      <li>救援令牌请勿通过聊天工具明文传递；用完即换。</li>
    </ul>
  </div>

  <?php endif; ?>

</div>
</body>
</html>
