#!/usr/bin/env bash
# ===========================================================================
# 蓝天内网穿透 · 立即应急脚本（无需等待新版本发布）
#
# 用途：当面板出现「所有接口 UNAUTHORIZED / 刚登录就被踢出」时，
#       在服务器上直接跑本脚本，把系统拉回可用状态。
#
# 用法（在站点根目录执行）：
#     bash emergency-fix.sh              # 只诊断，不改任何东西
#     bash emergency-fix.sh --fix        # 诊断 + 自动修复
#     bash emergency-fix.sh --reset-pw admin 新密码123   # 重置某个账号密码
#     SITE_URL=https://你的域名 bash emergency-fix.sh    # 附带线上实测
#
# 它做的事：
#   1. 定位站点根目录与 config.php
#   2. 探测数据库连通性
#   3. 检查 sessions 表与会话条数
#   4. 检查 storage/ 权限（这是「登录后立刻失效」最常见的原因）
#   5. 报告 PHP 与 MySQL 的时区差（心跳显示离线的经典病因）
#   6. 【b5 新增】请求头透传自检 —— 「所有接口 UNAUTHORIZED」的真凶所在
#      含 nginx 配置静态检查 + PHP 层面动态实测 + 线上端点探测
#   7. --fix 时：清空会话 + 修 storage 权限 + 清登录失败记录
# ===========================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
FIX=0
RESET_USER=""
RESET_PW=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --fix) FIX=1; shift ;;
    --reset-pw) RESET_USER="${2:-}"; RESET_PW="${3:-}"; shift 3 ;;
    *) shift ;;
  esac
done

echo "=============================================================="
echo " 蓝天内网穿透 · 应急排查"
echo " 站点根目录: $ROOT"
echo "=============================================================="

# ---------- 1. 定位配置 ----------
echo
echo ">> [1/7] 配置文件"
CFG=""
for c in "$ROOT/config.php" "$ROOT/api/config.php" "$ROOT/../config.php"; do
  [[ -f "$c" ]] && CFG="$c" && break
done
if [[ -z "$CFG" ]]; then
  echo "   !! 找不到 config.php，请用 LT_CONFIG=/path/to/config.php 指定"
  exit 1
fi
echo "   找到: $CFG"

# 从 config.php 里抽 db 配置（它是 return [...] 形式，用 PHP 解析最稳）
read -r DB_HOST DB_PORT DB_NAME DB_USER DB_PASS <<<"$(php -r '
$c = @include $argv[1];
$d = is_array($c) ? ($c["db"] ?? []) : [];
printf("%s %s %s %s %s",
  $d["host"] ?? "127.0.0.1",
  $d["port"] ?? "3306",
  $d["name"] ?? "",
  $d["user"] ?? "",
  $d["pass"] ?? "");
' "$CFG" 2>/dev/null)"

if [[ -z "$DB_NAME" ]]; then
  echo "   !! config.php 里读不到 db.name"
  exit 1
fi
echo "   数据库: $DB_NAME @ $DB_HOST:$DB_PORT  用户: $DB_USER"

# 统一的 mysql 调用
# 【为什么需要自动降级连接方式】
# 原实现写死 `-h$DB_HOST -P$DB_PORT`（即 TCP）。但很多服务器
# （尤其是 Debian/Ubuntu 系的 MariaDB、以及宝塔部分版本）root 账号
# 走 unix_socket 认证 —— 走 TCP 会直接 Access denied，
# 而走 socket 却完全正常。
# 若不做降级，用户拿着本脚本自救时会看到「数据库连不上」，
# 从而去排查密码/权限，方向全错，而真实原因只是连接方式。
# 所以这里依次尝试：① config.php 声明的 TCP → ② 本地 socket，
# 哪个通用哪个，并把实际生效的方式打印出来，便于事后核对。
M_TRY_TCP="mysql -h$DB_HOST -P$DB_PORT -u$DB_USER"
[[ -n "$DB_PASS" ]] && M_TRY_TCP="$M_TRY_TCP -p$DB_PASS"
M_TRY_SOCK="mysql -u$DB_USER"
[[ -n "$DB_PASS" ]] && M_TRY_SOCK="$M_TRY_SOCK -p$DB_PASS"

M=""
LINK_MODE=""
if $M_TRY_TCP -e "USE \`$DB_NAME\`; SELECT 1;" >/dev/null 2>&1; then
  M="$M_TRY_TCP"; LINK_MODE="TCP ($DB_HOST:$DB_PORT)"
elif $M_TRY_SOCK -e "USE \`$DB_NAME\`; SELECT 1;" >/dev/null 2>&1; then
  M="$M_TRY_SOCK"; LINK_MODE="本地 socket（TCP 被拒，已自动降级）"
fi

if [[ -z "$M" ]]; then
  echo "   !! 数据库连不上（TCP 与 socket 都失败）。"
  echo "      TCP 报错："
  $M_TRY_TCP -e "SELECT 1;" 2>&1 | head -2 | sed 's/^/        /'
  echo "      socket 报错："
  $M_TRY_SOCK -e "SELECT 1;" 2>&1 | head -2 | sed 's/^/        /'
  echo "      请核对 config.php 的 db.host / db.user / db.pass / db.name"
  exit 1
fi
echo "   ✓ 数据库连接正常（连接方式：$LINK_MODE）"

# ---------- 2. 表完整性 ----------
echo
echo ">> [2/7] 表完整性"
TABLES=$($M -N -e "SHOW TABLES FROM \`$DB_NAME\`;" 2>/dev/null)
MISSING=()
for t in users sessions nodes clients proxies settings api_keys; do
  echo "$TABLES" | grep -qx "$t" || MISSING+=("$t")
done
if [[ ${#MISSING[@]} -gt 0 ]]; then
  echo "   !! 缺失表: ${MISSING[*]}"
  echo "      请导入 sql/init.sql，或访问站点触发 db.php 的 schema 自愈"
else
  echo "   ✓ 关键表齐全"
fi

# ---------- 3. 会话状况 ----------
echo
echo ">> [3/7] 会话状况"
SCNT=$($M -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.sessions;" 2>/dev/null || echo "?")
UCNT=$($M -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.users;" 2>/dev/null || echo "?")
ACNT=$($M -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.users WHERE role='admin' AND status='active';" 2>/dev/null || echo "?")
echo "   会话数: $SCNT   用户数: $UCNT   可用管理员: $ACNT"

HAS_EXP=$($M -N -e "SHOW COLUMNS FROM \`$DB_NAME\`.sessions LIKE 'expires_at';" 2>/dev/null | wc -l)
if [[ "$HAS_EXP" -gt 0 ]]; then
  echo "   ✓ sessions.expires_at 存在（支持会话超时）"
else
  echo "   ⚠ sessions.expires_at 不存在（会话永不超时，鉴权走兼容分支）"
fi

if [[ "$ACNT" == "0" ]]; then
  echo "   !! 没有任何可用管理员 —— 必须重建管理员才能进后台"
fi

# 列出管理员
echo "   管理员账号:"
$M -N -e "SELECT CONCAT('     - ', username, '  [', status, ']') FROM \`$DB_NAME\`.users WHERE role='admin';" 2>/dev/null

# ---------- 4. storage 权限 ----------
echo
echo ">> [4/7] storage 目录权限（登录后立刻失效的头号原因）"
if [[ -d "$ROOT/storage" ]]; then
  OWNER=$(stat -c '%U:%G' "$ROOT/storage" 2>/dev/null || echo "?")
  WRIT=$( [[ -w "$ROOT/storage" ]] && echo "可写" || echo "不可写" )
  echo "   storage/  属主=$OWNER  $WRIT"
  for d in state logs packages; do
    [[ -d "$ROOT/storage/$d" ]] && echo "   storage/$d  属主=$(stat -c '%U:%G' "$ROOT/storage/$d" 2>/dev/null || echo '?')"
  done
  # PHP 进程的实际用户
  PHPUSER=$(ps -o user= -C php-fpm 2>/dev/null | head -1 | tr -d ' ')
  [[ -z "$PHPUSER" ]] && PHPUSER=$(ps -o user= -C php-fpm8.0 2>/dev/null | head -1 | tr -d ' ')
  [[ -n "$PHPUSER" ]] && echo "   PHP-FPM 运行用户: $PHPUSER"
  if [[ -n "$PHPUSER" && "$OWNER" != "$PHPUSER:"* ]]; then
    echo "   ⚠ storage 属主($OWNER) 与 PHP 用户($PHPUSER) 不一致 —— 建议 --fix 修正"
  fi
else
  echo "   !! storage/ 不存在"
fi

# ---------- 5. 时区一致性 ----------
echo
echo ">> [5/7] 时区一致性（心跳显示离线的经典病因）"
PHP_T=$(php -r 'echo date("Y-m-d H:i:s");')
PHP_Z=$(php -r 'echo date_default_timezone_get();')
MY_T=$($M -N -e "SELECT NOW();" 2>/dev/null)
echo "   PHP   : $PHP_T  ($PHP_Z)"
echo "   MySQL : $MY_T"
DIFF=$(( $(date -d "$MY_T" +%s 2>/dev/null || echo 0) - $(date -d "$PHP_T" +%s 2>/dev/null || echo 0) ))
if [[ ${DIFF#-} -gt 60 ]]; then
  echo "   ⚠ 相差 $((DIFF/3600)) 小时 —— 会导致心跳/会话判定异常"
  echo "     修复：为 MySQL 设时区，或确认代码已用绝对时间戳（1.1.5-b1+ 已修）"
else
  echo "   ✓ 一致"
fi

# ---------- 6. 请求头透传自检（本轮 UNAUTHORIZED 的真凶所在）----------
echo
echo ">> [6/7] 请求头透传自检（「所有接口 UNAUTHORIZED」的真凶）"
# 【为什么必须独立检查这一项】
# 前面 5 段全绿时，「所有接口 401」几乎一定是凭据在
# 浏览器 → nginx → PHP-FPM 路上被丢弃，而不是数据库或权限问题。
# 这个丢失发生在配置层，数据库侧完全看不出来 —— 所以必须单独探测。
#
# 两个已知丢失点：
#   ① PHP-FPM 不填充 HTTP_AUTHORIZATION（值可能跑到 REDIRECT_HTTP_AUTHORIZATION）
#   ② nginx underscores_in_headers off（默认值）静默丢弃含下划线的头
#      → 前端发的 X-API-Key 根本到不了 PHP

# 6a. 静态检查 nginx 配置
NGX_CONF=""
for c in /www/server/panel/vhost/nginx/*.conf /etc/nginx/conf.d/*.conf /etc/nginx/sites-enabled/*; do
  [[ -f "$c" ]] || continue
  if grep -ql "fastcgi_pass\|fastcgi_param" "$c" 2>/dev/null; then NGX_CONF="$c"; break; fi
done

if [[ -n "$NGX_CONF" ]]; then
  echo "   站点 nginx 配置: $NGX_CONF"
  UH=$(grep -rn "underscores_in_headers" /www/server/nginx/conf/nginx.conf /etc/nginx/nginx.conf "$NGX_CONF" 2>/dev/null | head -3)
  if [[ -z "$UH" ]]; then
    echo "   ⚠ underscores_in_headers 未显式设置（nginx 默认 off）"
    echo "     → 名称含下划线的请求头会被静默丢弃（如 X-API-Key）"
    echo "     建议：http/https 块内加  underscores_in_headers on;  然后 nginx -s reload"
  else
    echo "   underscores_in_headers 配置："
    echo "$UH" | sed 's/^/     /'
  fi
  AUTHFWD=$(grep -n "HTTP_AUTHORIZATION\|fastcgi_pass_request_headers" "$NGX_CONF" 2>/dev/null | head -3)
  if [[ -z "$AUTHFWD" ]]; then
    echo "   ⚠ 配置里没看到显式透传 Authorization 头的指令"
    echo "     （1.1.5-b5+ 的后端已做六层兜底，通常无需再改配置）"
  else
    echo "   Authorization 透传指令："; echo "$AUTHFWD" | sed 's/^/     /'
  fi
else
  echo "   · 未探测到 nginx 站点配置（可能非 nginx，或路径非标准）"
fi

# 6b. 动态实测：用 PHP 内置服务器直接观察头是否可达
# 这是关键 —— 配置看漏了不要紧，实测能直接给出「头到没到 PHP」的结论。
PROBE_DIR=$(mktemp -d)
cat > "$PROBE_DIR/probe.php" <<'PROBE_EOF'
<?php
$seen = [];
foreach ($_SERVER as $k => $v) {
    if (stripos($k, 'auth') !== false || stripos($k, 'x_api') !== false || stripos($k, 'api_key') !== false) {
        $seen[$k] = is_string($v) ? substr($v, 0, 12) . '…' : gettype($v);
    }
}
header('Content-Type: application/json');
echo json_encode(['headers_seen' => $seen, 'count' => count($seen)], JSON_PRETTY_PRINT);
PROBE_EOF
php -S 127.0.0.1:19977 -t "$PROBE_DIR" >/dev/null 2>&1 &
PROBE_PID=$!
sleep 1
RESP=$(curl -s -m 5 -H "Authorization: Bearer PROBE_TOKEN_123456" -H "X-Api-Key: PROBE_KEY_123456" http://127.0.0.1:19977/probe.php 2>/dev/null)
kill "$PROBE_PID" 2>/dev/null; wait "$PROBE_PID" 2>/dev/null
rm -rf "$PROBE_DIR"

if [[ -n "$RESP" ]]; then
  echo "   本地 PHP 直连实测（可排除 nginx 干扰）："
  echo "$RESP" | sed 's/^/     /'
  if echo "$RESP" | grep -q "HTTP_AUTHORIZATION"; then
    echo "   ✓ PHP 层面能收到 Authorization 头"
  else
    echo "   ⚠ PHP 层面就收不到 Authorization —— 问题在 Web 服务器层"
  fi
else
  echo "   · 本地探测未返回（php -S 不可用，跳过动态实测）"
fi

# 6c. 站内自检端点
echo
echo "   线上头透传实测（需要能访问站点）："
if [[ -n "${SITE_URL:-}" ]]; then
  HCODE=$(curl -s -o /dev/null -w '%{http_code}' -m 8 "$SITE_URL/__health" 2>/dev/null)
  echo "   $SITE_URL/__health → HTTP $HCODE"
  [[ "$HCODE" == "200" ]] && echo "   ✓ 站点可达，可在浏览器打开该地址看完整自检报告" \
                          || echo "   ⚠ 站点自检端点不可达"
else
  echo "   · 设置环境变量后重跑可获得线上实测："
  echo "       SITE_URL=https://你的域名 bash emergency-fix.sh"
fi

# ---------- 7. 修复 ----------
echo
echo ">> [7/7] 修复动作"
if [[ "$FIX" == "0" && -z "$RESET_USER" ]]; then
  echo "   （仅诊断模式，未改动任何数据）"
  echo "   如需修复，重跑并加 --fix"
else
  if [[ -n "$RESET_USER" ]]; then
    if [[ ${#RESET_PW} -lt 8 ]]; then
      echo "   !! 密码至少 8 位"
    else
      # 【必须先确认用户存在】
      # UPDATE 影响 0 行时 MySQL 也返回成功，于是脚本会报「已重置」而实际
      # 什么都没改 —— 这种假成功在应急场景下最害人：你以为修好了，一登录还是进不去。
      EXISTS=$($M -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.users WHERE username='$RESET_USER';" 2>/dev/null)
      if [[ "${EXISTS:-0}" == "0" ]]; then
        echo "   !! 用户「$RESET_USER」不存在于当前库（$DB_NAME）"
        echo "      当前库里的用户："
        $M -N -e "SELECT CONCAT('        - ', username, ' [', role, '/', status, ']') FROM \`$DB_NAME\`.users LIMIT 20;" 2>/dev/null
      else
        HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$RESET_PW")
        ROWS=$($M -N -e "UPDATE \`$DB_NAME\`.users SET password='$HASH', status='active' WHERE username='$RESET_USER'; SELECT ROW_COUNT();" 2>/dev/null | tail -1)
        if [[ "${ROWS:-0}" -ge 1 ]]; then
          echo "   ✓ 已重置「$RESET_USER」的密码并激活账号（影响 $ROWS 行）"
          $M -e "DELETE FROM \`$DB_NAME\`.sessions WHERE username='$RESET_USER';" 2>/dev/null \
            && echo "   ✓ 已清空该用户的会话"
        else
          echo "   !! 重置未生效（影响 0 行）—— 请检查库名与权限"
        fi
      fi
    fi
  fi

  if [[ "$FIX" == "1" ]]; then
    # 清会话
    $M -e "DELETE FROM \`$DB_NAME\`.sessions;" 2>/dev/null && echo "   ✓ 已清空全部会话（所有人需重新登录）"
    # 清登录失败记录 / 解锁
    $M -e "DELETE FROM \`$DB_NAME\`.login_attempts WHERE success=0;" 2>/dev/null \
      && echo "   ✓ 已清除登录失败记录（账号解锁）" \
      || echo "   · login_attempts 表不存在，跳过"
    # 修 storage 权限
    if [[ -n "${PHPUSER:-}" ]]; then
      chown -R "$PHPUSER:$PHPUSER" "$ROOT/storage" 2>/dev/null \
        && echo "   ✓ 已把 storage/ 属主改为 $PHPUSER" \
        || echo "   ⚠ 修改 storage 属主失败（可能需要 root）"
      chmod -R 755 "$ROOT/storage" 2>/dev/null
    fi
    echo "   ✓ 修复完成"
  fi
fi

echo
echo "=============================================================="
echo " 完成。若仍进不去，请检查："
echo "   1) 浏览器无痕窗口再登录（排除前端缓存）"
echo "   2) 站点根目录执行 bash diag-heartbeat.sh"
echo "   3) 访问 http://你的域名/__health 看部署自检"
echo "=============================================================="
