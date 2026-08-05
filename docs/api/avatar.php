<?php
// Аватар клиента — единый на всех устройствах номера.
// POST {token, action:'set', data:dataURL} → {ok, ts}
// POST {token, action:'get'} → {ok, data, ts}   (data:'' если аватара нет/удалён)
// POST {token, action:'del'} → {ok}
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body(400000);
if (!$d) { respond(['ok' => false], 400); }
$token = (string)($d['token'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $token)) { respond(['ok' => false, 'reason' => 'bad_token'], 401); }
$tokens = store_read('tokens.json');
if (!isset($tokens[$token])) { respond(['ok' => false, 'reason' => 'bad_token'], 401); }
$phone = $tokens[$token]['phone'];
client_guard($phone);

$dir = bdata_path('avatars');
if (!is_dir($dir)) { mkdir($dir, 0700, true); }
$file = $dir . '/' . $phone . '.txt';
$action = (string)($d['action'] ?? 'get');

if ($action === 'get') {
  if (!is_file($file)) { respond(['ok' => true, 'data' => '', 'ts' => 0]); }
  respond(['ok' => true, 'data' => file_get_contents($file), 'ts' => filemtime($file)]);
}

if ($action === 'set') {
  $data = (string)($d['data'] ?? '');
  if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $data, $m)) {
    respond(['ok' => false, 'reason' => 'bad_image'], 422);
  }
  $bin = base64_decode($m[2], true);
  if ($bin === false || strlen($bin) > 250000 || !@getimagesizefromstring($bin)) {
    respond(['ok' => false, 'reason' => 'bad_image'], 422);
  }
  file_put_contents($file, $data, LOCK_EX);
  $ts = time();
  touch($file, $ts);
  store_update('accounts.json', function ($a) use ($phone, $ts) {
    if (isset($a[$phone])) { $a[$phone]['ava'] = $ts; }
    return [$a, true];
  });
  respond(['ok' => true, 'ts' => $ts]);
}

if ($action === 'del') {
  if (is_file($file)) { unlink($file); }
  $ts = time();
  store_update('accounts.json', function ($a) use ($phone, $ts) {
    if (isset($a[$phone])) { $a[$phone]['ava'] = $ts; }
    return [$a, true];
  });
  respond(['ok' => true, 'ts' => $ts]);
}

respond(['ok' => false, 'reason' => 'bad_action'], 422);
