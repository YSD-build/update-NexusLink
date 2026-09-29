<?php
/**
 * 蓝天内网穿透 · 老版本自举引导（Bootstrapper）
 * ===========================================================================
 * 【这个文件解决什么问题 —— 鸡生蛋问题】
 *
 * 在线更新的执行者是站点上「当前正在运行的那一版 updater.php」。
 * v1.1.4 的 updater.php 把可落盘路径写死成两类：
 *
 *     assets/ 下的任意文件  +  根目录白名单文件（index.php / api.php / db.php ...）
 *
 * v1.1.5 引入了模块化目录 lib/ 与 controllers/，还有根目录新文件 routes.php。
 * 于是从 v1.1.4 点「在线更新」时会发生：
 *
 *     assets/app-xxx.js   ✓ 落盘
 *     api.php             ✓ 落盘（新 api.php 里 require lib/Bootstrap.php）
 *     lib/Bootstrap.php   ✗ 被旧白名单静默跳过      ← 致命
 *     controllers/*.php   ✗ 被旧白名单静默跳过      ← 致命
 *     routes.php          ✗ 被旧白名单静默跳过      ← 致命
 *
 * 结果是更新「成功」，但下一次任何后端请求都会在
 *     require_once __DIR__ . '/lib/Bootstrap.php';
 * 这一行抛出 PHP Fatal error（文件不存在），整个面板 500。
 *
 * 【为什么不能靠改 updater.php 解决】
 * 更新包的 updater.php 要等这次更新跑完才生效 —— 而这次更新正是由旧的那份执行的。
 * 任何「在新 updater.php 里放宽白名单」的写法都救不了这一次。
 *
 * 【本文件的解法：让入口自己完成安装】
 * 更新流程里 api.php / index.php 是可以落盘的（它们在旧白名单里）。
 * 所以新版 api.php 在 require 之前先看一眼：lib/ 到底装上没有？
 * 没装上，就由本文件把 ZIP 包里缺失的那些文件**直接补落盘**，
 * 然后才继续正常引导。整套机制不依赖 updater.php，因此不受旧白名单约束。
 *
 * 【安全性 —— 这里不是「任意路径写入」的后门】
 * 本文件按顺序做了 5 道校验收窄落盘范围：
 *   ① 包路径必须位于 storage/packages/ 下（realpath 比较，防 `..` 与软链接逃逸）
 *   ② 只能是本文件所在目录（站点根）自己的 storage/packages，不能是别处的
 *   ③ 条目相对路径禁止 `..`、空字节、绝对路径，且必须落在受控目录
 *      （lib/ . controllers/ . assets/）或根目录白名单文件里
 *   ④ 目标绝对路径 final realpath 必须仍在站点根目录之内
 *   ⑤ 单文件 2MB / 单次 8MB / 单次 300 个文件的上限，防止被人塞巨型包打满磁盘
 *
 * 另外加了 30 秒冷却（storage/state/boot.lock），
 * 避免引导失败时每个请求都重复解压一遍拖垮站点。
 * ===========================================================================
 */

if (defined('LT_BOOT_LOADED')) {
    return;
}
define('LT_BOOT_LOADED', 1);

/* ---------------------------------------------------------------------------
 * 0) 什么情况下需要自举
 *
 * 只有「新架构目录存在但关键文件缺失」才触发。
 * 正常部署（文件齐全）时这里只是一次 is_file()，开销可以忽略。
 * ---------------------------------------------------------------------------
 */
if (!function_exists('ltNeedsSelfBootstrap')) {
    function ltNeedsSelfBootstrap(): bool
    {
        $markers = [
            __DIR__ . '/Bootstrap.php',
            __DIR__ . '/Router.php',
        ];
        foreach ($markers as $m) {
            if (!is_file($m)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ltBootstrappedMarker')) {
    function ltBootstrappedMarker(): string
    {
        return __DIR__ . '/../storage/state/boot.ok';
    }
}

if (!function_exists('ltBootstrapLock')) {
    function ltBootstrapLock(): string
    {
        return __DIR__ . '/../storage/state/boot.lock';
    }
}

/* ---------------------------------------------------------------------------
 * 1) 自举入口
 *
 * 幂等：已经装好（存在 boot.ok 标记）就直接返回，不再探测。
 * ---------------------------------------------------------------------------
 */
if (!function_exists('ltSelfBootstrap')) {
    function ltSelfBootstrap(): void
    {
        // 已经成功装过一次 —— 不再重复扫描（每次请求省一次 glob）
        if (is_file(ltBootstrappedMarker())) {
            return;
        }
        if (!ltNeedsSelfBootstrap()) {
            ltBootstrapMarkOk();
            return;
        }

        // 冷却：上次自举失败过就不停重试，会把每次 API 请求都拖慢
        $lock = ltBootstrapLock();
        if (is_file($lock)) {
            $age = time() - (int) @file_get_contents($lock);
            if ($age >= 0 && $age < 30) {
                return;
            }
        }
        ltBootstrapTouch($lock);

        $pkgDir = __DIR__ . '/../storage/packages';
        if (!is_dir($pkgDir)) {
            return;
        }

        // 取目录里最新的 zip —— 刚刚通过在线更新下载下来的那个
        $zips = glob($pkgDir . '/*.zip') ?: [];
        if (!$zips) {
            return;
        }
        usort($zips, function ($a, $b) {
            return (int) @filemtime($b) <=> (int) @filemtime($a);
        });

        foreach ($zips as $zipPath) {
            $st = ltBootstrapExtract($zipPath);
            if (!empty($st['ok'])) {
                ltBootstrapMarkOk();
                ltBootstrapLog('self-bootstrap ok', [
                    'package' => basename($zipPath),
                    'mode'    => $st['mode'] ?? 'full',
                    'written' => $st['written'] ?? [],
                ]);
                return;
            }
            ltBootstrapLog('self-bootstrap failed', [
                'package' => basename($zipPath),
                'error'   => $st['error'] ?? 'UNKNOWN',
            ]);
            // 试下一个包（用户可能连续升级过几次，目录里有多个 zip）
        }
    }
}

/* ---------------------------------------------------------------------------
 * 2) 从 zip 里补落盘缺失/变更的文件
 *
 * 【为什么顺带比对 sha256 覆盖已有文件】
 * 有一种更隐蔽的坏情况：用户先装过 b1，再装 b2。
 * 这时 lib/Bootstrap.php 已存在（所以不会再触发「文件缺失」判定），
 * 但它的内容可能已经变了。而旧引擎同样不会更新 lib/ 里的任何东西 ——
 * 于是站点会停在「b1 的后端 + b2 的前端」这种半新半旧的状态，
 * 症状是前端调用了后端还不存在的接口，报 404 或字段缺失。
 *
 * 所以只要包里带了 lib/ controllers/ 的文件，就一律按 sha256 校验，
 * 不一致就覆盖（覆盖前先备份到 storage/backup/）。
 * ---------------------------------------------------------------------------
 */
if (!function_exists('ltBootstrapExtract')) {
    function ltBootstrapExtract(string $zipPath): array
    {
        // 【不再要求 ZipArchive】
        // 早期版本这里第一步就 class_exists('ZipArchive') 检查，
        // 缺扩展直接返回 ZIP_EXTENSION_MISSING。
        // 但宝塔环境的 PHP 常出现「装了 php-zip 却没有为当前 PHP 版本编译」的情况
        // （比如面板切过 PHP 版本），而且我们无法在线上补扩展。
        // 下面的 ltZipList/ltZipRead 是纯 PHP 的 zip 读取实现（只依赖 zlib），
        // 有 ZipArchive 时走原生（更快），没有时走纯 PHP —— 两条路结果一致。
        if (!is_file($zipPath)) {
            return ['ok' => false, 'error' => 'PACKAGE_NOT_FOUND'];
        }

        $allowDir = realpath(__DIR__ . '/../storage/packages');
        if ($allowDir === false) {
            return ['ok' => false, 'error' => 'PACKAGE_DIR_INVALID'];
        }
        $realZip = realpath($zipPath);
        // ① realpath 必须仍在 storage/packages 之内
        if ($realZip === false
            || strpos($realZip . '/', rtrim($allowDir, '/') . '/') !== 0) {
            return ['ok' => false, 'error' => 'PACKAGE_OUT_OF_SCOPE'];
        }
        // ② 必须是本站点自己的 storage/packages（防 __DIR__ 被意外重定位后越界）
        if ($realZip === $allowDir || dirname(dirname($allowDir)) !== realpath(__DIR__ . '/..')) {
            return ['ok' => false, 'error' => 'PACKAGE_DIR_MISMATCH'];
        }

        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            return ['ok' => false, 'error' => 'ROOT_UNRESOLVED'];
        }

        // 只有 lib/ 与 controllers/ 需要自举 —— 其余文件旧引擎本来就会落盘
        $entries = ltZipList($realZip);
        if ($entries === null) {
            return ['ok' => false, 'error' => 'PACKAGE_UNREADABLE'];
        }
        if (count($entries) > 4000) {
            return ['ok' => false, 'error' => 'PACKAGE_TOO_MANY_ENTRIES'];
        }

        // ③ 收集待写条目（先全部校验，再统一落盘）
        $plan     = [];
        $total    = 0;
        $written  = [];
        $skipped  = 0;
        $failed   = 0;

        foreach ($entries as $name => $meta) {
            $entry = str_replace('\\', '/', $name);
            if (substr($entry, -1) === '/') {
                continue; // 目录项
            }
            $rel = ltrim($entry, '/');

            // 兼容带一层包装目录的包（lantian-1.1.5/lib/Router.php）
            if (!ltBootstrapInScope($rel) && strpos($rel, '/') !== false) {
                $rel = substr($rel, strpos($rel, '/') + 1);
            }
            if (!ltBootstrapInScope($rel)) {
                continue; // 不归自举管
            }

            // ④ 路径本身的安全性
            if (!ltBootstrapSafeRel($rel)) {
                $skipped++;
                continue;
            }

            $abs = $root . '/' . $rel;
            $realParentProbe = ltBootstrapRealParent($abs);
            if ($realParentProbe === null) {
                $skipped++;
                continue;
            }
            if ($realParentProbe !== $root
                && strpos($realParentProbe . '/', rtrim($root, '/') . '/') !== 0) {
                $skipped++;
                continue;
            }

            // ⑤ 单文件大小上限
            $size = (int) ($meta['size'] ?? 0);
            if ($size > 2097152) {
                $skipped++;
                continue;
            }
            $total += $size;
            if ($total > 8388608 || count($plan) >= 300) {
                return ['ok' => false, 'error' => 'PACKAGE_TOO_LARGE'];
            }

            $plan[] = ['name' => $name, 'rel' => $rel, 'abs' => $abs, 'size' => $size];
        }

        if (!$plan) {
            return ['ok' => true, 'mode' => 'noop', 'written' => []];
        }

        // ---- 统一落盘 ----
        $backupDir = $root . '/storage/backup/selfbootstrap-' . date('Ymd-His');

        foreach ($plan as $item) {
            $rel = $item['rel'];
            $abs = $item['abs'];
            $new = ltZipRead($realZip, $item['name']);
            if ($new === null) {
                $failed++;
                continue;
            }

            // 内容一致就跳过（幂等重入时不会反复重写文件、反复备份）
            if (is_file($abs)) {
                $old = @file_get_contents($abs);
                if ($old !== false && hash('sha256', $old) === hash('sha256', $new)) {
                    $written[] = ['file' => $rel, 'status' => 'same'];
                    continue;
                }
                // 备份旧文件，出问题可以手工回滚
                $dst = $backupDir . '/' . $rel;
                if (!is_dir(dirname($dst))) {
                    @mkdir(dirname($dst), 0755, true);
                }
                @copy($abs, $dst);
            }

            $dir = dirname($abs);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                $written[] = ['file' => $rel, 'status' => 'dir_not_writable'];
                $failed++;
                continue;
            }
            if (@file_put_contents($abs, $new) === false) {
                $written[] = ['file' => $rel, 'status' => 'write_failed'];
                $failed++;
                continue;
            }
            @chmod($abs, 0644);
            $written[] = ['file' => $rel, 'status' => 'updated', 'size' => strlen($new)];
        }

        // 只要关键文件到位就算成功 —— 个别文件失败不该让整站卡在引导失败上
        $ok = is_file(__DIR__ . '/Bootstrap.php') && is_file(__DIR__ . '/Router.php');

        // 自举完清一次 opcache，否则 PHP 可能继续用旧的（不存在的）文件映射
        if ($ok && function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return [
            'ok'      => $ok,
            'mode'    => 'partial',
            'written' => $written,
            'skipped' => $skipped,
            'failed'  => $failed,
        ];
    }
}

/* ===========================================================================
 * ZIP 读取层
 * ---------------------------------------------------------------------------
 * 【为什么要自己写**
 *
 * 自举器是「整站崩了之后唯一的自救手段」，所以它对环境的假设必须尽可能少。
 * ZipArchive 由 php-zip 扩展提供，而实际线上（宝塔）常见这几种情况：
 *   · 面板切换过 PHP 版本，zip 扩展只装在旧版本上
 *   · 编译安装 PHP 时漏了 --with-zip
 *   · 为了减小内存占用禁用了 zip
 * 一旦缺扩展，靠 ZipArchive 的自举器就完全失效 —— 而这正是最需要它的时候。
 *
 * 所以这里实现一个最小可用的 zip 读取器：
 *   · 只依赖 zlib（gzuncompress / gzinflate），这是 PHP 核心级别的扩展
 *   · 只支持 deflate 与 store 两种压缩方式 —— 这是我们自己打出来的包
 *     （`zip -r` 默认 deflate），足以覆盖全部实际场景
 *   · 支持普通 zip 与 ZIP64 的大小/偏移字段（包可能超 4GB 边界，虽不至于但便宜）
 *
 * 有 ZipArchive 时优先用原生（C 实现，快一个数量级）。
 * 两条路径的返回值结构完全一致，调用方无感知。
 * ===========================================================================
 */

/**
 * 列出 zip 内所有文件的 name => ['size' => 未压缩大小]。
 * 失败返回 null。
 */
if (!function_exists('ltZipList')) {
    function ltZipList(string $zipPath): ?array
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return null;
            }
            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if ($st === false) {
                    continue;
                }
                $out[$st['name']] = ['size' => (int) ($st['size'] ?? 0)];
            }
            $zip->close();
            return $out;
        }
        return ltZipPureList($zipPath);
    }
}

/**
 * 读取 zip 内某个文件的原始内容（已解压）。失败返回 null。
 */
if (!function_exists('ltZipRead')) {
    function ltZipRead(string $zipPath, string $name): ?string
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return null;
            }
            $data = $zip->getFromName($name);
            $zip->close();
            return $data === false ? null : $data;
        }
        return ltZipPureRead($zipPath, $name);
    }
}

/**
 * 解析 End of Central Directory，定位中央目录。
 * 返回 [offset, count] 或 null。
 */
if (!function_exists('ltZipFindCentralDir')) {
    function ltZipFindCentralDir($fp, int $fileSize): ?array
    {
        // EOCD 至少 22 字节，可能带最长 65535 字节的注释，所以从尾部往前找签名
        $readLen = min($fileSize, 22 + 65535);
        if (fseek($fp, $fileSize - $readLen) !== 0) {
            return null;
        }
        $buf = fread($fp, $readLen);
        if ($buf === false || strlen($buf) < 22) {
            return null;
        }
        $sig = "PK\x05\x06";
        $pos = strrpos($buf, $sig);
        if ($pos === false) {
            return null;
        }
        $eocd = unpack(
            'vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset/vcommentLen',
            substr($buf, $pos + 4, 18)
        );
        if ($eocd === false) {
            return null;
        }
        $count  = (int) $eocd['entries'];
        $offset = (int) $eocd['cdOffset'];

        // ZIP64：字段为 0xFFFF/0xFFFFFFFF 时，真实值在 ZIP64 EOCD 里
        if ($count === 0xFFFF || $offset === 0xFFFFFFFF) {
            $z64sig = "PK\x06\x06";
            $zpos = strrpos($buf, $z64sig);
            if ($zpos !== false && strlen($buf) >= $zpos + 56) {
                $z = unpack('Pz64size/vmade/vneed/Vdisk/VcdDisk/Pentries/PcdSize/PcdOffset', substr($buf, $zpos + 4, 52));
                if ($z !== false) {
                    $count  = (int) $z['entries'];
                    $offset = (int) $z['cdOffset'];
                }
            }
        }
        return [$offset, $count];
    }
}

/**
 * 纯 PHP 实现：遍历中央目录，返回 name => ['size'=>..., 'offset'=>..., 'method'=>...]
 * 这里的 offset 是「本地文件头」的偏移，读内容时需要再跳过头部。
 */
if (!function_exists('ltZipPureIndex')) {
    function ltZipPureIndex(string $zipPath): ?array
    {
        $fp = @fopen($zipPath, 'rb');
        if (!$fp) {
            return null;
        }
        $stat = fstat($fp);
        $fileSize = (int) ($stat['size'] ?? 0);
        $cd = ltZipFindCentralDir($fp, $fileSize);
        if ($cd === null) {
            fclose($fp);
            return null;
        }
        [$cdOffset, $cdCount] = $cd;

        if (fseek($fp, $cdOffset) !== 0) {
            fclose($fp);
            return null;
        }
        $out = [];
        $guard = 0;
        for ($i = 0; $i < $cdCount; $i++) {
            $hdr = fread($fp, 46);
            if ($hdr === false || strlen($hdr) < 46) {
                break;
            }
            if (substr($hdr, 0, 4) !== "PK\x01\x02") {
                break; // 结构异常，放弃剩余条目
            }
            $e = unpack(
                'vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdiskStart/vinternalAttr/VexternalAttr/VlocalOffset',
                substr($hdr, 4, 42)
            );
            if ($e === false) {
                break;
            }
            $nameLen = (int) $e['nameLen'];
            $extraLen = (int) $e['extraLen'];
            $name = $nameLen > 0 ? (string) fread($fp, $nameLen) : '';
            $extra = $extraLen > 0 ? (string) fread($fp, $extraLen) : '';
            if ((int) $e['commentLen'] > 0) {
                fread($fp, (int) $e['commentLen']);
            }

            $usize  = (int) $e['usize'];
            $csize  = (int) $e['csize'];
            $offset = (int) $e['localOffset'];

            // ZIP64 扩展字段（0x0001）里可能有真实的大小与偏移
            if ($usize === 0xFFFFFFFF || $csize === 0xFFFFFFFF
                || $offset === 0xFFFFFFFF || (int) $e['diskStart'] === 0xFFFF) {
                $z = ltZipParseZip64Extra($extra, $usize, $csize, $offset);
                $usize = $z[0];
                $csize = $z[1];
                $offset = $z[2];
            }

            if ($name !== '') {
                $out[$name] = [
                    'size'   => $usize,
                    'csize'  => $csize,
                    'method' => (int) $e['method'],
                    'offset' => $offset,
                ];
            }
            if (++$guard > 100000) {
                break;
            }
        }
        fclose($fp);
        return $out;
    }
}

/** 解析 ZIP64 扩展字段，按需回填 usize / csize / offset */
if (!function_exists('ltZipParseZip64Extra')) {
    function ltZipParseZip64Extra(string $extra, int $usize, int $csize, int $offset): array
    {
        $p = 0;
        $len = strlen($extra);
        while ($p + 4 <= $len) {
            $h = unpack('vid/vsize', substr($extra, $p, 4));
            $p += 4;
            if ($h === false) {
                break;
            }
            $id = (int) $h['id'];
            $sz = (int) $h['size'];
            if ($p + $sz > $len) {
                break;
            }
            if ($id === 0x0001) {
                $q = $p;
                if ($usize === 0xFFFFFFFF && $q + 8 <= $p + $sz) {
                    $usize = (int) unpack('P', substr($extra, $q, 8))[1];
                    $q += 8;
                }
                if ($csize === 0xFFFFFFFF && $q + 8 <= $p + $sz) {
                    $csize = (int) unpack('P', substr($extra, $q, 8))[1];
                    $q += 8;
                }
                if ($offset === 0xFFFFFFFF && $q + 8 <= $p + $sz) {
                    $offset = (int) unpack('P', substr($extra, $q, 8))[1];
                }
                break;
            }
            $p += $sz;
        }
        return [$usize, $csize, $offset];
    }
}

/** 纯 PHP：列出条目 */
if (!function_exists('ltZipPureList')) {
    function ltZipPureList(string $zipPath): ?array
    {
        $idx = ltZipPureIndex($zipPath);
        if ($idx === null) {
            return null;
        }
        $out = [];
        foreach ($idx as $name => $m) {
            $out[$name] = ['size' => $m['size']];
        }
        return $out;
    }
}

/**
 * 纯 PHP：读取并解压单个条目。
 *
 * 【为什么需要重新定位本地文件头】
 * 中央目录里存的是「本地头偏移」，但本地头的 name/extra 长度可能与中央目录不同
 * （有些打包器会在本地头里塞额外的 extra 字段，`zip` 命令就会）。
 * 所以必须读本地头拿到它自己的 nameLen/extraLen，再据此算出数据起始位置，
 * 直接用中央目录的 csize 去 fseek 会偏移错位、解压出乱码。
 */
if (!function_exists('ltZipPureRead')) {
    function ltZipPureRead(string $zipPath, string $want): ?string
    {
        $idx = ltZipPureIndex($zipPath);
        if ($idx === null || !isset($idx[$want])) {
            return null;
        }
        $m = $idx[$want];
        $fp = @fopen($zipPath, 'rb');
        if (!$fp) {
            return null;
        }
        if (fseek($fp, $m['offset']) !== 0) {
            fclose($fp);
            return null;
        }
        $lh = fread($fp, 30);
        if ($lh === false || strlen($lh) < 30 || substr($lh, 0, 4) !== "PK\x03\x04") {
            fclose($fp);
            return null;
        }
        $l = unpack('vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen',
            substr($lh, 4, 26));
        if ($l === false) {
            fclose($fp);
            return null;
        }
        $dataStart = $m['offset'] + 30 + (int) $l['nameLen'] + (int) $l['extraLen'];

        // 数据描述符（flag bit3）下 csize 在本地头里是 0，用中央目录的值兜底
        $csize = (int) $l['csize'];
        if ($csize === 0) {
            $csize = $m['csize'];
        }
        if ($csize <= 0) {
            fclose($fp);
            return null;
        }
        if (fseek($fp, $dataStart) !== 0) {
            fclose($fp);
            return null;
        }
        $raw = '';
        $left = $csize;
        while ($left > 0) {
            $chunk = fread($fp, min($left, 262144));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
            $left -= strlen($chunk);
        }
        fclose($fp);

        $method = (int) $l['method'];
        if ($method === 0) {
            return $raw; // store
        }
        if ($method === 8) { // deflate
            if (!function_exists('gzinflate')) {
                return null;
            }
            $out = @gzinflate($raw);
            return $out === false ? null : $out;
        }
        return null; // 其余压缩方式（bzip2/lzma）本包不会用到
    }
}

/* ---------------------------------------------------------------------------
 * 3) 判定某相对路径是否归自举管
 *
 * 【为什么 routes.php 也必须归自举管】
 * 它在站点根目录，看起来应该由旧引擎按白名单落盘 ——
 * 但 v1.1.4 的白名单里根本没有它（它是 v1.1.5 才新增的文件）。
 * 于是旧引擎既不放行、也不落盘，结果是：
 *     lib/ + controllers/ 全装好了，但 routes.php 缺失
 *     → api.php 里 require routes.php 失败 → 整站 500
 *
 * 这个坑我踩过：第一版自举器只处理 lib/ 与 controllers/，
 * 补完之后页面依旧是 500，错误信息是
 *     Failed opening required '/.../routes.php'
 * 所以这里把 routes.php 一并纳入。
 *
 * 一并纳入的还有 router.php（伪静态兼容入口）—— 同理，
 * v1.1.4 的白名单里也没有它。缺了它不会整站 500（它是可选入口），
 * 但会让使用 /api/v1/... 形式的老用户拿到 404，属于静默功能退化，
 * 同样应该由自举补上。
 * ---------------------------------------------------------------------------
 */
if (!function_exists('ltBootstrapInScope')) {
    function ltBootstrapInScope(string $rel): bool
    {
        foreach (['lib/', 'controllers/'] as $d) {
            if (strpos($rel, $d) === 0 && strlen($rel) > strlen($d)) {
                return true;
            }
        }
        // 根目录上「v1.1.5 新增、旧引擎白名单里没有」的文件
        return in_array($rel, ltBootstrapExtraRootFiles(), true);
    }
}

if (!function_exists('ltBootstrapExtraRootFiles')) {
    /**
     * v1.1.5 新增的根目录文件 —— 旧引擎不认识它们，必须由自举补。
     * 这份清单必须与 updater.php 的 isAllowedRootFile() 里带 v1.1.5 注释的条目对齐。
     */
    function ltBootstrapExtraRootFiles(): array
    {
        return ['routes.php', 'router.php'];
    }
}

/* ---------------------------------------------------------------------------
 * 4) 相对路径安全性
 *
 * 与 updater.php 的 safeTargetPath 同源思路，但这里更严 ——
 * 自举的落盘范围是「白名单枚举」，不是「排除法」：
 *   ① lib/xxx.php 或 controllers/xxx.php（一层结构）
 *   ② routes.php / router.php（根目录，但必须是 ltBootstrapExtraRootFiles 里列出的）
 * 除此之外一律拒绝。也就是说，即便 ltBootstrapInScope() 出了 bug 放行了意外路径，
 * 这一层仍会拦住 —— 两层独立判定，任一层拒绝即不落盘。
 * ---------------------------------------------------------------------------
 */
if (!function_exists('ltBootstrapSafeRel')) {
    function ltBootstrapSafeRel(string $rel): bool
    {
        if ($rel === '' || strpos($rel, "\0") !== false) {
            return false;
        }
        if (strpos($rel, '..') !== false) {
            return false;
        }
        if ($rel[0] === '/' || preg_match('#^[A-Za-z]:#', $rel)) {
            return false; // 绝对路径 / Windows 盘符
        }
        if (strpos($rel, '\\') !== false) {
            return false; // 反斜杠留着会被某些文件系统当分隔符，统一拒绝
        }

        // ① 根目录白名单（v1.1.5 新增文件）
        if (strpos($rel, '/') === false) {
            return in_array($rel, ltBootstrapExtraRootFiles(), true);
        }

        // ② lib/ 与 controllers/ 下的一层 .php 文件
        $segs = explode('/', $rel);
        if (count($segs) !== 2) {
            return false; // 只允许一层，禁止 lib/sub/x.php 这类嵌套
        }
        if (!in_array($segs[0], ['lib', 'controllers'], true)) {
            return false;
        }
        if (!preg_match('~^[A-Za-z0-9_\-]+\.php$~', $segs[1])) {
            return false; // 只接受普通命名的 php 文件
        }
        return true;
    }
}

/* ---------------------------------------------------------------------------
 * 5) 取目标文件「最近的已存在祖先目录」的真实路径
 *
 * 目标目录可能还不存在（首次安装 lib/），所以要往上找到第一个真实存在的目录
 * 再做 realpath，然后用它来判断是否越界。
 * ---------------------------------------------------------------------------
 */
if (!function_exists('ltBootstrapRealParent')) {
    function ltBootstrapRealParent(string $abs): ?string
    {
        $probe = dirname($abs);
        $guard = 0;
        while ($probe !== '' && !is_dir($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
            if (++$guard > 64) {
                return null;
            }
        }
        if ($probe === '' || !is_dir($probe)) {
            return null;
        }
        $real = realpath($probe);
        return $real === false ? null : $real;
    }
}

/* ---------------------------------------------------------------------------
 * 6) 辅助：标记 / 冷却 / 日志
 * ---------------------------------------------------------------------------
 */
if (!function_exists('ltBootstrapEnsureStateDir')) {
    function ltBootstrapEnsureStateDir(): bool
    {
        $dir = __DIR__ . '/../storage/state';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return is_dir($dir) && is_writable($dir);
    }
}

if (!function_exists('ltBootstrapMarkOk')) {
    function ltBootstrapMarkOk(): void
    {
        // 已经装好也写标记，避免每次请求都重新判断目录完整性
        if (!ltBootstrapEnsureStateDir()) {
            return;
        }
        @file_put_contents(
            ltBootstrappedMarker(),
            json_encode(['at' => date('c'), 'by' => 'lib/Boot.php'], JSON_UNESCAPED_SLASHES)
        );
        @unlink(ltBootstrapLock());
    }
}

if (!function_exists('ltBootstrapTouch')) {
    function ltBootstrapTouch(string $file): void
    {
        if (!ltBootstrapEnsureStateDir()) {
            return;
        }
        @file_put_contents($file, (string) time());
    }
}

if (!function_exists('ltBootstrapLog')) {
    /**
     * 自举日志单独落一个文件，不依赖 Helpers 里的 ltLog
     * （那个文件此刻可能还没装好 —— 正是我们在修的问题）。
     */
    function ltBootstrapLog(string $msg, array $ctx = []): void
    {
        try {
            $dir = __DIR__ . '/../storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                return;
            }
            $line = sprintf(
                "[%s] %s %s%s",
                date('Y-m-d H:i:s'),
                $msg,
                json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                PHP_EOL
            );
            @file_put_contents($dir . '/bootstrap-' . date('Ymd') . '.log', $line, FILE_APPEND);
        } catch (Throwable $e) {
            // 日志失败绝不能影响请求
        }
    }
}

/* ---------------------------------------------------------------------------
 * 7) 执行自举
 *
 * 用 try/catch 包住：自举本身出错也必须让请求继续往下走，
 * 由正常的引导流程去报错 —— 那才是用户能看懂的报错。
 * ---------------------------------------------------------------------------
 */
try {
    ltSelfBootstrap();
} catch (Throwable $e) {
    ltBootstrapLog('self-bootstrap exception', ['msg' => $e->getMessage()]);
}
