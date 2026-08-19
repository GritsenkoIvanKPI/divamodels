<?php
/* ============================================================================
   DIVA MODELS — приём заявки и отправка в Telegram  (вариант для PHP-хостинга)
   ============================================================================
   Используется на обычном хостинге (cPanel, Hostinger, Beget, Timeweb и т.п.).
   Для Node/Vercel есть api/lead.js — делает то же самое.

   ЧТО НАСТРОИТЬ — две строки ниже:
     BOT_TOKEN — токен от @BotFather, например 1234567890:AAH...
     CHAT_ID   — id группы, отрицательное число: -1001234567890

   Куда положить: рядом с index.html, в корень сайта.
   Потом в apply.html и apply-form.html поставить LEAD_ENDPOINT = 'send.php'.

   ВАЖНО: токен лежит здесь, на сервере. В браузер он не попадает — PHP-файл
   отдаёт только результат. Не переносите токен в HTML или JS.
   ============================================================================ */

// ─────────────────────────────────────────────────────────────────────────────
const BOT_TOKEN = 'СЮДА_ТОКЕН_ОТ_BOTFATHER';
const CHAT_ID   = 'СЮДА_ID_ГРУППЫ';
// ─────────────────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');

function fail($code, $error) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') fail(405, 'method_not_allowed');

if (BOT_TOKEN === 'СЮДА_ТОКЕН_ОТ_BOTFATHER' || CHAT_ID === 'СЮДА_ID_ГРУППЫ') {
    error_log('send.php: BOT_TOKEN / CHAT_ID не заполнены');
    fail(500, 'not_configured');
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) $body = [];

/** Обрезаем всё, что пришло из браузера: и простыни, и мусор.
 *  Именованная функция, а не arrow fn — чтобы работало и на старом PHP 7.0. */
function dm_clean($v, $max = 200) {
    $s = trim((string)($v === null ? '' : $v));
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

// Ловушка для ботов: поле спрятано от людей, заполнить его может только спамер.
// Отвечаем «ок», чтобы бот не пробовал другие варианты.
if (dm_clean(isset($body['website']) ? $body['website'] : '') !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

$fields = [
    'name'       => 'Имя',
    'phone'      => 'Телефон',
    'telegram'   => 'Telegram',
    'age'        => 'Возраст',
    'experience' => 'Опыт',
    'taboo18'    => 'Контент 18+',
];
$labels = [
    'experience' => ['none' => 'Нет опыта', 'model' => 'Модель', 'operator' => 'Оператор'],
    'taboo18'    => ['yes' => 'Да', 'no' => 'Нет'],
];

$missing = [];
$rows = [];
foreach ($fields as $key => $title) {
    $raw = dm_clean(isset($body[$key]) ? $body[$key] : '');
    if ($raw === '') { $missing[] = $key; continue; }
    $val = isset($labels[$key][$raw]) ? $labels[$key][$raw] : $raw;
    $rows[] = '<b>' . $title . ':</b> ' . htmlspecialchars($val, ENT_NOQUOTES, 'UTF-8');
}
if ($missing) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields', 'missing' => $missing], JSON_UNESCAPED_UNICODE);
    exit;
}

$source = dm_clean(isset($body['source']) ? $body['source'] : '', 60) ?: 'не указан';
$page   = dm_clean(isset($body['page']) ? $body['page'] : '', 300);
$when   = dm_clean(isset($body['submittedAt']) ? $body['submittedAt'] : '', 60);

$lines = array_merge(
    ['🔥 <b>Новая заявка</b>', ''],
    $rows,
    ['', '<i>Откуда:</i> ' . htmlspecialchars($source, ENT_NOQUOTES, 'UTF-8')]
);
if ($when !== '') $lines[] = '<i>Когда:</i> ' . htmlspecialchars($when, ENT_NOQUOTES, 'UTF-8');
if ($page !== '') $lines[] = '<i>Страница:</i> ' . htmlspecialchars($page, ENT_NOQUOTES, 'UTF-8');

$payload = json_encode([
    'chat_id' => CHAT_ID,
    'text'    => implode("\n", $lines),
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 12,
]);
$response = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log('send.php: не достучались до Telegram: ' . $curlErr);
    fail(502, 'telegram_unreachable');
}

$result = json_decode($response, true);
if (!is_array($result) || empty($result['ok'])) {
    // описание от Telegram очень помогает: "chat not found", "bot was blocked"…
    error_log('send.php: Telegram отказал: ' . (isset($result['description']) ? $result['description'] : $response));
    fail(502, 'telegram_rejected');
}

echo json_encode(['ok' => true]);
