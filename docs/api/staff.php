<?php
// API персонала (бариста / админ). POST JSON.
// action:'login' {password}                      → {ok, role, key}
// далее все запросы с {key}:
//  'topups'                                      → заявки на пополнение (pending)
//  'topup_confirm' {tid}                         → зачислить по заявке
//  'topup_cancel' {tid}
//  'topup_direct' {phone, amount}                → бариста принял деньги на кассе без заявки
//  'chat_threads'                                → нити чата (последние сообщения)
//  'chat_get' {phone}                            → нить клиента
//  'chat_send' {phone, text}
//  админ: 'overview'                             → клиенты, кошельки, статистика
//  админ: 'adjust' {phone, amount, note}         → ручная корректировка кошелька (+/-)
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body(16384);
if (!$d) { respond(['ok' => false], 400); }
$action = (string)($d['action'] ?? '');

if ($action === 'login') {
  // Пароли: бариста — 1414 (общий код бариста), админ — см. креды у владельца
  $pass = (string)($d['password'] ?? '');
  usleep(200000); // тормозим перебор
  if (hash_equals(hash('sha256', 'bounty:' . $pass), hash('sha256', 'bounty:' . 'Bounty-Admin-8352'))) {
    respond(['ok' => true, 'role' => 'admin', 'key' => BOUNTY_ADMIN_KEY]);
  }
  if (hash_equals(hash('sha256', 'bounty:' . $pass), hash('sha256', 'bounty:' . '1414'))) {
    respond(['ok' => true, 'role' => 'barista', 'key' => BOUNTY_BARISTA_KEY]);
  }
  respond(['ok' => false, 'reason' => 'wrong_password'], 401);
}

$role = staff_role($d['key'] ?? '');
if (!$role) { respond(['ok' => false, 'reason' => 'forbidden'], 403); }

if ($action === 'topups') {
  $t = store_read('topups.json');
  $pending = array();
  foreach ($t as $tid => $v) { if (($v['status'] ?? '') === 'pending') { $v['tid'] = $tid; $pending[] = $v; } }
  usort($pending, function ($a, $b) { return strcmp($b['ts'], $a['ts']); });
  respond(['ok' => true, 'topups' => $pending]);
}

if ($action === 'topup_confirm' || $action === 'topup_cancel') {
  $tid = substr(preg_replace('/[^a-f0-9]/', '', (string)($d['tid'] ?? '')), 0, 12);
  $st = $action === 'topup_confirm' ? 'done' : 'cancel';
  $t = store_update('topups.json', function ($data) use ($tid, $st, $role) {
    if (!isset($data[$tid]) || $data[$tid]['status'] !== 'pending') { return [$data, null]; }
    $data[$tid]['status'] = $st;
    $data[$tid]['by'] = $role;
    $data[$tid]['done_ts'] = date('c');
    return [$data, $data[$tid]];
  });
  if (!$t) { respond(['ok' => false, 'reason' => 'not_pending'], 422); }
  if ($st === 'done') {
    wallet_credit($t['phone'], $t['amount'], 'topup', array('m' => $t['method'], 'by' => $role, 'id' => $t['id'] ?? '', 'name' => $t['name'] ?? ''));
  }
  respond(['ok' => true]);
}

if ($action === 'topup_direct') {
  $phone = norm_phone($d['phone'] ?? '');
  $amount = (int)($d['amount'] ?? 0);
  if (!$phone || $amount < 1000 || $amount > 5000000) { respond(['ok' => false, 'reason' => 'bad_input'], 422); }
  $w = wallet_credit($phone, $amount, 'topup', array('m' => 'cash', 'by' => $role));
  respond(['ok' => true, 'balance' => $w['balance']]);
}

if ($action === 'chat_threads') {
  $threads = array();
  $f = bdata_path('chat.jsonl');
  if (is_file($f)) {
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $r = json_decode($line, true);
      if (!is_array($r)) continue;
      $p = $r['phone'];
      if (!isset($threads[$p])) { $threads[$p] = array('phone' => $p, 'name' => '', 'last' => null, 'unread' => 0); }
      if (!empty($r['name'])) { $threads[$p]['name'] = $r['name']; }
      $threads[$p]['last'] = $r;
      if ($r['from'] === 'client') { $threads[$p]['unread']++; } else { $threads[$p]['unread'] = 0; }
    }
  }
  $out = array_values($threads);
  usort($out, function ($a, $b) { return strcmp($b['last']['ts'], $a['last']['ts']); });
  respond(['ok' => true, 'threads' => array_slice($out, 0, 50)]);
}

if ($action === 'chat_get') {
  $phone = norm_phone($d['phone'] ?? '');
  if (!$phone) { respond(['ok' => false], 422); }
  $msgs = array();
  $f = bdata_path('chat.jsonl');
  if (is_file($f)) {
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $r = json_decode($line, true);
      if (is_array($r) && ($r['phone'] ?? '') === $phone) { $msgs[] = $r; }
    }
  }
  respond(['ok' => true, 'msgs' => array_slice($msgs, -60)]);
}

if ($action === 'chat_send') {
  $phone = norm_phone($d['phone'] ?? '');
  $text = mb_substr(trim((string)($d['text'] ?? '')), 0, 500);
  if (!$phone || $text === '') { respond(['ok' => false], 422); }
  $rec = array('phone' => $phone, 'from' => 'staff', 'text' => $text, 'ts' => date('c'), 'role' => $role);
  file_put_contents(bdata_path('chat.jsonl'), json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
  respond(['ok' => true]);
}

if ($role !== 'admin') { respond(['ok' => false, 'reason' => 'admin_only'], 403); }

if ($action === 'overview') {
  // клиенты: серверные аккаунты + анкеты регистрации
  $accounts = store_read('accounts.json');
  $wallets = store_read('wallets.json');
  $clients = array();
  $f = bdata_path('clients.jsonl');
  if (is_file($f)) {
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $r = json_decode($line, true);
      if (is_array($r) && !empty($r['phone'])) {
        $p = norm_phone($r['phone']) ?: $r['phone'];
        $clients[$p] = array('phone' => $p, 'name' => $r['name'], 'id' => $r['id'] ?? '',
          'reg' => $r['reg'] ?? '', 'stamps' => (int)($r['stamps'] ?? 0), 'total' => (int)($r['total'] ?? 0),
          'free' => (int)($r['free'] ?? 0), 'seen' => $r['ts'] ?? '');
      }
    }
  }
  foreach ($accounts as $p => $a) {
    $clients[$p] = array_merge($clients[$p] ?? array('phone' => $p, 'seen' => ''), array(
      'name' => $a['name'], 'id' => $a['id'], 'reg' => $a['reg'],
      'stamps' => (int)$a['stamps'], 'total' => (int)$a['total'], 'free' => (int)$a['free'], 'account' => true));
  }
  foreach ($clients as $p => &$c) { $c['wallet'] = (int)($wallets[$p]['balance'] ?? 0); }
  unset($c);

  // заказы за 7 дней + статусы
  $since = time() - 7 * 86400;
  $orders = array();
  $of = bdata_path('orders.jsonl');
  if (is_file($of)) {
    foreach (file($of, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $r = json_decode($line, true);
      if (is_array($r) && isset($r['ts']) && strtotime($r['ts']) >= $since) {
        unset($r['ip']);
        $r['status'] = 'new';
        $orders[$r['id']] = $r;
      }
    }
  }
  $sf = bdata_path('orders_status.jsonl');
  if (is_file($sf)) {
    foreach (file($sf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $r = json_decode($line, true);
      if (is_array($r) && isset($orders[$r['id'] ?? ''])) {
        $orders[$r['id']]['status'] = $r['status'];
        if (!empty($r['by'])) { $orders[$r['id']]['status_by'] = $r['by']; }
      }
    }
  }

  $walletsOut = array();
  foreach ($wallets as $p => $w) {
    $walletsOut[] = array('phone' => $p, 'name' => $w['name'] ?? '', 'balance' => (int)$w['balance'],
      'tx' => array_slice(array_reverse($w['tx']), 0, 10));
  }

  respond(['ok' => true,
    'clients' => array_values($clients),
    'orders' => array_values($orders),
    'wallets' => $walletsOut,
  ]);
}

if ($action === 'adjust') {
  $phone = norm_phone($d['phone'] ?? '');
  $amount = (int)($d['amount'] ?? 0);
  $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 100);
  if (!$phone || $amount === 0) { respond(['ok' => false, 'reason' => 'bad_input'], 422); }
  $w = wallet_credit($phone, $amount, 'adjust', array('by' => 'admin', 'note' => $note));
  respond(['ok' => true, 'balance' => $w['balance']]);
}

respond(['ok' => false, 'reason' => 'bad_action'], 422);
