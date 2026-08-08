<?php
// POST {phone} → отправка SMS-кода. Ответы:
// {ok:true} — код отправлен; {ok:false,reason:'sms_unavailable'} — провайдер не настроен (клиент работает по-старому)
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body();
if (!$d) { respond(['ok' => false], 400); }
$phone = norm_phone($d['phone'] ?? '');
if (!$phone) { respond(['ok' => false, 'reason' => 'bad_phone'], 422); }
client_guard($phone);

// Демо-аккаунт для App Review: фиксированный код, SMS не шлём
// Активен только если существует флаг-файл bounty_data/demo_enabled (создать вручную для тестов, удалить для прода)
if ($phone === '998900000000' && file_exists(dirname(__DIR__, 2) . '/bounty_data/demo_enabled')) { respond(['ok' => true]); }

$has_tg = tg_link_chat($phone) !== null;
if (!sms_config() && !$has_tg) { respond(['ok' => false, 'reason' => 'sms_unavailable', 'tg' => tg_bot_link()]); }

$ip = client_ip();
$now = time();

// Лимит по IP: 10 запросов в час
$ok_ip = store_update('otp_ip.json', function ($data) use ($ip, $now) {
  foreach ($data as $k => $ts) { $data[$k] = array_values(array_filter($ts, function ($t) use ($now) { return $now - $t < 3600; })); if (!$data[$k]) unset($data[$k]); }
  $cnt = count($data[$ip] ?? []);
  if ($cnt >= 10) { return [$data, false]; }
  $data[$ip][] = $now;
  return [$data, true];
});
if (!$ok_ip) { respond(['ok' => false, 'reason' => 'rate_limit'], 429); }

$code = (string)random_int(10000, 99999);

// Лимит по номеру: 3 SMS в час (Telegram бесплатен — 10 в час), повтор не чаще раза в 60 сек
$per_hour = $has_tg ? 10 : 3;
$res = store_update('otp.json', function ($data) use ($phone, $now, $code, $per_hour) {
  foreach ($data as $k => $v) { if ($now > ($v['exp'] ?? 0) + 3600) unset($data[$k]); }
  $e = $data[$phone] ?? array('sent' => []);
  $e['sent'] = array_values(array_filter(isset($e['sent']) ? $e['sent'] : [], function ($t) use ($now) { return $now - $t < 3600; }));
  if (count($e['sent']) >= $per_hour) { return [$data, 'rate_limit']; }
  if ($e['sent'] && $now - end($e['sent']) < 60) { return [$data, 'too_soon']; }
  $e['sent'][] = $now;
  $e['h'] = hash('sha256', $phone . ':' . $code);
  $e['exp'] = $now + 600;
  $e['tries'] = 0;
  $data[$phone] = $e;
  return [$data, 'ok'];
});
if ($res !== 'ok') { respond(['ok' => false, 'reason' => $res], 429); }

// Приоритет — Telegram: мгновенно и бесплатно; SMS — фолбэк (Eskiz в тест-режиме до договора)
if ($has_tg && tg_otp_send($phone, $code)) { respond(['ok' => true, 'via' => 'tg']); }
$sent = sms_send($phone, "Bounty Coffee Bar: kod dlya vhoda $code. Nikomu ne soobshchayte.");
if ($sent) { respond(['ok' => true]); }
// Код НЕ ушёл (SMS-провайдер отказал, TG не привязан) — возвращаем списанную попытку,
// иначе клиент, вернувшись после привязки Telegram, бьётся о 60-сек кулдаун («слишком много запросов»).
store_update('otp.json', function ($data) use ($phone, $now) {
  if (isset($data[$phone]['sent'])) {
    $data[$phone]['sent'] = array_values(array_filter($data[$phone]['sent'], function ($t) use ($now) { return $t !== $now; }));
    unset($data[$phone]['h'], $data[$phone]['exp']);
    if (!$data[$phone]['sent']) { unset($data[$phone]); }
  }
  return [$data, true];
});
audit('otp_send_fail', array('phone' => $phone));
// клиент уходит в локальную регистрацию; tg — ссылка на бота для самопривязки номера
respond(['ok' => false, 'reason' => 'sms_unavailable', 'tg' => tg_bot_link()]);
