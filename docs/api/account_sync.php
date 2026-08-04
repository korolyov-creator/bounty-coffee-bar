<?php
// POST {token, action:'get'} → {ok, account}
// POST {token, action:'sync', stamps, total, free, hist:[..], name?} → сервер принимает состояние карты
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body(16384);
if (!$d) { respond(['ok' => false], 400); }
$token = (string)($d['token'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $token)) { respond(['ok' => false, 'reason' => 'bad_token'], 401); }
$tokens = store_read('tokens.json');
if (!isset($tokens[$token])) { respond(['ok' => false, 'reason' => 'bad_token'], 401); }
$phone = $tokens[$token]['phone'];
client_guard($phone);

$action = (string)($d['action'] ?? 'get');

if ($action === 'get') {
  $accounts = store_read('accounts.json');
  if (!isset($accounts[$phone])) { respond(['ok' => false, 'reason' => 'no_account'], 404); }
  respond(['ok' => true, 'account' => $accounts[$phone]]);
}

if ($action === 'sync') {
  $account = store_update('accounts.json', function ($data) use ($phone, $d) {
    if (!isset($data[$phone])) { return [$data, null]; }
    $a = $data[$phone];
    foreach (array('stamps', 'total', 'free') as $k) {
      if (isset($d[$k])) { $a[$k] = max(0, min(100000, (int)$d[$k])); }
    }
    // баллы: сервер главнее — клиент может только догонять, не занижать
    if (isset($d['points'])) {
      $p = max(0, min(1000000, (int)$d['points']));
      if (!isset($a['points']) || $p > (int)$a['points']) { $a['points'] = $p; }
    }
    $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 50);
    if ($name !== '') { $a['name'] = $name; }
    if (isset($d['hist']) && is_array($d['hist'])) {
      $a['hist'] = array_slice($d['hist'], -200);
    }
    $data[$phone] = $a;
    return [$data, $a];
  });
  if (!$account) { respond(['ok' => false, 'reason' => 'no_account'], 404); }
  respond(['ok' => true, 'account' => $account]);
}

respond(['ok' => false, 'reason' => 'bad_action'], 422);
