<?php
// Статус игрового сервера через SA-MP query (работает и для open.mp). Ответ кэшируется на 30 секунд.

function samp_query_info($ip, $port, $timeout = 1.0)
{
    $host = gethostbyname($ip);
    $parts = explode('.', $host);
    if (count($parts) !== 4) {
        return null;
    }
    $errno = 0;
    $errstr = '';
    $sock = @fsockopen('udp://' . $host, (int)$port, $errno, $errstr, $timeout);
    if (!$sock) {
        return null;
    }
    stream_set_timeout($sock, (int)$timeout, (int)(($timeout - floor($timeout)) * 1000000));
    $packet = 'SAMP' . chr((int)$parts[0]) . chr((int)$parts[1]) . chr((int)$parts[2]) . chr((int)$parts[3])
        . chr($port & 0xFF) . chr(($port >> 8) & 0xFF) . 'i';
    fwrite($sock, $packet);
    $data = fread($sock, 2048);
    fclose($sock);
    if (!is_string($data) || strlen($data) < 11 + 5 || substr($data, 0, 4) !== 'SAMP') {
        return null;
    }
    $pos = 11;
    $password = ord($data[$pos]);
    $pos += 1;
    $players = unpack('v', substr($data, $pos, 2))[1];
    $pos += 2;
    $max = unpack('v', substr($data, $pos, 2))[1];
    $pos += 2;
    $read = function () use ($data, &$pos) {
        if ($pos + 4 > strlen($data)) {
            return '';
        }
        $len = unpack('V', substr($data, $pos, 4))[1];
        $pos += 4;
        $s = substr($data, $pos, $len);
        $pos += $len;
        // Сервер отдаёт строки в cp1251
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
        }
        return $s;
    };
    $hostname = $read();
    $gamemode = $read();
    $language = $read();
    return [
        'online' => true,
        'password' => (bool)$password,
        'players' => (int)$players,
        'max' => (int)$max,
        'hostname' => $hostname,
        'gamemode' => $gamemode,
        'language' => $language,
    ];
}

// Статус для вывода на сайте: всегда массив с ключами online, players, max, hostname, gamemode, checked
function server_status()
{
    static $status = null;
    if ($status !== null) {
        return $status;
    }
    $empty = ['online' => false, 'players' => 0, 'max' => 0, 'hostname' => '', 'gamemode' => '', 'language' => '', 'password' => false, 'checked' => false];
    if (setting('server_query') !== '1') {
        return $status = $empty;
    }
    $file = WRP_STORAGE . '/cache/server_status.json';
    if (is_file($file) && filemtime($file) > time() - 30) {
        $cached = json_decode((string)file_get_contents($file), true);
        if (is_array($cached)) {
            return $status = array_merge($empty, $cached);
        }
    }
    $info = samp_query_info((string)setting('server_ip'), (int)setting('server_port'));
    $status = $info ? array_merge($empty, $info, ['checked' => true]) : array_merge($empty, ['checked' => true]);
    @file_put_contents($file, json_encode($status, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $status;
}
