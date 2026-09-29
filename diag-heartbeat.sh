#!/usr/bin/env bash
# ===========================================================================
# 蓝天内透 · 节点心跳诊断脚本
#
# 用法（在服务器上执行）：
#     bash diag-heartbeat.sh
#     bash diag-heartbeat.sh nt_你的节点token      # 顺便实测心跳
#
# 它会依次检查：路由是否到位 → 鉴权凭据 → 时区一致性 → 库里的状态 →
# 界面为什么判定离线。最后给出结论。
# ===========================================================================
set -uo pipefail

ROOT="${LT_ROOT:-$(cd "$(dirname "$0")" && pwd)}"
BASE="${LT_BASE:-http://127.0.0.1}"
NTOKEN="${1:-}"

DB_NAME="${DB_NAME:-nexuslink}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

echo "=============================================================="
echo " 节点心跳诊断 · $ROOT"
echo "=============================================================="

MYSQL="mariadb"
if ! command -v mariadb >/dev/null 2>&1; then MYSQL="mysql"; fi
DBQ() { if [ -n "$DB_PASS" ]; then "$MYSQL" -u"$DB_USER" -p"$DB_PASS" -N -e "$1" 2>/dev/null; else "$MYSQL" -u"$DB_USER" -N -e "$1" 2>/dev/null; fi; }

# ---------------------------------------------------------------------------
echo
echo "【1】文件是否到位（缺任何一个都会让心跳 404/500）"
# ---------------------------------------------------------------------------
for f in routes.php router.php lib/Bootstrap.php lib/Router.php \
         controllers/NodesController.php api.php db.php config.php; do
  if [ -e "$ROOT/$f" ]; then
    printf '   \033[32m✓\033[0m %s\n' "$f"
  else
    printf '   \033[31m✗\033[0m %s  \033[31m缺失\033[0m\n' "$f"
  fi
done

# ---------------------------------------------------------------------------
echo
echo "【2】心跳端点是否可达"
# ---------------------------------------------------------------------------
CODE=$(curl -s -o /tmp/.hb_diag -w '%{http_code}' -m 10 \
       -X POST "$BASE/api.php/v1/nodes/heartbeat" \
       -H 'Content-Type: application/json' -d '{}' 2>/dev/null || echo 000)
echo "   HTTP $CODE"
case "$CODE" in
  401) echo "   ✓ 路由正常（返回鉴权错误是预期的，因为没带 token）" ;;
  404) echo "   ✗ 路由没生效 → routes.php 或 lib/ 缺失，或 PHP 没跑到新架构" ;;
  500) echo "   ✗ 服务器错误 → 看 storage/logs/ 与 PHP 错误日志" ;;
  000) echo "   ✗ 连不上 → 检查域名/端口，或 nginx 配置" ;;
  *)   echo "   ? 非预期状态码" ;;
esac
head -c 300 /tmp/.hb_diag; echo

# ---------------------------------------------------------------------------
echo
echo "【3】时区一致性（心跳显示离线的头号元凶）"
# ---------------------------------------------------------------------------
PHP_TZ=$(php -r 'echo date("Y-m-d H:i:s");' 2>/dev/null)
PHP_ZONE=$(php -r 'echo ini_get("date.timezone") ?: "(空，跟系统)";' 2>/dev/null)
MYSQL_NOW=$(DBQ "SELECT NOW();")
MYSQL_TZ=$(DBQ "SELECT @@global.time_zone;")
echo "   PHP    : $PHP_TZ   [date.timezone=$PHP_ZONE]"
echo "   MySQL  : $MYSQL_NOW   [time_zone=$MYSQL_TZ]"

PHP_H=$(echo "$PHP_TZ" | cut -c1-13)
MY_H=$(echo "$MYSQL_NOW" | cut -c1-13)
if [ -n "$PHP_H" ] && [ -n "$MY_H" ] && [ "$PHP_H" != "$MY_H" ]; then
  echo "   \033[31m✗ 时区不一致！\033[0m这会让心跳显示离线的同时接口却返回 200"
  echo "     影响：last_seen 落在过去 8 小时 → 永远超过超时阈值 → 永久离线"
  echo "     建议：统一两边时区（推荐都设 Asia/Shanghai）"
  echo "       ① my.cnf 里加 default-time-zone = '+08:00' 后重启 MySQL"
  echo "       ② php.ini 里 date.timezone = Asia/Shanghai 后重载 PHP"
  echo "     注：v1.1.5-b1 起心跳已改用绝对时间戳，可不受此影响；"
  echo "         但为免其它功能出问题，仍建议统一。"
else
  echo "   ✓ 时区一致"
fi

# ---------------------------------------------------------------------------
echo
echo "【4】数据库里节点的真实状态"
# ---------------------------------------------------------------------------
HAS_TS=$(DBQ "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema='$DB_NAME' AND table_name='nodes'
              AND column_name='last_seen_ts';")
if [ "${HAS_TS:-0}" -ge 1 ] 2>/dev/null; then
  echo "   ✓ nodes.last_seen_ts 列已就位（心跳判定用绝对时间戳，不受时区影响）"
  DBQ "SELECT CONCAT('   ', name, ' | status=', status,
        ' | last_seen=', IFNULL(last_seen,'NULL'),
        ' | ts=', IFNULL(last_seen_ts,'NULL'),
        ' | cpu=', cpu, ' | tok=', LEFT(node_token,10))
       FROM $DB_NAME.nodes ORDER BY id;"
  echo
  echo "   --- 各节点距今秒数（ts 为准，>nodeOfflineSeconds 即判离线）---"
  DBQ "SELECT CONCAT('   ', name, ':', IFNULL(UNIX_TIMESTAMP() - last_seen_ts, -1), 's')
       FROM $DB_NAME.nodes ORDER BY id;"
else
  echo "   \033[31m✗ 缺 last_seen_ts 列\033[0m"
  echo "     说明数据库还没跑完迁移。处理：删除 storage/schema.version 后"
  echo "     访问一次后台页面，或用浏览器打开 /health.php 触发自愈。"
  DBQ "SELECT CONCAT('   ', name, ' | status=', status, ' | last_seen=', IFNULL(last_seen,'NULL'))
       FROM $DB_NAME.nodes ORDER BY id;"
fi

TIMEOUT=$(DBQ "SELECT IFNULL(value,'90') FROM $DB_NAME.settings WHERE key_name='nodeOfflineSeconds';")
echo "   离线阈值 nodeOfflineSeconds = ${TIMEOUT:-90} 秒"

# ---------------------------------------------------------------------------
echo
echo "【5】实测发一次心跳（需要传入 token）"
# ---------------------------------------------------------------------------
if [ -n "$NTOKEN" ]; then
  R=$(curl -s -m 10 -X POST "$BASE/api.php/v1/nodes/heartbeat" \
       -H "X-Node-Token: $NTOKEN" -H 'Content-Type: application/json' \
       -d '{"cpu":11.1,"mem":22.2,"connCount":3}' 2>/dev/null)
  echo "   响应: $(echo "$R" | head -c 300)"
  case "$R" in
    *'"success":true'*) echo "   ✓ 心跳被接受。若界面仍显示离线，看第 3 节的时区结论。" ;;
    *INVALID_NODE_TOKEN*) echo "   ✗ token 不对。去后台「节点管理」查看正确 token（或轮换）。" ;;
    *NODE_TOKEN_REQUIRED*) echo "   ✗ 没带 token 头。节点端要发 X-Node-Token。" ;;
    *SCHEMA_NOT_READY*) echo "   ✗ 数据库没迁移完。删 storage/schema.version 触发自愈。" ;;
    *) echo "   ? 未知响应，看第 2 节与日志。" ;;
  esac
else
  echo "   （未提供 token，跳过。用法：bash diag-heartbeat.sh nt_xxx）"
fi

# ---------------------------------------------------------------------------
echo
echo "【6】最近的日志"
# ---------------------------------------------------------------------------
for f in "$ROOT"/storage/logs/node-*.log "$ROOT"/storage/logs/bootstrap-*.log; do
  [ -e "$f" ] || continue
  echo "   --- $(basename "$f") 末 5 行 ---"
  tail -5 "$f" | sed 's/^/     /'
done
[ -d "$ROOT/storage/logs" ] || echo "   （没有日志目录）"

echo
echo "=============================================================="
echo " 诊断完毕。把以上完整输出发我，可直接定位。"
echo "=============================================================="
