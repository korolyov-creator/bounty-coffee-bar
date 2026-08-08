<?php
// Кошелёк клиента. POST {phone, client_id, action, ...}
// action:'get'                     → {ok, balance, tx, methods}
// action:'topup_request'           → заявка на пополнение: cash — код для баристы; click/payme/uzum — ссылка (если мерчант подключён)
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body();
if (!$d) { respond(['ok' => false], 400); }
list($phone, $id) = wallet_auth($d);
client_guard($phone, $id);
$action = (string)($d['action'] ?? 'get');
$name = mb_substr(trim((string)($d['name'] ?? '')), 0, 50);

if ($action === 'get') {
  $wallets = store_read('wallets.json');
  $w = $wallets[$phone] ?? array('balance' => 0, 'tx' => array());
  respond(['ok' => true, 'balance' => (int)$w['balance'], 'tx' => array_slice(array_reverse($w['tx']), 0, 30), 'methods' => pay_methods()]);
}

if ($action === 'topup_request') {
  $amount = (int)($d['amount'] ?? 0);
  if ($amount < 10000 || $amount > 5000000) { respond(['ok' => false, 'reason' => 'bad_amount'], 422); }
  $method = (string)($d['method'] ?? 'cash');
  // Только наличный расчёт: Click/Payme/Uzum не подключены, заблокированы на уровне API.
  if ($method !== 'cash') { respond(['ok' => false, 'reason' => 'method_disabled'], 400); }

  $tid = bin2hex(random_bytes(6));
  $code = (string)random_int(1000, 9999);
  store_update('topups.json', function ($data) use ($tid, $code, $phone, $id, $name, $amount, $method) {
    // чистим старые pending (>2 суток)
    $cut = time() - 2 * 86400;
    foreach ($data as $k => $v) { if (strtotime($v['ts'] ?? '') < $cut) { unset($data[$k]); } }
    $data[$tid] = array('code' => $code, 'phone' => $phone, 'id' => $id, 'name' => $name,
      'amount' => $amount, 'method' => $method, 'status' => 'pending', 'ts' => date('c'));
    return [$data, true];
  });
  $out = array('ok' => true, 'tid' => $tid, 'code' => $code);
  if ($method !== 'cash') { $out['link'] = $link; }
  respond($out);
}

respond(['ok' => false, 'reason' => 'bad_action'], 422);
