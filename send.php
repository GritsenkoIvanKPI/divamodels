<?php
/* ============================================================================
   DIVA MODELS — приём заявки и отправка в Telegram  (вариант для PHP-хостинга)
   ============================================================================
   Для Node/Vercel есть api/lead.js — делает то же самое.

   ГДЕ ЛЕЖИТ ТОКЕН (в этом файле его нет и не должно быть):

     1) Переменные окружения TELEGRAM_BOT_TOKEN и TELEGRAM_CHAT_ID — если
        хостинг умеет их задавать. Самый безопасный вариант.
     2) Иначе — файл telegram-config.php рядом с этим. Он добавлен в
        .gitignore, поэтому не попадает в репозиторий. Образец: см.
        telegram-config.example.php

   Токен не виден в браузере: страница обращается только сюда, а PHP уже сам
   ходит в Telegram. Никогда не переносите токен в HTML или JS.
   ============================================================================ */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function fail($code, $error) {
    http_response_code($code);
    echo json_encode(array('ok' => false, 'error' => $error), JSON_UNESCAPED_UNICODE);
    exit;
}

if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') fail(405, 'method_not_allowed');

/* --- учётные данные ------------------------------------------------------ */
$BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN');
$CHAT_ID   = getenv('TELEGRAM_CHAT_ID');
if (!$BOT_TOKEN && is_readable(__DIR__ . '/telegram-config.php')) {
    $cfg = require __DIR__ . '/telegram-config.php';
    if (is_array($cfg)) {
        $BOT_TOKEN = isset($cfg['token'])   ? $cfg['token']   : '';
        $CHAT_ID   = isset($cfg['chat_id']) ? $cfg['chat_id'] : '';
    }
}
if (!$BOT_TOKEN || !$CHAT_ID) {
    error_log('send.php: не заданы TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID');
    fail(500, 'not_configured');
}

/* --- простая защита от потока заявок с одного адреса ---------------------- *
 * Не даёт залить группу сотней сообщений за минуту. Хранится во временных
 * файлах, поэтому это лучшее из возможного без базы, а не строгая гарантия. */
function rate_limited($max = 5, $window = 600) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $file = sys_get_temp_dir() . '/dm_lead_' . md5($ip) . '.txt';
    $now = time();
    $hits = array();
    if (is_readable($file)) {
        $raw = @file_get_contents($file);
        foreach (explode(',', (string)$raw) as $t) {
            $t = (int)$t;
            if ($t && $now - $t < $window) $hits[] = $t;
        }
    }
    if (count($hits) >= $max) return true;
    $hits[] = $now;
    @file_put_contents($file, implode(',', $hits), LOCK_EX);
    return false;
}
if (rate_limited()) fail(429, 'too_many_requests');

/* --- разбор входящих данных ---------------------------------------------- */
$body = json_decode(file_get_contents('php://input') ? file_get_contents('php://input') : '', true);
if (!is_array($body)) $body = array();

/** Обрезаем всё, что пришло из браузера: и простыни, и мусор. */
function dm_clean($v, $max = 200) {
    $s = trim((string)($v === null ? '' : $v));
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

// Ловушка для спам-ботов: человек этого поля не видит. Отвечаем «ок», чтобы
// бот не подбирал другие варианты.
if (dm_clean(isset($body['website']) ? $body['website'] : '') !== '') {
    echo json_encode(array('ok' => true));
    exit;
}

$fields = array(
    'name'       => 'Имя',
    'phone'      => 'Телефон',
    'telegram'   => 'Telegram',
    'age'        => 'Возраст',
    'experience' => 'Опыт',
    'taboo18'    => 'Контент 18+',
);
$labels = array(
    'experience' => array('none' => 'Нет опыта', 'model' => 'Модель', 'operator' => 'Оператор'),
    'taboo18'    => array('yes' => 'Да', 'no' => 'Нет'),
);

$missing = array();
$rows = array();
foreach ($fields as $key => $title) {
    $raw = dm_clean(isset($body[$key]) ? $body[$key] : '');
    if ($raw === '') { $missing[] = $key; continue; }
    $val = isset($labels[$key][$raw]) ? $labels[$key][$raw] : $raw;
    $rows[] = '<b>' . $title . ':</b> ' . htmlspecialchars($val, ENT_NOQUOTES, 'UTF-8');
}
if ($missing) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'missing_fields', 'missing' => $missing), JSON_UNESCAPED_UNICODE);
    exit;
}

$source = dm_clean(isset($body['source']) ? $body['source'] : '', 60);
if ($source === '') $source = 'не указан';
$page = dm_clean(isset($body['page']) ? $body['page'] : '', 300);
$when = dm_clean(isset($body['submittedAt']) ? $body['submittedAt'] : '', 60);

$lines = array_merge(
    array('🔥 <b>Новая заявка</b>', ''),
    $rows,
    array('', '<i>Откуда:</i> ' . htmlspecialchars($source, ENT_NOQUOTES, 'UTF-8'))
);
if ($when !== '') $lines[] = '<i>Когда:</i> ' . htmlspecialchars($when, ENT_NOQUOTES, 'UTF-8');
if ($page !== '') $lines[] = '<i>Страница:</i> ' . htmlspecialchars($page, ENT_NOQUOTES, 'UTF-8');

$payload = json_encode(array(
    'chat_id' => $CHAT_ID,
    'text'    => implode("\n", $lines),
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true,
), JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.telegram.org/bot' . $BOT_TOKEN . '/sendMessage');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
));
$response = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log('send.php: не достучались до Telegram: ' . $curlErr);
    fail(502, 'telegram_unreachable');
}

$result = json_decode($response, true);
if (!is_array($result) || empty($result['ok'])) {
    error_log('send.php: Telegram отказал: ' . (isset($result['description']) ? $result['description'] : $response));
    fail(502, 'telegram_rejected');
}

echo json_encode(array('ok' => true));
