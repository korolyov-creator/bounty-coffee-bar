<?php
// POST {token, action:'get'} → {ok, account, orders}
// POST {token, action:'sync', stamps, total, free, hist:[..], name?} → сервер принимает состояние карты
// Мультиустройство: сервер — источник правды. Счётчики принимаются только вверх (устаревшее
// устройство не может занизить карту), hist объединяется, а не перетирается.
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
  // последние заказы этого номера — чтобы «Мои заказы» были одинаковы на всех устройствах
  $orders = array();
  $f = bdata_path('orders.jsonl');
  if (is_file($f)) {
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $r = json_decode($line, true);
      if (!is_array($r)) { continue; }
      if (norm_phone($r['phone'] ?? '') !== $phone) { continue; }
      unset($r['ip']);
      $orders[$r['id']] = $r;
    }
    $orders = array_slice($orders, -20, 20, true);
    $sf = bdata_path('orders_status.jsonl');
    if (is_file($sf)) {
      foreach (file($sf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r) && isset($r['id']) && isset($orders[$r['id']])) { $orders[$r['id']]['status'] = $r['status']; }
      }
    }
    foreach ($orders as &$o) { if (!isset($o['status'])) { $o['status'] = 'new'; } }
    unset($o);
  }
  respond(['ok' => true, 'account' => $accounts[$phone], 'orders' => array_values($orders)]);
}

if ($action === 'sync') {
  $account = store_update('accounts.json', function ($data) use ($phone, $d) {
    if (!isset($data[$phone])) { return [$data, null]; }
    $a = $data[$phone];
    // счётчики: сервер главнее — клиент может только догонять, не занижать
    foreach (array('stamps', 'total', 'free') as $k) {
      if (isset($d[$k])) {
        $v = max(0, min(100000, (int)$d[$k]));
        if ($v > (int)($a[$k] ?? 0)) { $a[$k] = $v; }
      }
    }
    if (isset($d['points'])) {
      $p = max(0, min(1000000, (int)$d['points']));
      if (!isset($a['points']) || $p > (int)$a['points']) { $a['points'] = $p; }
    }
    $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 50);
    if ($name !== '') { $a['name'] = $name; }
    if (isset($d['hist']) && is_array($d['hist'])) {
      // объединение истории: серверные записи + новые с устройства, без дублей
      $merged = array();
      foreach (array_merge((array)($a['hist'] ?? array()), $d['hist']) as $h) {
        if (!is_array($h)) { continue; }
        $key = (string)($h['t'] ?? '') . '|' . (string)($h['d'] ?? '') . '|' . (string)($h['o'] ?? '');
        $merged[$key] = $h;
      }
      $merged = array_values($merged);
      usort($merged, function ($x, $y) { return strcmp((string)($x['d'] ?? ''), (string)($y['d'] ?? '')); });
      $a['hist'] = array_slice($merged, -200);
    }
    $data[$phone] = $a;
    return [$data, $a];
  });
  if (!$account) { respond(['ok' => false, 'reason' => 'no_account'], 404); }
  respond(['ok' => true, 'account' => $account]);
}

respond(['ok' => false, 'reason' => 'bad_action'], 422);
