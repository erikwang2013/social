#!/usr/bin/env bash
#
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 一键安装脚本（幂等，可重复执行）：
#   1. 前置检查：php>=8.3 / composer / mysql 客户端 / openssl
#   2. composer install（service/、admin/，--no-dev；vendor 已存在则跳过）
#   3. 创建 social + open_admin 数据库
#   4. 导入 database/install.sql（social 段可重复执行；open_admin 段已安装则跳过，不破坏数据）
#      首次导入或检测到旧默认口令时生成随机密码并启用 erik，成功后写入 admin/runtime/install.lock
#   5. 生成 service/.env、admin/.env 与 media/sfu/.env（已存在不覆盖，密钥 openssl rand 生成）
#   6. 可选启动 media/sfu 容器（--skip-media 跳过）
#
# 用法: ./install.sh [--skip-media]
# 环境变量: DB_HOST DB_PORT DB_USERNAME DB_ROOT_PASSWORD（缺省时交互询问密码）
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# 生成随机密钥/密码（openssl 在下方前置检查中校验）
gen_key() { openssl rand -hex "$1"; }

SKIP_MEDIA=0
for arg in "$@"; do
  case "$arg" in
    --skip-media) SKIP_MEDIA=1 ;;
    *) echo "未知参数: $arg（支持 --skip-media）" >&2; exit 1 ;;
  esac
done

# ─────────────────────────── 前置检查 ───────────────────────────

require() { command -v "$1" >/dev/null 2>&1 || { echo "缺少依赖: $1，请先安装" >&2; exit 1; }; }

require php
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo 0.0)"
if [[ "$PHP_VER" < "8.3" ]]; then
  echo "PHP >= 8.3 必需（当前 $PHP_VER）" >&2
  exit 1
fi
require composer
require mysql
require redis-cli
require openssl
echo "前置检查通过（PHP $PHP_VER / composer / mysql / redis-cli / openssl）"

# ─────────────────────────── 数据库连接 ───────────────────────────

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-root}"
if [[ -z "${DB_ROOT_PASSWORD:-}" ]]; then
  read -rsp "MySQL $DB_USERNAME@$DB_HOST 密码: " DB_ROOT_PASSWORD
  echo
fi
export MYSQL_PWD="$DB_ROOT_PASSWORD"   # 避免密码出现在 ps 输出
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME")
if ! "${MYSQL[@]}" -e "SELECT 1" >/dev/null 2>&1; then
  echo "无法连接 MySQL（$DB_USERNAME@$DB_HOST:$DB_PORT），请检查密码" >&2
  exit 1
fi
echo "MySQL 连接成功"

# ─────────────────────────── composer install ───────────────────────────

composer_install() {
  local dir="$1"
  if [[ -d "$dir/vendor" ]]; then
    echo "$dir/vendor 已存在，跳过 composer install"
  else
    echo "composer install（$dir）..."
    (cd "$dir" && composer install --no-interaction --no-dev --no-progress)
  fi
}
composer_install "$ROOT/service"
composer_install "$ROOT/admin"

# ─────────────────────────── 建库 + 导入 ───────────────────────────

echo "创建数据库 social / open_admin ..."
"${MYSQL[@]}" -e "CREATE DATABASE IF NOT EXISTS \`social\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`open_admin\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# install.sql 混含 social_（CREATE IF NOT EXISTS，幂等）与 erik_（DROP 重建）两类表：
#   social 段 = 1-184 行 + 445 行至文件尾（虚拟经济/支付/提现均为 social_ 表）
#   open_admin 段 = 185-444 行（含权限/角色/用户种子）
INSTALL_SQL="$ROOT/database/install.sql"

echo "导入 social 段（可重复执行，CREATE IF NOT EXISTS）..."
# 141-145 行是 voice 列 ALTER（无 IF NOT EXISTS，重复导入会报「列已存在」），排除后由下方按需补列。
sed -n '1,140p;146,184p;445,$p' "$INSTALL_SQL" | "${MYSQL[@]}" social

# 既有库升级：补 voice 列（部分升级可能只缺其中一列，逐列检查）
add_voice_col() {
  if [[ "$("${MYSQL[@]}" social -N -e "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='social' AND table_name='social_messages' AND column_name='$1'")" == "0" ]]; then
    "${MYSQL[@]}" social -e "ALTER TABLE social_messages ADD COLUMN $2;"
  fi
}
add_voice_col voice_url "voice_url VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL'"
add_voice_col voice_duration "voice_duration SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)'"

# 既有库升级：幂等键改为按用户/发送者唯一（与服务层查重条件一致）。
# 旧键全局唯一、比新键更严格，新键下不可能新增重复行；仅当索引首列仍为旧列时重建。
rekey() {
  local table="$1" index="$2" old_first_col="$3" new_cols="$4"
  if [[ "$("${MYSQL[@]}" social -N -e "SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema='social' AND table_name='$table' AND index_name='$index'
        AND seq_in_index=1 AND column_name='$old_first_col'")" == "1" ]]; then
    "${MYSQL[@]}" social -e "ALTER TABLE \`$table\` DROP INDEX \`$index\`, ADD UNIQUE KEY \`$index\` ($new_cols);"
    echo "已升级幂等键: $table.$index ($new_cols)"
  fi
}
rekey social_currency_transactions uniq_ref ref_type "user_id, ref_type, ref_id"
rekey social_gifts_given uniq_client_ref client_ref "from_uid, client_ref"
rekey social_messages uk_cmid client_msg_id "sender_id, client_msg_id"
rekey social_payments uniq_client_ref client_ref "user_id, client_ref"
rekey social_withdrawals uniq_client_ref client_ref "user_id, client_ref"

# 既有库升级：ref_id 扩容到 191（复合引用可能超过 64），仅旧长度时执行
if [[ "$("${MYSQL[@]}" social -N -e "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns
    WHERE table_schema='social' AND table_name='social_currency_transactions' AND column_name='ref_id'")" == "64" ]]; then
  "${MYSQL[@]}" social -e "ALTER TABLE social_currency_transactions MODIFY ref_id VARCHAR(191) NULL COMMENT '外部引用 ID（事务号/订单号）';"
fi

# 回填 IAP 凭证门：旧格式 platform:sku:transaction_id 流水补建去重记录（INSERT IGNORE 可重复执行）
"${MYSQL[@]}" social -e "INSERT IGNORE INTO social_iap_receipts
  (receipt_key, user_id, platform, sku, transaction_id, created_at, updated_at)
SELECT CONCAT(platform, ':', tx), user_id, platform, sku, tx, created_at, updated_at
FROM (
  SELECT
    SUBSTRING_INDEX(ref_id, ':', 1) AS platform,
    SUBSTRING_INDEX(SUBSTRING(ref_id, LENGTH(SUBSTRING_INDEX(ref_id, ':', 1)) + 2), ':', 1) AS sku,
    SUBSTRING(ref_id, LENGTH(SUBSTRING_INDEX(ref_id, ':', 1))
      + LENGTH(SUBSTRING_INDEX(SUBSTRING(ref_id, LENGTH(SUBSTRING_INDEX(ref_id, ':', 1)) + 2), ':', 1)) + 3) AS tx,
    user_id, created_at, updated_at
  FROM social_currency_transactions
  WHERE ref_type = 'iap' AND ref_id IS NOT NULL AND ref_id LIKE '%:%:%'
) AS legacy;"

# CREATE TABLE IF NOT EXISTS 对已存在表是 no-op，需显式去掉这三张表的 AUTO_INCREMENT：
# 主键改由应用层 snowflake 生成（Rust idgen_rs），残留自增与显式传入的 id 语义不明。
echo "移除 live/voice room 三表的 AUTO_INCREMENT ..."
"${MYSQL[@]}" social -e "
ALTER TABLE social_live_rooms MODIFY id BIGINT UNSIGNED NOT NULL COMMENT '主键ID，由snowflake生成';
ALTER TABLE social_voice_rooms MODIFY id BIGINT UNSIGNED NOT NULL COMMENT '主键ID，由snowflake生成';
ALTER TABLE social_voice_room_members MODIFY id BIGINT UNSIGNED NOT NULL COMMENT '主键ID，由snowflake生成';"

ADMIN_PASSWORD=""
if "${MYSQL[@]}" open_admin -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='open_admin' AND table_name='erik_admin_user'" | grep -q '^0$'; then
  echo "导入 open_admin 段（首次安装）..."
  sed -n '185,444p' "$INSTALL_SQL" | "${MYSQL[@]}" open_admin
else
  echo "open_admin 已安装（跳过重建，保留现有数据）"
fi

# 旧版默认口令（123456 的 bcrypt）/占位值/空口令：改为随机密码并启用；
# 自定义密码保持原状（禁用状态也维持禁用），软删除账号跳过。
LEGACY_ADMIN_HASH='$2y$10$OMKpk/QJFvFg.785anhTR.n038Gmwf.yx9sOPcbS/AgIt3ChKXswG'
# 分三次查询，避免空 password 被 IFS 折叠；软删除行单独判断
ADMIN_HASH_CUR="$("${MYSQL[@]}" open_admin -N -B -e "SELECT \`password\` FROM \`erik_admin_user\` WHERE \`username\`='erik' AND \`deleted_at\` IS NULL LIMIT 1")"
ADMIN_STATUS_CUR="$("${MYSQL[@]}" open_admin -N -B -e "SELECT \`status\` FROM \`erik_admin_user\` WHERE \`username\`='erik' AND \`deleted_at\` IS NULL LIMIT 1")"
ADMIN_DELETED_CUR="$("${MYSQL[@]}" open_admin -N -B -e "SELECT IFNULL(\`deleted_at\`, '') FROM \`erik_admin_user\` WHERE \`username\`='erik' LIMIT 1")"
if [[ -z "$ADMIN_HASH_CUR" && -z "$ADMIN_STATUS_CUR" && -z "$ADMIN_DELETED_CUR" ]]; then
  echo "未找到管理员账号 erik，跳过密码设置（请手动创建）"
elif [[ -n "$ADMIN_DELETED_CUR" ]]; then
  echo "管理员 erik 已被软删除（deleted_at=$ADMIN_DELETED_CUR），跳过密码设置（如需恢复请手动处理）"
elif [[ "$ADMIN_HASH_CUR" == "$LEGACY_ADMIN_HASH" || "$ADMIN_HASH_CUR" == "!" || -z "$ADMIN_HASH_CUR" ]]; then
  # 随机管理员密码：php -r 代码用单引号，哈希中的 $ 不会被 shell 展开
  ADMIN_PASSWORD="$(gen_key 12)"
  ADMIN_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$ADMIN_PASSWORD")"
  "${MYSQL[@]}" open_admin -e "UPDATE \`erik_admin_user\` SET \`password\`='$ADMIN_HASH', \`status\`=1 WHERE \`username\`='erik' AND \`deleted_at\` IS NULL;"
  echo "已设置管理员 erik 随机密码: $ADMIN_PASSWORD（仅显示一次，请妥善保存）"
elif [[ "$ADMIN_STATUS_CUR" != "1" ]]; then
  echo "管理员 erik 已被禁用（status=0），保留现有口令与禁用状态（如需启用请手动处理）"
else
  echo "管理员 erik 已使用自定义密码，保持现状（不重置）"
fi

# ─────────────────────────── 生成 .env ───────────────────────────

# 用 printf %s 写密码/密钥，避免特殊字符（$ # 空格等）被 shell 或 .env 解析破坏
write_env() {
  local file="$1"
  [[ -f "$file" ]] && { echo "已存在: $file（不覆盖）"; return 1; }
  {
    echo "# Generated by install.sh $(date '+%F %T')"
    echo "DB_CONNECTION=mysql"
    printf 'DB_HOST=%s\nDB_PORT=%s\nDB_USERNAME=%s\nDB_PASSWORD=%s\n' \
      "$DB_HOST" "$DB_PORT" "$DB_USERNAME" "$DB_ROOT_PASSWORD"
    echo "REDIS_HOST=127.0.0.1"
    echo "REDIS_PORT=6379"
    echo "REDIS_PASSWORD="
    echo "REDIS_DATABASE=0"
    printf 'JWT_SECRET=%s\n' "$(gen_key 32)"
    printf 'ENCRYPTABLE_KEY=%s\n' "$(gen_key 16)"
    echo "ENCRYPTABLE_CIPHER=aes-128-ecb"
  } > "$file"
}

# service/.env：social 库（DB_PREFIX=social_）+ 跨库读 open_admin
if [[ ! -f "$ROOT/service/.env" ]]; then
  write_env "$ROOT/service/.env"
  {
    echo "DB_DATABASE=social"
    echo "DB_PREFIX=social_"
    echo "DB_OPEN_ADMIN_DATABASE=open_admin"
    printf 'HASHIDS_SALT=%s\n' "$(gen_key 16)"
  } >> "$ROOT/service/.env"
  echo "已生成: $ROOT/service/.env"
fi

# admin/.env：open_admin 库（默认库）+ 跨库读 social
if [[ ! -f "$ROOT/admin/.env" ]]; then
  write_env "$ROOT/admin/.env"
  {
    echo "DB_DATABASE=open_admin"
    echo "DB_SOCIAL_DATABASE=social"
    echo "REDIS_PREFIX=open-admin:"
    printf 'JWT_SECRET_KEY=%s\n' "$(gen_key 32)"
    printf 'ENCRYPTION_KEY=%s\n' "$(gen_key 16)"
    printf 'HASHIDS_SALT=%s\n' "$(gen_key 16)"
  } >> "$ROOT/admin/.env"
  echo "已生成: $ROOT/admin/.env"
fi

# ─────────────────────────── media/sfu 凭证 ───────────────────────────

# coturn TURN REST 密钥：media/sfu/.env 是事实源，service/.env 使用同一值签发临时凭证
TURN_SECRET_VALUE=""
if [[ -f "$ROOT/media/sfu/.env" ]]; then
  TURN_SECRET_VALUE="$(grep -E '^TURN_SECRET=' "$ROOT/media/sfu/.env" | head -1 | cut -d= -f2- || true)"
  if [[ -z "$TURN_SECRET_VALUE" ]]; then
    # 兼容旧版静态密码键
    TURN_SECRET_VALUE="$(grep -E '^COTURN_PASSWORD=' "$ROOT/media/sfu/.env" | head -1 | cut -d= -f2- || true)"
  fi
fi
if [[ -z "$TURN_SECRET_VALUE" ]]; then
  TURN_SECRET_VALUE="$(gen_key 16)"
  echo "已生成 TURN_SECRET: $ROOT/media/sfu/.env"
fi
if [[ -f "$ROOT/media/sfu/.env" ]] && grep -q '^TURN_SECRET=' "$ROOT/media/sfu/.env"; then
  sed -i "s/^TURN_SECRET=.*/TURN_SECRET=$TURN_SECRET_VALUE/" "$ROOT/media/sfu/.env"
else
  printf 'TURN_SECRET=%s\n' "$TURN_SECRET_VALUE" >> "$ROOT/media/sfu/.env"
fi
# 旧版静态账号已不再使用
sed -i '/^COTURN_PASSWORD=/d' "$ROOT/media/sfu/.env" 2>/dev/null || true

# 服务端使用同一密钥；保留已有的 TURN_URL，缺失时写本机默认值
if grep -q '^TURN_SECRET=' "$ROOT/service/.env"; then
  sed -i "s/^TURN_SECRET=.*/TURN_SECRET=$TURN_SECRET_VALUE/" "$ROOT/service/.env"
else
  printf 'TURN_SECRET=%s\n' "$TURN_SECRET_VALUE" >> "$ROOT/service/.env"
fi
if ! grep -q '^TURN_URL=' "$ROOT/service/.env"; then
  printf 'TURN_URL=turn:127.0.0.1:3478\n' >> "$ROOT/service/.env"
  echo "TURN_URL 默认 turn:127.0.0.1:3478，生产环境请改为公网 TURN 地址"
fi

# ─────────────────────────── media/sfu 容器（可选） ───────────────────────────

if [[ "$SKIP_MEDIA" -eq 1 ]]; then
  echo "跳过 media/sfu 容器（--skip-media）"
elif command -v docker >/dev/null 2>&1; then
  echo "启动 media/sfu 容器（语音/通话）..."
  (cd "$ROOT/media/sfu" && docker compose up -d)
else
  echo "未安装 docker，跳过 media/sfu（需要时可手动启动）"
fi

# ─────────────────────────── 完成 ───────────────────────────

# 安装成功标记（存在即表示已初始化，内容为完成时间）
mkdir -p "$ROOT/admin/runtime"
date '+%F %T' > "$ROOT/admin/runtime/install.lock"

echo
echo "安装完成。启动服务："
echo "  service:  cd $ROOT/service && php start.php start -d   # http://0.0.0.0:8788"
echo "  admin:    cd $ROOT/admin && php start.php start -d     # http://0.0.0.0:8791"
if [[ -n "$ADMIN_PASSWORD" ]]; then
  echo "后台管理员账号: erik（随机密码已在上方输出，仅显示一次，请妥善保存）"
else
  echo "后台管理员账号: erik（密码未改动，详见上方输出）"
fi
