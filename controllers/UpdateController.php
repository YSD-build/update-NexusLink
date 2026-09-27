<?php
/**
 * 在线更新控制器（管理员专属）
 * ===========================================================================
 * 负责：查看当前版本 / 拉取远程清单比对 / 执行远程更新 / 应用本地包。
 *
 * 【依赖 updater.php】
 * 真正的下载、校验、原子替换都在 updater.php 里，本控制器只做
 * 「参数校验 + 调用 + 把结果翻译成 HTTP 状态码」。这样更新逻辑
 * 可以被 CLI 直接复用（比如 sftp 上传后手动触发）。
 * ===========================================================================
 */

final class UpdateController
{
    /**
     * 比较两个版本号字符串，返回 -1 / 0 / 1。
     *
     * 【为什么放在控制器里而不是复用 updater.php 的 compareVersionKey】
     * compareVersionKey 的入参是「已拆好的数字数组 + 后缀」，服务于 tag 排序；
     * 这里拿到的是用户可见的版本串（可能带 v 前缀、可能缺位、可能带后缀），
     * 需要一个"从字符串开始"的入口。拆解规则必须与 updater 保持一致，
     * 否则会出现「排序选出 b2、判定却说不是升级」的自相矛盾。
     *
     * 规则：
     *   1.1.5-b1  <  1.1.5-b2  <  1.1.5
     *   1.1.4      <  1.1.5-b1
     * 无法解析的版本串一律视为「相等」——宁可不动，也不要误判成降级而挡住用户升级。
     */
    private static function compareAppVersion(string $a, string $b): int
    {
        $parse = static function (string $v): ?array {
            $v = ltrim(trim($v), 'vV');
            if (!preg_match('~^(\d+(?:\.\d+)*)(?:-([0-9A-Za-z.\-]+))?$~', $v, $m)) {
                return null;
            }
            // 同 updater.php：排除 2026-09-27 这类日期式串被误判成「版本 2026」。
            // 判成版本会让它比任何真实版本都大，从而得出错误的方向结论。
            if (!str_contains($m[1], '.') && isset($m[2]) && (int) $m[1] >= 1900) {
                return null;
            }
            return [
                'nums'   => array_map('intval', explode('.', $m[1])),
                'suffix' => $m[2] ?? '',
            ];
        };

        $pa = $parse($a);
        $pb = $parse($b);
        if ($pa === null || $pb === null) {
            return 0;
        }

        $an = $pa['nums'];
        $bn = $pb['nums'];
        while (count($an) < count($bn)) { $an[] = 0; }
        while (count($bn) < count($an)) { $bn[] = 0; }
        for ($i = 0; $i < count($an); $i++) {
            if ($an[$i] !== $bn[$i]) {
                return $an[$i] <=> $bn[$i];
            }
        }

        $as = $pa['suffix'];
        $bs = $pb['suffix'];
        if ($as === '' && $bs === '') { return 0; }
        if ($as === '') { return 1; }    // 无后缀 = 正式版，更大
        if ($bs === '') { return -1; }

        // 后缀分段比：'b12' → ['b', 12]，数字段按数值比（b2 < b10）
        $seg = static function (string $s): array {
            preg_match_all('~\d+|\D+~', $s, $mm);
            return array_map(static fn($x) => ctype_digit($x) ? (int) $x : $x, $mm[0]);
        };
        $xs = $seg($as);
        $ys = $seg($bs);
        for ($i = 0; $i < max(count($xs), count($ys)); $i++) {
            $x = $xs[$i] ?? null;
            $y = $ys[$i] ?? null;
            if ($x === $y) { continue; }
            if ($x === null) { return -1; }
            if ($y === null) { return 1; }
            if (is_int($x) && is_int($y)) { return $x <=> $y; }
            if (is_int($x)) { return -1; }
            if (is_int($y)) { return 1; }
            return strcmp((string) $x, (string) $y) <=> 0;
        }
        return 0;
    }

    /** GET /update/status —— 当前版本、更新源、历史与本地包 */
    public static function status()
    {
        $cfg   = cfg();
        $state = readUpdateState();

        respond([
            'success' => true,
            'current' => [
                'version'        => currentBuildVersion(),
                'appliedAt'      => $state['appliedAt'] ?? '',
                'appliedVersion' => $state['appliedVersion'] ?? '',
            ],
            'source' => [
                'manifestUrl' => (string) ($cfg['update']['manifest_url'] ?? ''),
                'configured'  => !empty($cfg['update']['manifest_url']),
            ],
            'history'  => $state['history'] ?? [],
            'packages' => listLocalPackages(),
            'env'      => [
                'zipArchive' => class_exists('ZipArchive'),
                'curl'       => function_exists('curl_init'),
                // 【修复】扁平结构下 api.php 就在站点根目录，storage/ 与它同级。
                // 原代码用 dirname(__DIR__) 会指向站点根目录的上一级（如 /www/wwwroot/），
                // 那里没有 storage/，导致此项恒为 false —— 权限即使正常也显示「不可写」。
                'storageWritable' => (function () {
                    $root = dirname(__DIR__);   // controllers/ 的上一级 = 站点根
                    $s    = $root . '/storage';
                    ensureStorageDirs();
                    return is_dir($s) && is_writable($s);
                })(),
                'storage'    => diagnoseStorage(),
                'opcache'    => function_exists('opcache_reset'),
            ],
        ]);
    }

    /** GET /update/check —— 拉取远程清单，比对版本 */
    public static function check()
    {
        $cfg = cfg();
        $url = (string) ($cfg['update']['manifest_url'] ?? '');
        if ($url === '') {
            respond([
                'success' => false,
                'error'   => 'UPDATE_SOURCE_NOT_CONFIGURED',
                'message' => '未配置更新源，请在 api/config.php 设置 update.manifest_url，'
                           . '或把新版 zip 放到 storage/packages/ 后选择本地包更新。',
            ], 400);
        }

        $r = fetchManifest($url);
        if (!$r['ok']) {
            respond(['success' => false, 'error' => $r['error']], 502);
        }

        $m       = $r['manifest'];
        $current = currentBuildVersion();

        // 【修复】远端版本优先取 appVersion，回退 version。
        // 历史上 build-meta.json 的 version 被构建脚本写成 git 短哈希，
        // 与本地同一个哈希比对时必然「相等」，导致明明有新版本却报「已是最新」。
        $remote = (string) ($m['appVersion'] ?? '');
        if ($remote === '') {
            $remote = (string) ($m['version'] ?? '');
        }

        // 【版本判定：字符串不等 + 降级识别】
        //
        // 基于「不等即有更新」而不是全序比较，是为分批发布服务的：
        // v1.1.5-b1 → v1.1.5-b2 这类同版本不同批次，在 semver 里
        // b1 与 b2 的先后取决于后缀规则，而「不等即升级」天然覆盖，
        // 不需要引擎理解后缀语义。
        //
        // 但纯不等判断有个洞：如果用户当前跑的比远端还新（例如开发机装了
        // 1.1.6 而正式清单还是 1.1.5-b2），界面会给出一个「更新」按钮，
        // 点下去等于降级 —— 这会覆盖掉更新的代码。
        // 所以额外用 compareVersionKey 判一次方向：只有远端真正更新才 hasUpdate。
        $hasUpdate = false;
        $direction = 'same';
        if ($remote !== '' && $remote !== $current) {
            $cmp = compareAppVersion($remote, $current);
            $hasUpdate = $cmp > 0;
            $direction = $cmp > 0 ? 'upgrade' : ($cmp < 0 ? 'downgrade' : 'diff');
        }

        respond([
            'success'        => true,
            'currentVersion' => $current,
            'remoteVersion'  => $remote,
            'hasUpdate'      => $hasUpdate,
            // 便于前端区分「有新版本」与「本地更新但版本号不同」两种情形
            'direction'      => $direction,
            'isDowngrade'    => $direction === 'downgrade',
            'publishedAt'    => (string) ($m['publishedAt'] ?? ''),
            'notes'          => (string) ($m['notes'] ?? ''),
            'fileCount'      => count($m['files']),
            // 钉住后的实际来源地址，便于排查「清单说新版、文件却来自旧别名」
            'resolvedUrl'    => (string) ($r['url'] ?? $url),
        ]);
    }

    /** POST /update/apply —— 执行远程更新（body: {dryRun?: bool}） */
    public static function apply()
    {
        $b   = Req::body();
        $cfg = cfg();
        $url = (string) ($cfg['update']['manifest_url'] ?? '');
        if ($url === '') {
            respond(['success' => false, 'error' => 'UPDATE_SOURCE_NOT_CONFIGURED'], 400);
        }

        $r = fetchManifest($url);
        if (!$r['ok']) {
            respond(['success' => false, 'error' => $r['error']], 502);
        }

        // 用 fetchManifest 钉住版本后的地址拼下载路径。
        // 配 @latest 时它已被解析成 @v<具体版本>，避免清单说新版、
        // 文件却从滞后的别名路径下载。
        $srcUrl = (string) ($r['url'] ?? $url);
        $res    = applyRemoteUpdate($r['manifest'], $srcUrl, (bool) ($b['dryRun'] ?? false));

        respond($res, $res['ok'] ? 200 : 500);
    }

    /** POST /update/apply-local —— 应用本地包（body: {name, dryRun?}） */
    public static function applyLocal()
    {
        $b    = Req::body();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            respond(['success' => false, 'error' => 'PACKAGE_NAME_REQUIRED'], 400);
        }

        $res = applyLocalPackage($name, (bool) ($b['dryRun'] ?? false));
        respond($res, $res['ok'] ? 200 : 500);
    }
}
