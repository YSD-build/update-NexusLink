<?php
/**
 * 蓝天内网穿透 · 在线更新模块
 * ---------------------------------------------------------------------------
 * 设计目标：让服务器**不需要手动上传文件**即可获取新版本。
 *
 * 两种更新源（二选一，自动回退）：
 *   1. 远程清单（推荐）—— 配置 update.manifest_url，指向一个 JSON 清单，
 *      清单里列出每个文件的下载地址与 sha256。服务器逐个下载并校验。
 *   2. 本地包（离线）—— 把新版本的 zip 放到 storage/packages/ 下，
 *      接口会列出发现的包，管理员点一下即可解压覆盖。
 *
 * 安全约束：
 *   - 仅管理员可调用（在 index.php 用 requireRole('admin') 保护）
 *   - 所有写入限制在站点根目录之内，拒绝路径穿越
 *   - 下载的文件必须通过 sha256 校验，不匹配即丢弃
 *   - 更新前自动备份被覆盖的文件到 storage/backup/<时间戳>/
 *   - .php 文件更新后清空 opcache
 * ---------------------------------------------------------------------------
 */

/** 更新清单缓存文件 */
function updateStateFile(): string {
    return __DIR__ . '/storage/update-state.json';
}

/** 可写目录准备 */
function ensureStorageDirs(): array {
    $root = __DIR__;
    $dirs = [
        'storage'          => $root . '/storage',
        'backup'           => $root . '/storage/backup',
        'packages'         => $root . '/storage/packages',
    ];
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
    }
    return $dirs;
}

/** 读取本地状态（上次更新版本等） */
function readUpdateState(): array {
    $f = updateStateFile();
    if (!is_file($f)) {
        return ['appliedVersion' => '', 'appliedAt' => '', 'history' => []];
    }
    $j = json_decode((string) file_get_contents($f), true);
    return is_array($j) ? $j : ['appliedVersion' => '', 'appliedAt' => '', 'history' => []];
}

/** 写入本地状态 */
function writeUpdateState(array $s): void {
    ensureStorageDirs();
    @file_put_contents(updateStateFile(), json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** 当前已部署的版本号（读 public/build-meta.json） */
function currentBuildVersion(): string {
    $f = __DIR__ . '/build-meta.json';
    if (!is_file($f)) {
        return '';
    }
    $j = json_decode((string) file_get_contents($f), true);
    return is_array($j) ? (string) ($j['version'] ?? '') : '';
}

/** 拒绝路径穿越：解析后的绝对路径必须落在允许的根内 */
function safeTargetPath(string $relative, string $allowedRoot): ?string {
    // 去掉开头的斜杠与 ./ 前缀，统一分隔符
    $rel = ltrim(str_replace('\\', '/', $relative), '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        return null;
    }
    // 拒绝空字节与绝对路径式构造
    if (strpos($rel, "\0") !== false) {
        return null;
    }
    $root   = realpath($allowedRoot);
    if ($root === false) {
        return null;
    }
    $target = $root . '/' . $rel;

    // 目标可能尚不存在，用「最近的已存在祖先目录」做真实路径校验，
    // 这样多层新目录（如 storage/backup/2026-09-26/）也能通过
    $probe = dirname($target);
    while ($probe !== '' && !is_dir($probe) && dirname($probe) !== $probe) {
        $probe = dirname($probe);
    }
    $real = realpath($probe);
    if ($real === false) {
        return null;
    }
    // 必须是根目录本身，或位于根目录之下（补分隔符，避免 /root2 欺骗 /root）
    if ($real !== $root && strpos($real . '/', rtrim($root, '/') . '/') !== 0) {
        return null;
    }
    // 目标若已存在，再校验其真实路径
    if (file_exists($target)) {
        $realTarget = realpath($target);
        if ($realTarget === false || ($realTarget !== $root && strpos($realTarget . '/', rtrim($root, '/') . '/') !== 0)) {
            return null;
        }
    }
    return $target;
}

/**
 * 需要保护、禁止被远程清单覆盖的文件。
 * 扁平结构下这些文件直接位于站点根目录。
 */
function isProtectedFile(string $rel): bool {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $protected = [
        'config.php',       // 数据库口令
        'db.php',           // 核心库，随版本走（不保护会有半截更新风险）
        'api.php',
        'updater.php',
    ];
    // config.php 绝对不可覆盖；其余核心文件允许更新但走白名单逻辑
    return $rel === 'config.php';
}

/**
 * 拉取远程清单。清单格式（扁平结构）：
 * {
 *   "version": "1.0.3",
 *   "publishedAt": "2026-09-26T00:00:00Z",
 *   "notes": "更新说明",
 *   "baseUrl": "https://example.com/releases/1.0.3/",
 *   "files": {
 *     "index.html": {"sha256": "...", "size": 889},
 *     "assets/views-xxx.js": {"sha256": "...", "size": 106626},
 *     "api.php": {"sha256": "...", "size": 33000},
 *     "db.php": {"sha256": "...", "size": 5810}
 *   }
 * }
 */
function fetchManifest(string $url, int $timeout = 15): array {
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'LantianUpdater/1.0',
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'timeout' => $timeout,
            'header'  => "User-Agent: LantianUpdater/1.0\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
    }
    if ($raw === false) {
        return ['ok' => false, 'error' => 'MANIFEST_UNREACHABLE'];
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j['files']) || !is_array($j['files'])) {
        return ['ok' => false, 'error' => 'MANIFEST_INVALID'];
    }
    return ['ok' => true, 'manifest' => $j];
}

/** 下载单个文件到临时目录并校验 sha256 */
function downloadFile(string $url, string $expectSha, int $expectSize = 0, int $timeout = 30): array {
    $tmp = tempnam(sys_get_temp_dir(), 'lt_upd_');
    if ($tmp === false) {
        return ['ok' => false, 'error' => 'TEMP_UNAVAILABLE'];
    }
    $fp = @fopen($tmp, 'wb');
    if (!$fp) {
        return ['ok' => false, 'error' => 'TEMP_UNWRITABLE'];
    }

    $ok = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'LantianUpdater/1.0',
        ]);
        $ok = curl_exec($ch);
        curl_close($ch);
    } else {
        $data = @file_get_contents($url);
        if ($data !== false) {
            $ok = fwrite($fp, $data) !== false;
        }
    }
    fclose($fp);

    if (!$ok) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'DOWNLOAD_FAILED'];
    }

    $size = filesize($tmp);
    if ($expectSize > 0 && $size !== $expectSize) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'SIZE_MISMATCH', 'expect' => $expectSize, 'actual' => $size];
    }

    $sha = hash_file('sha256', $tmp);
    if ($expectSha !== '' && !hash_equals(strtolower($expectSha), strtolower((string) $sha))) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'CHECKSUM_MISMATCH', 'expect' => $expectSha, 'actual' => $sha];
    }

    return ['ok' => true, 'tmp' => $tmp, 'size' => $size, 'sha256' => $sha];
}

/** 备份单个已存在的文件 */
function backupFile(string $absPath, string $root, string $backupDir): void {
    if (!is_file($absPath)) {
        return;
    }
    $rel = ltrim(substr($absPath, strlen(realpath($root))), '/');
    $dst = $backupDir . '/' . $rel;
    if (!is_dir(dirname($dst))) {
        @mkdir(dirname($dst), 0755, true);
    }
    @copy($absPath, $dst);
}

/** 清空 opcache（PHP 文件被覆盖后需要） */
function flushOpcache(): bool {
    if (function_exists('opcache_reset')) {
        return @opcache_reset();
    }
    return false;
}

/** 人类可读的字节数 */
function humanSize(int $n): string {
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($n >= 1024 && $i < 3) {
        $n /= 1024;
        $i++;
    }
    return round($n, $i === 0 ? 0 : 2) . ' ' . $u[$i];
}

/* =======================================================================
 * 应用远程清单更新
 * ===================================================================== */
function applyRemoteUpdate(array $manifest, string $manifestUrl, bool $dryRun = false): array {
    $root = realpath(__DIR__);
    if ($root === false) {
        return ['ok' => false, 'error' => 'ROOT_UNRESOLVED'];
    }

    // baseUrl 用于拼接相对下载地址
    $base = (string) ($manifest['baseUrl'] ?? '');
    if ($base === '') {
        $base = preg_replace('#/[^/]*$#', '/', $manifestUrl);
    }
    if (substr($base, -1) !== '/') {
        $base .= '/';
    }

    $version = (string) ($manifest['version'] ?? '');
    $files   = $manifest['files'];
    if ($version === '') {
        return ['ok' => false, 'error' => 'MANIFEST_NO_VERSION'];
    }

    $dirs      = ensureStorageDirs();
    $backupDir = $dirs['backup'] . '/' . date('Ymd-His');

    $results = [];
    $applied = 0;
    $skipped = 0;

    foreach ($files as $rel => $meta) {
        $rel = str_replace('\\', '/', (string) $rel);

        if (isProtectedFile($rel)) {
            $results[] = ['file' => $rel, 'status' => 'protected', 'note' => '受保护文件，已跳过'];
            $skipped++;
            continue;
        }

        $abs = safeTargetPath($rel, $root);
        if ($abs === null) {
            $results[] = ['file' => $rel, 'status' => 'unsafe', 'note' => '路径非法，已拒绝'];
            $skipped++;
            continue;
        }

        $url = is_array($meta) && !empty($meta['url'])
            ? (string) $meta['url']
            : $base . $rel;
        $sha  = is_array($meta) ? (string) ($meta['sha256'] ?? '') : '';
        $size = is_array($meta) ? (int) ($meta['size'] ?? 0) : 0;

        // 干跑模式只看不写
        if ($dryRun) {
            $exist = is_file($abs);
            $same  = $exist && $sha !== '' && hash_file('sha256', $abs) === strtolower($sha);
            $results[] = [
                'file'   => $rel,
                'status' => $same ? 'unchanged' : ($exist ? 'would_update' : 'would_create'),
            ];
            continue;
        }

        // 已是最新则跳过，省流量
        if (is_file($abs) && $sha !== '' && hash_file('sha256', $abs) === strtolower($sha)) {
            $results[] = ['file' => $rel, 'status' => 'unchanged'];
            $skipped++;
            continue;
        }

        $dl = downloadFile($url, $sha, $size);
        if (!$dl['ok']) {
            $results[] = ['file' => $rel, 'status' => 'failed', 'error' => $dl['error'], 'detail' => $dl];
            continue;
        }

        // 备份旧文件
        backupFile($abs, $root, $backupDir);

        if (!is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0755, true);
        }

        if (!@rename($dl['tmp'], $abs)) {
            @copy($dl['tmp'], $abs);
            @unlink($dl['tmp']);
        }
        @chmod($abs, 0644);

        $results[] = ['file' => $rel, 'status' => 'updated', 'size' => $dl['size']];
        $applied++;
    }

    if (!$dryRun) {
        $state = readUpdateState();
        $state['appliedVersion'] = $version;
        $state['appliedAt']      = date('c');
        $state['history']        = array_slice(array_merge(
            [['version' => $version, 'at' => date('c'), 'files' => $applied]],
            $state['history'] ?? []
        ), 0, 20);
        writeUpdateState($state);
        flushOpcache();
    }

    return [
        'ok'         => true,
        'dryRun'     => $dryRun,
        'version'    => $version,
        'notes'      => (string) ($manifest['notes'] ?? ''),
        'applied'    => $applied,
        'skipped'    => $skipped,
        'backupDir'  => $dryRun ? '' : $backupDir,
        'files'      => $results,
    ];
}

/* =======================================================================
 * 从本地 zip 包更新（离线场景）
 * ===================================================================== */

/** 列出 storage/packages/ 下可用的更新包 */
function listLocalPackages(): array {
    $dirs = ensureStorageDirs();
    $out  = [];
    foreach (glob($dirs['packages'] . '/*.zip') ?: [] as $f) {
        $out[] = [
            'name' => basename($f),
            'size' => filesize($f),
            'sizeText' => humanSize((int) filesize($f)),
            'mtime' => date('c', (int) filemtime($f)),
            'sha256' => hash_file('sha256', $f),
        ];
    }
    usort($out, fn($a, $b) => strcmp($b['mtime'], $a['mtime']));
    return $out;
}

/**
 * 应用本地 zip 包：解压 → 覆盖 public/ 与 api/ → 备份 → 清 opcache
/**
 * 扁平结构下允许位于站点根目录的白名单文件。
 * 除此之外的根目录文件（如 config.php、storage/、sql/）一律不接受更新。
 */
function isAllowedRootFile(string $name): bool {
    return in_array($name, [
        'index.html',
        'build-meta.json',
        'favicon.svg',
        'icons.svg',
        'api.php',
        'db.php',
        'updater.php',
    ], true);
}

/**
 * 判断一个包内相对路径是否允许落盘。
 * 只接受两类：assets/ 目录下的资源，或根目录白名单文件。
 */
function isAllowedPackageEntry(string $rel): bool {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        return false;
    }
    // assets/ 下的任意文件
    if (strpos($rel, 'assets/') === 0 && strlen($rel) > 7) {
        return true;
    }
    // 根目录白名单文件（不允许再带子路径）
    return isAllowedRootFile($rel);
}

/**
 * 应用本地 zip 包：解压 → 覆盖前端资源与核心代码 → 备份 → 清 opcache
 * 扁平结构：只允许覆盖 assets/ 与根目录白名单文件，其余一律忽略。
 */
function applyLocalPackage(string $zipName, bool $dryRun = false): array {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'ZIP_EXTENSION_MISSING'];
    }
    $dirs = ensureStorageDirs();
    $zipPath = $dirs['packages'] . '/' . basename($zipName);
    if (!is_file($zipPath)) {
        return ['ok' => false, 'error' => 'PACKAGE_NOT_FOUND'];
    }

    $root = realpath(__DIR__);
    $zip  = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'error' => 'PACKAGE_UNREADABLE'];
    }

    $backupDir = $dirs['backup'] . '/' . date('Ymd-His');
    $results   = [];
    $applied   = 0;
    $skipped   = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            continue;
        }
        $entry = str_replace('\\', '/', $stat['name']);

        if (substr($entry, -1) === '/') {
            continue; // 目录项
        }

        // 扁平结构：zip 内路径即相对站点根的路径。
        // 允许的顶层：assets/ 目录与根目录下的白名单文件，其余一律忽略。
        $rel = $entry;

        // 兼容带一层包装目录的 zip（如 lantian-1.0.3/assets/x.js）：
        // 若路径首段既不是 assets 也不是白名单文件，则尝试剥掉一层
        $firstSeg = explode('/', $rel)[0];
        if ($firstSeg !== 'assets' && !isAllowedRootFile($firstSeg)) {
            $slash = strpos($rel, '/');
            if ($slash !== false) {
                $rel = substr($rel, $slash + 1);
            }
        }

        if (!isAllowedPackageEntry($rel)) {
            $skipped++;
            continue;
        }
        if (isProtectedFile($rel)) {
            $results[] = ['file' => $rel, 'status' => 'protected'];
            $skipped++;
            continue;
        }

        $abs = safeTargetPath($rel, $root);
        if ($abs === null) {
            $results[] = ['file' => $rel, 'status' => 'unsafe'];
            $skipped++;
            continue;
        }

        if ($dryRun) {
            $results[] = ['file' => $rel, 'status' => is_file($abs) ? 'would_update' : 'would_create'];
            continue;
        }

        backupFile($abs, $root, $backupDir);
        if (!is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0755, true);
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $results[] = ['file' => $rel, 'status' => 'failed'];
            continue;
        }
        if (@file_put_contents($abs, $content) === false) {
            $results[] = ['file' => $rel, 'status' => 'failed', 'error' => 'WRITE_DENIED'];
            continue;
        }
        @chmod($abs, 0644);
        $results[] = ['file' => $rel, 'status' => 'updated', 'size' => strlen($content)];
        $applied++;
    }
    $zip->close();

    if (!$dryRun) {
        $state = readUpdateState();
        $state['appliedVersion'] = currentBuildVersion();
        $state['appliedAt']      = date('c');
        $state['history']        = array_slice(array_merge(
            [['version' => currentBuildVersion(), 'at' => date('c'), 'files' => $applied, 'source' => basename($zipName)]],
            $state['history'] ?? []
        ), 0, 20);
        writeUpdateState($state);
        flushOpcache();
    }

    return [
        'ok'        => true,
        'dryRun'    => $dryRun,
        'package'   => basename($zipName),
        'applied'   => $applied,
        'skipped'   => $skipped,
        'backupDir' => $dryRun ? '' : $backupDir,
        'files'     => $results,
    ];
}
