<?php
// POST {phone} → отправка SMS-кода. Ответы:
// {ok:true} — код отправлен; {ok:false,reason:'sms_unavailable'} — провайдер не настроен (клиент работает по-старому)
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body();
if (!$d) { respond(['ok' => false], 400); }
$phone = norm_phone($d['phone'] ?? '');
if (!$phone) { respond(['ok' => false, 'reason' => 'bad_phone'], 422); }

if (!sms_config()) { respond(['ok' => false, 'reason' => 'sms_unavailable']); }

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

// Лимит по номеру: 3 SMS в час, повтор не чаще раза в 60 сек
$res = store_update('otp.json', function ($data) use ($phone, $now, $code) {
  foreach ($data as $k => $v) { if ($now > ($v['exp'] ?? 0) + 3600) unset($data[$k]); }
  $e = $data[$phone] ?? array('sent' => []);
  $e['sent'] = array_values(array_filter(isset($e['sent']) ? $e['sent'] : [], function ($t) use ($now) { return $now - $t < 3600; }));
  if (count($e['sent']) >= 3) { return [$data, 'rate_limit']; }
  if ($e['sent'] && $now - end($e['sent']) < 60) { return [$data, 'too_soon']; }
  $e['sent'][] = $now;
  $e['h'] = hash('sha256', $phone . ':' . $code);
  $e['exp'] = $now + 600;
  $e['tries'] = 0;
  $data[$phone] = $e;
  return [$data, 'ok'];
});
if ($res !== 'ok') { respond(['ok' => false, 'reason' => $res], 429); }

$sent = sms_send($phone, "Bounty Coffee Bar: kod dlya vhoda $code. Nikomu ne soobshchayte.");
if (!$sent) { respond(['ok' => false, 'reason' => 'sms_failed'], 502); }
respond(['ok' => true]);
