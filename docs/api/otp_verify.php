<?php
// POST {phone, code, name?} → проверка кода, вход/создание аккаунта.
// Ответ: {ok:true, token, account:{name,id,stamps,total,free,reg,hist}}
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body();
if (!$d) { respond(['ok' => false], 400); }
$phone = norm_phone($d['phone'] ?? '');
$code = preg_replace('/\D/', '', (string)($d['code'] ?? ''));
if (!$phone || strlen($code) !== 5) { respond(['ok' => false, 'reason' => 'bad_input'], 422); }
client_guard($phone);

$now = time();
$res = store_update('otp.json', function ($data) use ($phone, $code, $now) {
  $e = isset($data[$phone]) ? $data[$phone] : null;
  if (!$e || empty($e['h'])) { return [$data, 'no_code']; }
  if ($now > $e['exp']) { unset($data[$phone]); return [$data, 'expired']; }
  $e['tries'] = (isset($e['tries']) ? $e['tries'] : 0) + 1;
  if ($e['tries'] > 5) { unset($data[$phone]); return [$data, 'too_many_tries']; }
  if (!hash_equals($e['h'], hash('sha256', $phone . ':' . $code))) { $data[$phone] = $e; return [$data, 'wrong_code']; }
  unset($data[$phone]);
  return [$data, 'ok'];
});
if ($res !== 'ok') { respond(['ok' => false, 'reason' => $res], 401); }

$name = mb_substr(trim((string)($d['name'] ?? '')), 0, 50);

$account = store_update('accounts.json', function ($data) use ($phone, $name) {
  if (!isset($data[$phone])) {
    $data[$phone] = array(
      'name' => ($name !== '' ? $name : 'Гость'),
      'phone' => $phone,
      'id' => 'BNT-' . substr($phone, -7) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 3)),
      'stamps' => 0, 'total' => 0, 'free' => 0, 'wallet' => 0,
      'reg' => date('Y-m-d'), 'hist' => array(),
    );
  } elseif ($name !== '') {
    $data[$phone]['name'] = $name;
  }
  return [$data, $data[$phone]];
});

$token = bin2hex(random_bytes(24));
store_update('tokens.json', function ($data) use ($token, $phone) {
  // чистка токенов старше года
  $cut = time() - 365 * 86400;
  foreach ($data as $t => $v) { if (($v['ts'] ?? 0) < $cut) unset($data[$t]); }
  $data[$token] = array('phone' => $phone, 'ts' => time());
  return [$data, true];
});

respond(['ok' => true, 'token' => $token, 'account' => $account]);
