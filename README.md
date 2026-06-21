# NexusLink 内网穿透平台

基于 PHP + MySQL 的内网穿透管理平台，类似 MeFrp、樱花frp 的多用户平台，底层使用 NexusLink 程序。

## 功能特性

### 用户前台
- ✅ 用户注册、登录
- ✅ 节点列表查看
- ✅ 隧道创建、编辑、删除
- ✅ 自动分配远程端口
- ✅ 客户端配置一键生成
- ✅ 流量统计
- ✅ 修改密码

### 管理后台
- ✅ 仪表盘统计
- ✅ 节点管理（增删改查）
- ✅ 用户管理（增删改查、禁用、重置密码）
- ✅ 隧道管理
- ✅ 流量统计

### 安全特性
- ✅ JWT 身份认证
- ✅ 密码 bcrypt 哈希存储
- ✅ CORS 跨域支持
- ✅ SQL 注入防护（PDO 预处理）
- ✅ 管理员权限验证

## 技术栈

- **后端**：原生 PHP + PDO
- **数据库**：MySQL 5.7+ / MariaDB
- **前端**：Vue 3 + Element Plus（CDN 方式，无需构建）
- **认证**：JWT (HS256)

## 目录结构

```
nexuslink-platform/
├── api/                    # API 接口目录
│   ├── config.php          # 配置文件
│   ├── db.php              # 数据库连接类
│   ├── functions.php       # 公共函数库
│   ├── auth.php            # 认证接口（登录、注册）
│   ├── user.php            # 用户接口
│   ├── node.php            # 节点接口（公开）
│   ├── tunnel.php          # 隧道接口
│   └── admin.php           # 管理员接口
├── frontend/               # 前端页面
│   ├── index.html          # 用户前台
│   └── admin.html          # 管理后台
├── sql/
│   └── install.sql         # 数据库安装脚本
└── README.md               # 说明文档
```

## 快速开始

### 1. 环境要求

- PHP 7.4+ （推荐 PHP 8.0+）
- MySQL 5.7+ 或 MariaDB 10.3+
- Web 服务器（Nginx / Apache）
- PHP 扩展：pdo_mysql、json、mbstring

### 2. 部署步骤

#### 步骤一：导入数据库

创建数据库并导入 `sql/install.sql`：

```sql
CREATE DATABASE nexuslink DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nexuslink;
SOURCE sql/install.sql;
```

或者使用命令行：

```bash
mysql -u root -p nexuslink < sql/install.sql
```

#### 步骤二：修改配置

编辑 `api/config.php`，修改数据库配置：

```php
// 数据库配置
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'nexuslink');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');
define('DB_CHARSET', 'utf8mb4');

// JWT密钥（请修改为随机字符串）
define('JWT_SECRET', 'your-secret-key-change-me');
```

**重要**：请务必修改 `JWT_SECRET` 为随机字符串，以保证安全。

#### 步骤三：配置 Web 服务器

将整个项目放到网站根目录，确保 `api` 和 `frontend` 目录可访问。

**推荐的目录结构**：

```
/var/www/nexuslink/
├── api/           # 放到 /var/www/nexuslink/api/
├── frontend/      # 放到 /var/www/nexuslink/
│   ├── index.html
│   └── admin.html
└── sql/
```

**Nginx 配置示例**：

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/nexuslink/frontend;
    index index.html;

    # API 反向代理
    location /api/ {
        alias /var/www/nexuslink/api/;
        try_files $uri $uri/ =404;
        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/run/php/php8.1-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $request_filename;
        }
    }

    # 前端页面
    location / {
        try_files $uri $uri/ /index.html;
    }

    location /admin {
        try_files $uri $uri/ /admin.html;
    }
}
```

#### 步骤四：访问

- 用户前台：http://your-domain.com/
- 管理后台：http://your-domain.com/admin.html

默认管理员账号：
- 用户名：`admin`
- 密码：`admin123`

**重要**：登录后请立即修改管理员密码！

## API 接口文档

### 认证接口

#### 注册
```
POST /api/auth.php?action=register
Content-Type: application/json

{
    "username": "testuser",
    "password": "123456",
    "email": "test@example.com"
}
```

#### 登录
```
POST /api/auth.php?action=login
Content-Type: application/json

{
    "username": "admin",
    "password": "admin123"
}
```

返回：
```json
{
    "success": true,
    "message": "登录成功",
    "token": "eyJ0eXAiOiJKV1Qi...",
    "user": {
        "id": 1,
        "username": "admin",
        "nickname": "管理员",
        "role": "admin"
    }
}
```

### 用户接口（需要认证）

在请求头中携带 Token：
```
Authorization: Bearer your_token_here
```

#### 获取用户信息
```
GET /api/user.php?action=info
```

#### 修改密码
```
POST /api/user.php?action=password

{
    "old_password": "原密码",
    "new_password": "新密码"
}
```

### 节点接口（公开）

#### 节点列表
```
GET /api/node.php?action=list
```

#### 节点详情
```
GET /api/node.php?action=detail&id=1
```

### 隧道接口（需要认证）

#### 我的隧道列表
```
GET /api/tunnel.php?action=list
```

#### 创建隧道
```
POST /api/tunnel.php?action=create

{
    "node_id": 1,
    "name": "我的Minecraft",
    "type": "tcp",
    "local_addr": "127.0.0.1",
    "local_port": 25565,
    "remote_port": 0
}
```

- `remote_port` 为 0 时自动分配端口

#### 更新隧道
```
POST /api/tunnel.php?action=update

{
    "id": 1,
    "name": "新名称",
    "local_addr": "127.0.0.1",
    "local_port": 25565,
    "status": 1
}
```

#### 删除隧道
```
POST /api/tunnel.php?action=delete

{
    "id": 1
}
```

#### 获取配置
```
GET /api/tunnel.php?action=config&id=1
```

### 管理员接口（需要管理员权限）

#### 统计数据
```
GET /api/admin.php?action=stats
```

#### 节点管理
```
GET    /api/admin.php?action=node_list
POST   /api/admin.php?action=node_create
POST   /api/admin.php?action=node_update
POST   /api/admin.php?action=node_delete
```

#### 用户管理
```
GET    /api/admin.php?action=user_list
POST   /api/admin.php?action=user_update
POST   /api/admin.php?action=user_delete
```

## 添加节点

1. 在你的服务器上安装并启动 NexusLink 服务端
2. 登录管理后台，进入「节点管理」
3. 点击「添加节点」，填写节点信息：
   - 节点名称：如「北京-电信-1号」
   - 节点地址：服务器 IP 或域名
   - 服务端口：NexusLink 服务端端口（默认 7000）
   - Token：服务端配置的 token
   - 机房位置：如「北京电信」
   - 端口范围：用户可使用的端口范围（默认 10000-60000）
4. 保存后，用户即可在前台看到该节点并创建隧道

## 客户端使用

1. 用户在前台创建隧道后，点击「配置」按钮
2. 复制配置内容，保存为 `client.yaml`
3. 下载 NexusLink 客户端
4. 运行客户端：`./nexuslink-client -c client.yaml`

## 安全建议

1. **修改默认管理员密码**：首次登录后立即修改
2. **修改 JWT 密钥**：使用随机字符串
3. **启用 HTTPS**：生产环境请配置 SSL 证书
4. **数据库权限**：使用最小权限的数据库用户
5. **定期备份**：定期备份数据库
6. **防火墙**：只开放必要的端口

## 常见问题

### 1. 登录后提示 "需要管理员权限"

确保用户的 `role` 字段为 `admin`。默认的 admin 用户是管理员。

### 2. 数据库连接失败

检查 `api/config.php` 中的数据库配置是否正确，确保 MySQL 服务正在运行。

### 3. 前端页面空白

检查浏览器控制台是否有报错，确认 API 路径配置正确。

### 4. 隧道创建后连不上

- 检查节点服务器防火墙是否开放了对应端口
- 检查 NexusLink 服务端是否正常运行
- 检查 token 是否一致

## 版本信息

- 平台版本：v0.1.0
- 适配 NexusLink 版本：v0.2.2.beta+

## License

MIT License
