<?php
// Вебхук @bountycoffeebar_bot: самопривязка номера клиента для доставки кодов входа в Telegram.
// Аутентификация: заголовок X-Telegram-Bot-Api-Secret-Token == sms_config.tg_webhook_secret.
// Привязки: bounty_data/tg_link_map.json {"998XXXXXXXXX": chat_id}.
require __DIR__ . '/_lib.php';

$c = store_read('sms_config.json');
$secret = isset($c['tg_webhook_secret']) ? (string)$c['tg_webhook_secret'] : '';
$got = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? (string)$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
if ($secret === '' || !hash_equals($secret, $got)) { http_response_code(403); exit; }
$token = isset($c['tg_bot_token']) ? (string)$c['tg_bot_token'] : '';
if ($token === '') { echo 'ok'; exit; }

function tgw_send($token, $chat_id, $text, $markup = null) {
  $p = array('chat_id' => $chat_id, 'text' => $text);
  if ($markup !== null) { $p['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE); }
  $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
  curl_setopt_array($ch, array(
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($p),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
  ));
  curl_exec($ch); curl_close($ch);
}

$u = json_decode(file_get_contents('php://input'), true);
$msg = isset($u['message']) ? $u['message'] : null;
if (!$msg) { echo 'ok'; exit; }
$chat_id = isset($msg['chat']['id']) ? (int)$msg['chat']['id'] : 0;
$from_id = isset($msg['from']['id']) ? (int)$msg['from']['id'] : 0;
$type = isset($msg['chat']['type']) ? $msg['chat']['type'] : '';
if (!$chat_id || $type !== 'private') { echo 'ok'; exit; }

$kb_contact = array(
  'keyboard' => array(array(array('text' => '📱 Поделиться номером', 'request_contact' => true))),
  'resize_keyboard' => true, 'one_time_keyboard' => true,
);

if (!empty($msg['contact'])) {
  $ct = $msg['contact'];
  $ct_uid = isset($ct['user_id']) ? (int)$ct['user_id'] : 0;
  if ($ct_uid !== $from_id) {
    tgw_send($token, $chat_id, 'Это чужой контакт 🙂 Нажмите кнопку «📱 Поделиться номером» — нужен именно ваш номер.', $kb_contact);
    echo 'ok'; exit;
  }
  $phone = norm_phone(isset($ct['phone_number']) ? $ct['phone_number'] : '');
  if (!$phone) {
    tgw_send($token, $chat_id, 'Поддерживаются только номера Узбекистана (+998). Если ваш номер +998 — напишите нам в приложении.');
    echo 'ok'; exit;
  }
  store_update('tg_link_map.json', function ($m) use ($phone, $chat_id) {
    $m[$phone] = $chat_id;
    return array($m, true);
  });
  audit('tg_link', array('phone' => $phone, 'chat' => $chat_id));
  tgw_send($token, $chat_id,
    "✅ Номер +{$phone} привязан!\n\n" .
    "Теперь коды входа в приложение Bounty будут приходить сюда — SMS не нужна.\n\n" .
    "Вернитесь в приложение и запросите код ещё раз.\n\n" .
    "☕ Новости и акции: @bountycoffeebar_uz",
    array('remove_keyboard' => true));
  echo 'ok'; exit;
}

$text = isset($msg['text']) ? trim($msg['text']) : '';
if (strpos($text, '/start') === 0) {
  tgw_send($token, $chat_id,
    "☕ Привет! Это официальный бот кофейни Bounty Coffee Bar (Ташкент).\n\n" .
    "Здесь вы будете получать коды входа в наше приложение bountycoffeebar.uz/app — быстрее и надёжнее SMS.\n\n" .
    "Нажмите кнопку ниже и поделитесь номером, на который регистрируетесь.\n\n" .
    "Наш канал с новостями и акциями: @bountycoffeebar_uz",
    $kb_contact);
} else {
  tgw_send($token, $chat_id,
    "Чтобы получать коды входа, нажмите «📱 Поделиться номером».\n" .
    "Вопросы по заказам — через чат в приложении bountycoffeebar.uz/app.\n" .
    "Новости: @bountycoffeebar_uz",
    $kb_contact);
}
echo 'ok';
