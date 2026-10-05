#!/bin/sh
# Второй dev-сервер с включённой игровой базой (для проверки кабинета в браузере).
# Делает копию сайта в $1 (по умолчанию /tmp/wrp-gametest) со своим config.php, который
# подключает основной config/config.php и включает game_db на тестовую базу wrp_game_test.
# Основные файлы проекта и config/config.php не меняются.
#   sh tests/b_game_server.sh /path/to/copy 8092
SRC="$(cd "$(dirname "$0")/.." && pwd)"
DST="${1:-/tmp/wrp-gametest}"
PORT="${2:-8092}"
mkdir -p "$DST"
rsync -a --delete --exclude config/config.php --exclude 'tests/screens' "$SRC/" "$DST/"
cat > "$DST/config/config.php" <<EOF
<?php
\$c = require '$SRC/config/config.php';
\$c['game_db'] = array_merge(\$c['game_db'], [
    'enabled' => true, 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'wrp_game_test',
    'user' => 'wrp_game', 'pass' => 'wrp_game', 'charset' => 'cp1251', 'table' => 'accounts',
    'col_name' => 'name', 'col_pass' => 'password', 'col_salt' => 'salt', 'hash' => 'auto',
    'stats' => ['level' => 'Уровень', 'money' => 'Наличные', 'bank' => 'Банк', 'city' => 'Город'],
]);
return \$c;
EOF
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null
cd "$DST" && (php -S 127.0.0.1:$PORT -t public > "$DST/php-$PORT.log" 2>&1 &)
sleep 1
echo "game test server: http://127.0.0.1:$PORT (copy in $DST)"
