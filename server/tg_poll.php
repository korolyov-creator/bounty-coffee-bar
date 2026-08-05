<?php
// Поллер @bountycoffeebar_bot (замена вебхука).
// Причина: входящие соединения Telegram → хостинг регулярно таймаутят («Connection timed out»
// в getWebhookInfo), ответы бота приходили пачками с задержкой 40-70 с. Исходящие соединения
// хостинг → api.telegram.org стабильны, поэтому длинный getUpdates даёт ответ мгновенно.
// Запуск: cron каждую минуту, живёт ~55 с, flock от наложений.
// Обработка обновлений не дублируется: каждое шлём в локальный api/tg_webhook.php (с секретом),
// его ответ {method:...} исполняем через Bot API.
set_time_limit(0);
$BASE = '/home/socia361/bounty_data';
$lock = fopen($BASE . '/tg_poll.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit; }
$cfg = json_decode(file_get_contents($BASE . '/sms_config.json'), true);
$token = isset($cfg['tg_bot_token']) ? (string)$cfg['tg_bot_token'] : '';
$secret = isset($cfg['tg_webhook_secret']) ? (string)$cfg['tg_webhook_secret'] : '';
if ($token === '' || $secret === '') { exit; }
$offFile = $BASE . '/tg_poll_offset.txt';
$off = (int)@file_get_contents($offFile);
$deadline = time() + 55;

function tp_curl($url, $post, $headers = null) {
  $ch = curl_init($url);
  $opts = array(
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
  );
  if ($headers !== null) { $opts[CURLOPT_HTTPHEADER] = $headers; }
  curl_setopt_array($ch, $opts);
  $r = curl_exec($ch);
  curl_close($ch);
  return json_decode($r, true);
}

while (true) {
  $left = $deadline - time();
  if ($left < 4) { break; }
  $to = min(20, $left - 3);
  $r = tp_curl('https://api.telegram.org/bot' . $token . '/getUpdates', http_build_query(array(
    'timeout' => $to, 'offset' => $off ?: '', 'allowed_updates' => '["message"]')));
  if (empty($r['ok'])) { sleep(3); continue; }
  foreach ($r['result'] as $u) {
    $off = $u['update_id'] + 1;
    file_put_contents($offFile, (string)$off);
    $resp = tp_curl('https://bountycoffeebar.uz/api/tg_webhook.php',
      json_encode($u, JSON_UNESCAPED_UNICODE),
      array('Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $secret));
    if (!empty($resp['method'])) {
      $m = $resp['method'];
      unset($resp['method']);
      tp_curl('https://api.telegram.org/bot' . $token . '/' . $m,
        json_encode($resp, JSON_UNESCAPED_UNICODE),
        array('Content-Type: application/json'));
    }
  }
}
