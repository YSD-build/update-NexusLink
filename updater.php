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

/** 可写目录准备（含权限自愈） */
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
    // 【修复】自愈权限：宝塔/虚拟主机下目录常属主为 www 而 PHP 以 www 运行，
    // 但上传解压后可能变成 root:root 0644，此时 storage/ 不可写，
    // 会导致更新状态无法保存、备份失败，面板显示「storage 可写：不可写」。
    // 这里在检测到不可写时主动尝试 chmod 0775；失败则由调用方报出真实原因。
    foreach ($dirs as $d) {
        if (is_dir($d) && !is_writable($d)) {
            @chmod($d, 0775);
        }
    }
    return $dirs;
}

/**
 * 诊断 storage 目录可写性，返回可读结论（供面板展示，避免只给一个红点）。
 * 逐级检查，明确指出是哪个目录、什么原因导致不可写。
 */
function diagnoseStorage(): array {
    $root  = __DIR__;
    $items = [
        ['storage',          $root . '/storage'],
        ['storage/backup',   $root . '/storage/backup'],
        ['storage/packages', $root . '/storage/packages'],
    ];
    $checks  = [];
    $allOk   = true;
    foreach ($items as [$label, $path]) {
        $exists = is_dir($path);
        $writ   = $exists ? is_writable($path) : false;
        $owner  = $exists ? (@fileowner($path) !== false ? (string) @posix_getpwuid(@fileowner($path))['name'] : '?') : '-';
        $perms  = $exists ? substr(sprintf('%o', @fileperms($path)), -4) : '-';
        $ok     = $exists && $writ;
        if (!$ok) { $allOk = false; }
        $checks[] = [
            'path'   => $label,
            'exists' => $exists,
            'writable' => $writ,
            'owner'  => $owner,
            'perms'  => $perms,
            'ok'     => $ok,
            'hint'   => $ok ? '' : (!$exists
                ? '目录不存在，且无法自动创建（父目录不可写）'
                : "目录存在但不可写（属主 {$owner}，权限 {$perms}）。"
                  . '请在宝塔中把该目录属主设为 PHP 运行用户（通常 www），权限 755。'),
        ];
    }
    return [
        'ok'      => $allOk,
        'root'    => $root,
        'phpUser' => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?',
        'checks'  => $checks,
    ];
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
    if (!is_array($j)) {
        return '';
    }
    // 【修复】优先读 appVersion（语义版本），再回退 version。
    // 历史上 version 字段被构建脚本写成 git 短哈希，直接读它会让界面显示
    // 形如 89a502cf1d79 的构建号而不是 1.0.3，用户无法判断版本。
    $v = (string) ($j['appVersion'] ?? '');
    if ($v !== '') {
        return $v;
    }
    // 回退：若 version 看起来像语义版本（含点号）才用，否则不显示哈希
    $legacy = (string) ($j['version'] ?? '');
    return strpos($legacy, '.') !== false ? $legacy : '';
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
    // 【修复】删除误导性的 $protected 死数组（原来定义了 4 个文件却只 return config.php）。
    // 扁平结构下唯一绝对禁止覆盖的文件就是 config.php —— 它含数据库口令，
    // 且必须由站长自行维护，任何更新源都不得触碰。
    // 其余核心文件（index.php / api.php / db.php / updater.php / health.php）
    // 均由 isAllowedRootFile() 白名单 + safeTargetPath() 路径校验共同管控。
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
/**
 * 构造绕过 CDN 缓存的请求头。
 *
 * 【为什么需要】
 * jsDelivr 对「分支名」形式的分支（@main）按文件缓存 12 小时，且是
 * 逐个文件独立的。发布新版本后，可能出现 manifest.json 已回源、
 * build-meta.json 还在旧缓存的情况 —— 清单说 1.1.0，本地读到 1.0.4，
 * 于是一直判定「已是最新」，用户完全不知道该点哪里。
 *
 * 加上 no-cache 请求头，让各层缓存都回源拿最新。
 * 对 tag（@v1.1.0）无害 —— tag 是永久缓存，回源结果也一样。
 */
function cacheBustHeaders(): array {
    return [
        'Cache-Control: no-cache, no-store, max-age=0',
        'Pragma: no-cache',
    ];
}

/**
 * 给「分支名」形式的清单地址追加时间戳，强制拿到最新内容。
 *
 * 只处理分支，不处理 tag：
 *   @main       → 追加 ?t=时间戳   （分支会变，必须绕缓存）
 *   @v1.1.0     → 原样返回          （tag 内容永不变，绕了反而浪费回源）
 *
 * 判断依据：路径里的 @ 之后如果是 v+数字开头，视为版本 tag。
 * 这样用户无论配 @main 还是 @v1.1.0，行为都正确，不需要改配置。
 */
function bustCdnCache(string $url): string {
    $p = parse_url($url);
    if ($p === false || empty($p['host']) || empty($p['path'])) {
        return $url;
    }
    // 只看 URL 里是否出现 jsDelivr 风格的 @ref
    if (!preg_match('~@([^/]+)/~', $p['path'], $m)) {
        return $url; // 自建静态空间等，没有 @ref 概念
    }
    $ref = $m[1];
    // v1 / v1.2 / v1.2.3 这类视为不可变 tag，不追加参数
    if (preg_match('~^v?\d+(\.\d+)*$~', $ref)) {
        return $url;
    }
    // 分支名（main / master / dev …）：追加时间戳
    $sep = strpos($url, '?') === false ? '?' : '&';
    return $url . $sep . 't=' . time();
}

/* ==================== 多源镜像（国内加速） ==================== */

/**
 * 把 jsDelivr 的 gh 地址按指定镜像替换。
 *
 * 【为什么需要】
 * jsDelivr 本身是全球加速（Fastly + Cloudflare + Gcore 三套 CDN 组合），
 * 但它对国内用户的致命问题是：**解析不稳定**。
 * cdn.jsdelivr.net 在不同省份会解析到 151.101.x（Fastly）或 104.17.x
 * （Cloudflare），其中部分 IP 段被污染，表现为连接超时或 TLS 中断。
 *
 * 官方为此提供了多个同源镜像域名，内容完全一致、只是解析路径不同：
 *   cdn.jsdelivr.net        自动选择（可能是 Fastly 或 CF）
 *   fastly.jsdelivr.net     固定走 Fastly
 *   gcore.jsdelivr.net      固定走 Gcore
 *   testingcf.jsdelivr.net  固定走 Cloudflare
 *
 * 不同网络环境下哪个最快完全不同，所以正确做法是**多源依次尝试**，
 * 第一个成功的就用，而不是赌某一个。
 *
 * @param string $url    原始清单/文件地址
 * @param string $mirror 镜像主机名；空字符串 = 保持原样
 */
function applyMirror(string $url, string $mirror): string {
    if ($mirror === '') {
        return $url;
    }
    // 只处理 jsDelivr 官方域名，自建空间/其他 CDN 不动
    return preg_replace(
        '~^https?://(?:cdn|fastly|gcore|testingcf|testingrs)\.jsdelivr\.net/~i',
        'https://' . $mirror . '/',
        $url
    );
}

/**
 * 取「清单地址 → 镜像地址列表」，第一个是首选源。
 *
 * 配置来源（config.php 的 update 段）：
 *   'manifest_mirrors' => ['cdn.jsdelivr.net', 'fastly.jsdelivr.net', ...]
 *
 * 未配置时给一套默认顺序 —— 让老用户升级后自动获得多源能力，
 * 不需要改配置。顺序按「国内实测最稳」排：
 *   1. fastly   —— 固定节点，不易被 DNS 污染影响
 *   2. testingcf—— 固定走 Cloudflare
 *   3. cdn      —— 官方自动选择
 *   4. gcore    —— 固定走 Gcore
 *
 * @return string[] 去掉重复与空值后的镜像主机名列表
 */
function manifestMirrors(): array {
    $cfg  = cfg();
    $user = $cfg['update']['manifest_mirrors'] ?? null;

    if (is_array($user) && $user) {
        $list = $user;
    } else {
        $list = [
            'fastly.jsdelivr.net',
            'testingcf.jsdelivr.net',
            'cdn.jsdelivr.net',
            'gcore.jsdelivr.net',
        ];
    }

    $out = [];
    foreach ($list as $m) {
        $m = trim((string) $m);
        if ($m !== '' && !in_array($m, $out, true)) {
            $out[] = $m;
        }
    }
    return $out; // 已去重
}

/**
 * 从字面量 URL 里抽出一个「可作为镜像替换基准」的地址。
 *
 * 用于文件下载：manifest 里的文件地址是从清单 URL 派生出来的，
 * 可能已经是某个镜像的域名。我们要把它统一回 cdn.jsdelivr.net 基准，
 * 再按镜像列表逐个替换，否则换镜像时不会生效。
 */
function normalizeToBaseMirror(string $url): string {
    return preg_replace(
        '~^https?://(?:fastly|gcore|testingcf|testingrs)\.jsdelivr\.net/~i',
        'https://cdn.jsdelivr.net/',
        $url
    );
}

/**
 * 带镜像回退的 GET：依次尝试每个源，第一个成功即返回。
 *
 * @param string   $url      基准地址（通常含 cdn.jsdelivr.net）
 * @param callable $attempt  签名 fn(string $candidateUrl): mixed
 *                           返回非 false 视为成功
 * @param string[] $mirrors  镜像主机名列表
 * @param int      $perTry   单次超时（秒）
 * @return array{ok:bool, value:mixed, mirror:string, error:string, tried:array}
 */
/* ==================== 最新版本自动解析 ==================== */

/**
 * 从 jsDelivr 地址里解析出 GitHub owner/repo（仅 gh 类型）。
 * 返回 ['YSD-build','update-NexusLink'] 或 null。
 */
function parseGhRepo(string $url): ?array {
    if (!preg_match('~jsdelivr\.net/gh/([^/@]+)/([^/@]+)@~i', $url, $m)) {
        return null;
    }
    return [$m[1], $m[2]];
}

/**
 * 查 GitHub 的 tags 列表，用语义版本比较取最大值。
 *
 * 【为什么需要这个】
 * jsDelivr 的 @latest 别名**不实时**。实测：v1.1.3 推上去后 2 分钟，
 * 四个镜像的 @latest 全部仍返回 1.1.2，而 @v1.1.3（显式 tag）秒回源。
 * 更糟的是 data.jsdelivr.com 的索引当时还停在 1.1.1。
 *
 * 所以「永久地址」如果写 @latest，发版后用户点检查更新会拿到旧版本，
 * 且没有任何报错 —— 正是 1.1.1 修过的那类"看起来正常其实错了"的坑。
 *
 * 解法：**让引擎自己去 GitHub 问最新 tag**，再拼出显式 tag 的清单地址。
 * GitHub tags API 是实时的（无需 token、不限速到不可用），实测推完即见。
 *
 * @return array{ok:bool, version:string, tag:string}
 */
function resolveLatestTag(string $manifestUrl): array {
    $repo = parseGhRepo($manifestUrl);
    if ($repo === null) {
        return ['ok' => false, 'version' => '', 'tag' => ''];
    }
    [$owner, $name] = $repo;

    $api = "https://api.github.com/repos/{$owner}/{$name}/tags?per_page=100";
    $raw = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'LantianUpdater/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'header'  => "User-Agent: LantianUpdater/1.0\r\nAccept: application/vnd.github+json\r\n",
        ]]);
        $raw = @file_get_contents($api, false, $ctx);
    }

    if ($raw === false) {
        return ['ok' => false, 'version' => '', 'tag' => ''];
    }
    $list = json_decode($raw, true);
    if (!is_array($list) || !$list) {
        return ['ok' => false, 'version' => '', 'tag' => ''];
    }

    // 只认 v?数字.数字 形式，按语义版本取最大（不能按字符串比：
    // "1.1.9" > "1.1.10" 是错的）
    $best = '';
    $bestKey = [-1];
    foreach ($list as $t) {
        $tag = (string) ($t['name'] ?? '');
        if (!preg_match('~^v?(\d+(?:\.\d+)*)$~', $tag, $m)) {
            continue; // 跳过 1.0.0-test 之类的预发布
        }
        $key = array_map('intval', explode('.', $m[1]));
        // 补零对齐后比较，保证 1.1 与 1.1.0 视为同级
        while (count($key) < count($bestKey)) { $key[] = 0; }
        $cmpLeft = $key;
        $cmpRight = $bestKey;
        while (count($cmpRight) < count($cmpLeft)) { $cmpRight[] = 0; }
        if ($best === '' || $cmpLeft > $cmpRight) {
            $best = $tag;
            $bestKey = $key;
        }
    }

    if ($best === '') {
        return ['ok' => false, 'version' => '', 'tag' => ''];
    }
    return ['ok' => true, 'version' => ltrim($best, 'v'), 'tag' => $best];
}

/**
 * 把一个「含可变引用（@latest / @main）」的清单地址，解析成具体的 tag 地址。
 *
 *   .../update-NexusLink@latest/manifest.json
 *     → .../update-NexusLink@v1.1.3/manifest.json
 *
 * 已经是具体 tag 的地址原样返回（不做额外网络请求）。
 */
function pinManifestUrl(string $url): string {
    if (preg_match('~jsdelivr\.net/gh/[^/@]+/[^/@]+@(v?\d+(?:\.\d+)*)/~i', $url)) {
        return $url; // 已是显式版本，不用动
    }
    $r = resolveLatestTag($url);
    if (!$r['ok']) {
        return $url; // 解析失败就按原样试，让多源回退去处理
    }
    return preg_replace(
        '~jsdelivr\.net/gh/([^/@]+/[^/@]+)@[^/]+/~i',
        'jsdelivr.net/gh/$1@' . $r['tag'] . '/',
        $url
    );
}

function withMirrorFallback(string $url, callable $attempt, array $mirrors, int $perTry = 15): array {
    $base  = normalizeToBaseMirror($url);
    $tried = [];

    foreach ($mirrors as $m) {
        $candidate = applyMirror($base, $m);
        $t0 = microtime(true);
        $r  = $attempt($candidate, $perTry);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        if ($r !== false) {
            $tried[] = ['mirror' => $m, 'ok' => true, 'ms' => $ms];
            return ['ok' => true, 'value' => $r, 'mirror' => $m, 'error' => '', 'tried' => $tried];
        }
        $tried[] = ['mirror' => $m, 'ok' => false, 'ms' => $ms];
    }

    return ['ok' => false, 'value' => null, 'mirror' => '', 'error' => 'ALL_MIRRORS_FAILED', 'tried' => $tried];
}

function fetchManifest(string $url, int $timeout = 15): array {
    $hdr = cacheBustHeaders();

    // 【先钉住版本】把 @latest / @main 这类可变引用解析成具体 tag。
    // 原因见 pinManifestUrl() 的注释：jsDelivr 的 @latest 缓存滞后，
    // 发版后可能几十分钟仍指向旧版本，而且不报错。
    $pinned = pinManifestUrl($url);
    if ($pinned !== $url) {
        $url = $pinned;
    }

    // 单次尝试：返回解码后的数组，失败返回 false
    $once = function (string $candidate, int $t) use ($hdr) {
        $u   = bustCdnCache($candidate);
        $raw = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => min(6, $t), // 连接阶段别等满超时
                CURLOPT_TIMEOUT        => $t,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'LantianUpdater/1.0',
                CURLOPT_HTTPHEADER     => $hdr,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'timeout' => $t,
                'header'  => "User-Agent: LantianUpdater/1.0\r\n" . implode("\r\n", $hdr) . "\r\n",
            ]]);
            $raw = @file_get_contents($u, false, $ctx);
        }

        if ($raw === false) {
            return false;
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['files']) || !is_array($j['files'])) {
            return false; // 内容不合法也换下一个源，可能是被劫持返回的错误页
        }
        return $j;
    };

    $mirrors = manifestMirrors();

    // 非 jsDelivr 地址（自建静态空间等）没有镜像概念，直接单源请求
    $isJsdelivr = (bool) preg_match('~^https?://(?:cdn|fastly|gcore|testingcf|testingrs)\.jsdelivr\.net/~i', $url);
    if (!$isJsdelivr) {
        $j = $once($url, $timeout);
        return $j === false
            ? ['ok' => false, 'error' => 'MANIFEST_UNREACHABLE']
            : ['ok' => true, 'manifest' => $j, 'url' => $url];
    }

    $r = withMirrorFallback($url, $once, $mirrors, $timeout);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => 'MANIFEST_UNREACHABLE', 'tried' => $r['tried']];
    }

    return [
        'ok'       => true,
        'manifest' => $r['value'],
        'mirror'   => $r['mirror'],   // 哪个源成功，前端可展示
        'url'      => $url,           // 钉住版本后的地址，供拼下载路径
        'tried'    => $r['tried'],
    ];
}

/** 下载单个文件到临时目录并校验 sha256（支持多源回退） */
function downloadFile(string $url, string $expectSha, int $expectSize = 0, int $timeout = 30): array {
    $hdr = cacheBustHeaders();

    // 单次尝试：成功（下载+校验通过）返回数组，失败返回 false
    $once = function (string $candidate, int $t) use ($hdr, $expectSha, $expectSize) {
        $u  = bustCdnCache($candidate);
        $tmp = tempnam(sys_get_temp_dir(), 'lt_upd_');
        if ($tmp === false) {
            return false;
        }
        $fp = @fopen($tmp, 'wb');
        if (!$fp) {
            @unlink($tmp);
            return false;
        }

        $ok = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => min(8, $t),
                CURLOPT_TIMEOUT        => $t,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'LantianUpdater/1.0',
                CURLOPT_HTTPHEADER     => $hdr,
            ]);
            $ok = curl_exec($ch);
            curl_close($ch);
        } else {
            $data = @file_get_contents($u);
            if ($data !== false) {
                $ok = fwrite($fp, $data) !== false;
            }
        }
        fclose($fp);

        if (!$ok) {
            @unlink($tmp);
            return false;
        }

        $size = filesize($tmp);
        if ($expectSize > 0 && $size !== $expectSize) {
            @unlink($tmp);
            return false; // 尺寸不符 → 可能被劫持/截断，换源重试
        }

        $sha = hash_file('sha256', $tmp);
        if ($expectSha !== '' && !hash_equals(strtolower($expectSha), strtolower((string) $sha))) {
            @unlink($tmp);
            return false; // 校验失败 → 换源重试（CDN 缓存了旧内容时会这样）
        }

        return ['tmp' => $tmp, 'size' => $size, 'sha256' => $sha];
    };

    // 非 jsDelivr：无镜像概念
    $isJsdelivr = (bool) preg_match('~^https?://(?:cdn|fastly|gcore|testingcf|testingrs)\.jsdelivr\.net/~i', $url);
    if (!$isJsdelivr) {
        $r = $once($url, $timeout);
        return $r === false
            ? ['ok' => false, 'error' => 'DOWNLOAD_FAILED']
            : ['ok' => true] + $r;
    }

    $r = withMirrorFallback($url, $once, manifestMirrors(), $timeout);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => 'DOWNLOAD_FAILED', 'tried' => $r['tried']];
    }

    return ['ok' => true, 'mirror' => $r['mirror'], 'tried' => $r['tried']] + $r['value'];
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
    //
    // 【注意】这里必须用「未打时间戳」的原始地址派生 base。
    // fetchManifest() 内部会调 bustCdnCache() 加 ?t=… 绕缓存，
    // 若拿那个加工过的地址来派生，base 会变成
    //   https://cdn.../lantian@main/?t=1234/     ← 时间戳夹在中间
    // 拼出的文件地址全部 404。所以先剥掉查询串。
    $cleanUrl = preg_replace('~\?.*$~', '', $manifestUrl);
    $base = (string) ($manifest['baseUrl'] ?? '');
    if ($base === '') {
        $base = preg_replace('#/[^/]*$#', '/', $cleanUrl);
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
        // 文件地址同样绕 CDN 缓存。分支形式（@main）下 jsDelivr 按文件独立
        // 缓存 12 小时，只刷清单不刷文件，就会出现「清单说新版、文件还是旧的」
        // —— 下载后 sha256 必然不符，更新在中途失败。
        $url = bustCdnCache($url);
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
        // 前端产物
        'index.html',
        'build-meta.json',
        'favicon.svg',
        'icons.svg',
        // 后端入口与核心库（随版本走）
        'index.php',        // 【修复】主入口，之前遗漏导致更新被静默跳过
        'api.php',
        'db.php',
        'updater.php',
        'health.php',       // 【修复】部署自检页
        // 发布清单本身（在线更新时用于比对，不落盘也无妨，但允许覆盖便于排查）
        'manifest.json',
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
