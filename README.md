# 蓝天内网穿透 · 更新分发仓库

本仓库**只存放构建产物**，用于「蓝天内网穿透」管理面板的**在线更新**功能。
不含源码，不含任何配置或凭据。

---

## 一、这个仓库是干什么的

「蓝天内网穿透」后台有一个「在线更新」页。它的工作原理是：

1. 服务器读取本仓库的 `manifest.json`（更新清单）
2. 逐文件比对 sha256，**只下载变化的文件**
3. 每个文件下载后**逐字节校验**，不符即中止
4. 校验通过才落盘，覆盖前自动备份

所以本仓库需要保持以下结构：

```
manifest.json                 ← 更新清单（入口）
index.html                    ← 前端入口
build-meta.json               ← 构建版本戳
favicon.svg / icons.svg       ← 图标
index.php / api.php           ← 后端入口与接口
db.php / updater.php / health.php
assets/                       ← 前端资源（内容哈希命名）
lantian-x.y.z.zip             ← 完整包（备用：本地包更新）
```

> `config.php` **绝不在此仓库**。它含数据库口令，由部署者自己维护，
> 更新引擎也已将它列入受保护名单，永不被覆盖。

---

## 二、部署者如何接入

在你的服务器 `config.php` 里填一行：

```php
'update' => [
    'manifest_url' => 'https://cdn.jsdelivr.net/gh/<用户名>/<仓库名>@v1.0.3/manifest.json',
],
```

然后在后台「资源 → 在线更新」页点「检查更新」即可。

> 建议用 **Tag**（`@v1.0.3`）而不是分支名。jsDelivr 对 tag 是永久缓存，
> 分支则是 12 小时。用 tag 能保证每个版本永久可复现。

---

## 三、发布新版本的流程

在开发机执行：

```bash
# 1. 生成发布产物
./release-flat.sh 1.0.4 "本次改动说明"

# 2. 同步到本仓库
cp dist-release/manifest.json .
cp -r dist-release/files/* .
cp dist-release/lantian-1.0.4.zip .
rm -f config.php

# 3. 提交并打 tag
git add -A
git commit -m "release v1.0.4"
git tag v1.0.4
git push origin main --tags
```

推送后 jsDelivr 会自动缓存，几分钟内即可访问：

```
https://cdn.jsdelivr.net/gh/<用户名>/<仓库名>@v1.0.4/manifest.json
```

**验证是否生效**：

```bash
curl -s https://cdn.jsdelivr.net/gh/<用户名>/<仓库名>@v1.0.4/manifest.json | head
```

---

## 四、`manifest.json` 结构

```json
{
  "app": "lantian-tunnel",
  "version": "1.0.3",
  "publishedAt": "2026-09-25T22:24:27Z",
  "notes": "版本说明",
  "baseUrl": "",
  "fileCount": 15,
  "totalBytes": 1817263,
  "files": {
    "assets/core-xxxx.js": { "sha256": "...", "size": 94743 },
    "api.php":             { "sha256": "...", "size": 35378 }
  }
}
```

- `files` 里每个条目的 `sha256` 用于下载后校验
- 服务器端会跳过 sha256 已一致的文件，实现**增量更新**
- `baseUrl` 为空时，按 `manifest_url` 所在目录拼接文件地址

---

## 五、许可

本项目基于 **NexusLink** 的数据面协议构建，遵循 **GPL-3.0** 许可。

NexusLink 项目地址：<https://github.com/YSD-build/NexusLink>

本仓库仅分发构建产物，同样适用 GPL-3.0。
