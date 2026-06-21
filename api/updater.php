<?php
/**
 * NexusLink 自动更新类
 * 通过 GitHub 通道实现实质性更新
 */

class Updater {
    
    private $repo;
    private $token;
    private $currentVersion;
    private $versionCode;
    private $backupDir;
    
    public function __construct() {
        $this->repo = GITHUB_REPO;
        $this->token = $this->decryptToken(GITHUB_TOKEN);
        $this->currentVersion = CURRENT_VERSION;
        $this->versionCode = VERSION_CODE;
        $this->backupDir = dirname(__DIR__) . '/backups';
    }
    
    /**
     * 解密加密的 Token
     * AES-256-CBC 解密，密钥使用 JWT_SECRET
     */
    private function decryptToken($encryptedToken) {
        if (empty($encryptedToken)) {
            return '';
        }
        
        // 如果 token 以 ghp_ 开头，说明是明文，直接返回（兼容旧配置）
        if (strpos($encryptedToken, 'ghp_') === 0) {
            return $encryptedToken;
        }
        
        $key = substr(JWT_SECRET, 0, 32); // AES-256 需要 32 字节密钥
        $data = base64_decode($encryptedToken);
        
        if ($data === false || strlen($data) < 16) {
            return '';
        }
        
        $iv = substr($data, 0, 16); // 前 16 字节是 IV
        $encrypted = substr($data, 16); // 后面是加密数据
        
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        
        if ($decrypted === false) {
            return '';
        }
        
        return $decrypted;
    }
    
    /**
     * 检查更新
     * 返回：['has_update' => bool, 'latest_version' => string, 'release_notes' => string, 'download_url' => string]
     */
    public function checkUpdate() {
        if (!UPDATE_ENABLED) {
            return ['has_update' => false, 'message' => '自动更新已禁用'];
        }
        
        if (UPDATE_CHANNEL == 'github') {
            return $this->checkGithubUpdate();
        } else {
            return $this->checkCustomUpdate();
        }
    }
    
    /**
     * 从 GitHub 检查更新
     */
    private function checkGithubUpdate() {
        $apiUrl = "https://api.github.com/repos/{$this->repo}/releases/latest";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'NexusLink-Updater');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // 15秒超时
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 连接超时10秒
        
        if (!empty($this->token)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: token ' . $this->token
            ]);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode != 200) {
            return ['has_update' => false, 'message' => '检查更新失败：' . $httpCode];
        }
        
        $release = json_decode($response, true);
        
        if (!$release) {
            return ['has_update' => false, 'message' => '解析更新信息失败'];
        }
        
        $latestVersion = $release['tag_name'] ?? '';
        $releaseNotes = $release['body'] ?? '';
        $publishedAt = $release['published_at'] ?? '';
        
        // 获取下载地址（zip 包）
        $downloadUrl = '';
        if (!empty($release['assets'])) {
            // 优先找 nexuslink-platform.zip
            foreach ($release['assets'] as $asset) {
                if (strpos($asset['name'], 'platform') !== false && strpos($asset['name'], '.zip') !== false) {
                    $downloadUrl = $asset['browser_download_url'];
                    break;
                }
            }
        }
        
        // 如果没有找到资产，用源码包
        if (empty($downloadUrl)) {
            $downloadUrl = $release['zipball_url'] ?? '';
        }
        
        // 比较版本
        $hasUpdate = $this->compareVersions($latestVersion, $this->currentVersion);
        
        return [
            'has_update' => $hasUpdate,
            'latest_version' => $latestVersion,
            'release_notes' => $releaseNotes,
            'published_at' => $publishedAt,
            'download_url' => $downloadUrl,
            'current_version' => $this->currentVersion
        ];
    }
    
    /**
     * 从自定义源检查更新
     */
    private function checkCustomUpdate() {
        if (empty(UPDATE_SOURCE)) {
            return ['has_update' => false, 'message' => '未配置自定义更新源'];
        }
        
        $apiUrl = rtrim(UPDATE_SOURCE, '/') . '/latest.json';
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode != 200) {
            return ['has_update' => false, 'message' => '检查更新失败'];
        }
        
        $info = json_decode($response, true);
        
        if (!$info) {
            return ['has_update' => false, 'message' => '解析更新信息失败'];
        }
        
        $latestVersion = $info['version'] ?? '';
        $versionCode = $info['version_code'] ?? 0;
        $releaseNotes = $info['release_notes'] ?? '';
        $downloadUrl = $info['download_url'] ?? '';
        
        $hasUpdate = $versionCode > $this->versionCode;
        
        return [
            'has_update' => $hasUpdate,
            'latest_version' => $latestVersion,
            'release_notes' => $releaseNotes,
            'download_url' => $downloadUrl,
            'current_version' => $this->currentVersion
        ];
    }
    
    /**
     * 比较版本号
     */
    private function compareVersions($v1, $v2) {
        // 去掉前缀 v
        $v1 = ltrim($v1, 'v');
        $v2 = ltrim($v2, 'v');
        
        return version_compare($v1, $v2, '>');
    }
    
    /**
     * 执行更新
     */
    public function doUpdate() {
        // 检查更新
        $updateInfo = $this->checkUpdate();
        
        if (!$updateInfo['has_update']) {
            return ['success' => false, 'message' => '没有可用更新'];
        }
        
        if (empty($updateInfo['download_url'])) {
            return ['success' => false, 'message' => '未找到更新包下载地址'];
        }
        
        // 1. 备份当前版本
        $backupResult = $this->backup();
        if (!$backupResult['success']) {
            return ['success' => false, 'message' => '备份失败：' . $backupResult['message']];
        }
        
        // 2. 下载更新包
        $downloadResult = $this->downloadUpdate($updateInfo['download_url']);
        if (!$downloadResult['success']) {
            return ['success' => false, 'message' => '下载失败：' . $downloadResult['message']];
        }
        
        // 3. 解压并替换文件
        $extractResult = $this->extractAndReplace($downloadResult['file']);
        if (!$extractResult['success']) {
            // 失败则回滚
            $this->rollback($backupResult['backup_dir']);
            return ['success' => false, 'message' => '更新失败：' . $extractResult['message']];
        }
        
        // 4. 清理临时文件
        @unlink($downloadResult['file']);
        
        return [
            'success' => true,
            'message' => '更新成功！',
            'new_version' => $updateInfo['latest_version'],
            'backup_dir' => $backupResult['backup_dir']
        ];
    }
    
    /**
     * 备份当前版本
     */
    private function backup() {
        if (!is_dir($this->backupDir)) {
            if (!mkdir($this->backupDir, 0755, true)) {
                return ['success' => false, 'message' => '无法创建备份目录'];
            }
        }
        
        $backupName = 'backup_' . date('YmdHis') . '_' . $this->currentVersion;
        $backupDir = $this->backupDir . '/' . $backupName;
        
        if (!mkdir($backupDir, 0755, true)) {
            return ['success' => false, 'message' => '无法创建备份文件夹'];
        }
        
        // 要备份的文件和目录
        $items = [
            'index.php',
            'admin.php',
            'style.css',
            'api/',
            'sql/'
        ];
        
        $rootDir = dirname(__DIR__);
        
        foreach ($items as $item) {
            $source = $rootDir . '/' . $item;
            $dest = $backupDir . '/' . $item;
            
            if (is_dir($source)) {
                $this->copyDir($source, $dest);
            } elseif (file_exists($source)) {
                $destDir = dirname($dest);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                copy($source, $dest);
            }
        }
        
        // 保存备份信息
        $info = [
            'version' => $this->currentVersion,
            'date' => date('Y-m-d H:i:s'),
            'backup_dir' => $backupDir
        ];
        file_put_contents($backupDir . '/backup_info.json', json_encode($info, JSON_PRETTY_PRINT));
        
        return ['success' => true, 'backup_dir' => $backupDir];
    }
    
    /**
     * 下载更新包
     */
    private function downloadUpdate($url) {
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir . '/nexuslink_update_' . time() . '.zip';
        
        $ch = curl_init($url);
        $fp = fopen($tempFile, 'w');
        
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'NexusLink-Updater');
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        
        if (!empty($this->token)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: token ' . $this->token
            ]);
        }
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        
        if (!$success || $httpCode != 200) {
            @unlink($tempFile);
            return ['success' => false, 'message' => '下载失败，HTTP 状态：' . $httpCode];
        }
        
        if (filesize($tempFile) < 1024) {
            @unlink($tempFile);
            return ['success' => false, 'message' => '下载的文件太小，可能无效'];
        }
        
        return ['success' => true, 'file' => $tempFile];
    }
    
    /**
     * 解压并替换文件
     */
    private function extractAndReplace($zipFile) {
        $zip = new ZipArchive();
        $result = $zip->open($zipFile);
        
        if ($result !== true) {
            return ['success' => false, 'message' => '无法打开压缩包'];
        }
        
        $tempExtractDir = sys_get_temp_dir() . '/nexuslink_extract_' . time();
        if (!mkdir($tempExtractDir)) {
            $zip->close();
            return ['success' => false, 'message' => '无法创建临时目录'];
        }
        
        $zip->extractTo($tempExtractDir);
        $zip->close();
        
        // 查找解压后的平台文件
        // GitHub 源码包解压后会有一层目录（用户名-仓库名-commit）
        $platformDir = $this->findPlatformDir($tempExtractDir);
        
        if (!$platformDir) {
            $this->removeDir($tempExtractDir);
            return ['success' => false, 'message' => '未找到平台文件'];
        }
        
        // 替换文件
        $rootDir = dirname(__DIR__);
        $items = [
            'index.php',
            'admin.php',
            'style.css',
            'api/',
            'sql/'
        ];
        
        // 需要保留的文件（更新时不覆盖）
        $preserveFiles = [
            'api/config.php'
        ];
        
        // 先备份需要保留的文件
        $tempPreserve = [];
        foreach ($preserveFiles as $file) {
            $filePath = $rootDir . '/' . $file;
            if (file_exists($filePath)) {
                $tempFile = sys_get_temp_dir() . '/nexuslink_preserve_' . basename($file) . '_' . time();
                copy($filePath, $tempFile);
                $tempPreserve[$file] = $tempFile;
            }
        }
        
        foreach ($items as $item) {
            $source = $platformDir . '/' . $item;
            $dest = $rootDir . '/' . $item;
            
            if (is_dir($source)) {
                // 目录：先删除旧的，再复制新的
                if (is_dir($dest)) {
                    $this->removeDir($dest);
                }
                $this->copyDir($source, $dest);
            } elseif (file_exists($source)) {
                // 文件：直接替换
                if (file_exists($dest)) {
                    unlink($dest);
                }
                copy($source, $dest);
            }
        }
        
        // 恢复需要保留的文件
        foreach ($tempPreserve as $file => $tempFile) {
            $destPath = $rootDir . '/' . $file;
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            copy($tempFile, $destPath);
            unlink($tempFile);
        }
        
        // 清理临时文件
        $this->removeDir($tempExtractDir);
        
        return ['success' => true];
    }
    
    /**
     * 查找解压后的平台目录
     */
    private function findPlatformDir($dir) {
        // 先检查当前目录有没有 index.php
        if (file_exists($dir . '/index.php') && is_dir($dir . '/api')) {
            return $dir;
        }
        
        // 遍历子目录
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                if (file_exists($path . '/index.php') && is_dir($path . '/api')) {
                    return $path;
                }
                // 递归查找
                $result = $this->findPlatformDir($path);
                if ($result) return $result;
            }
        }
        
        return null;
    }
    
    /**
     * 回滚到备份
     */
    public function rollback($backupDir) {
        if (!is_dir($backupDir)) {
            return ['success' => false, 'message' => '备份目录不存在'];
        }
        
        $rootDir = dirname(__DIR__);
        $items = [
            'index.php',
            'admin.php',
            'style.css',
            'api/',
            'sql/'
        ];
        
        foreach ($items as $item) {
            $source = $backupDir . '/' . $item;
            $dest = $rootDir . '/' . $item;
            
            if (is_dir($source)) {
                if (is_dir($dest)) {
                    $this->removeDir($dest);
                }
                $this->copyDir($source, $dest);
            } elseif (file_exists($source)) {
                if (file_exists($dest)) {
                    unlink($dest);
                }
                copy($source, $dest);
            }
        }
        
        return ['success' => true, 'message' => '回滚成功'];
    }
    
    /**
     * 复制目录
     */
    private function copyDir($src, $dst) {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        
        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file == '.' || $file == '..') continue;
            
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            
            if (is_dir($srcPath)) {
                $this->copyDir($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }
    
    /**
     * 删除目录
     */
    private function removeDir($dir) {
        if (!is_dir($dir)) return;
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        
        rmdir($dir);
    }
    
    /**
     * 获取备份列表
     */
    public function getBackupList() {
        if (!is_dir($this->backupDir)) {
            return [];
        }
        
        $backups = [];
        $dirs = scandir($this->backupDir);
        
        foreach ($dirs as $dir) {
            if ($dir == '.' || $dir == '..') continue;
            
            $backupDir = $this->backupDir . '/' . $dir;
            $infoFile = $backupDir . '/backup_info.json';
            
            if (file_exists($infoFile)) {
                $info = json_decode(file_get_contents($infoFile), true);
                $backups[] = $info;
            }
        }
        
        // 按时间倒序
        usort($backups, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return $backups;
    }
}
