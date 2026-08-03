<?php
// API персонала (бариста / админ). POST JSON.
// action:'login' {login?, password}  → {ok, role, token, name}
//   админ — пароль администратора (логин пуст или 'admin');
//   бариста — личный логин+пароль; общий код работает, пока включён legacy-режим
// action:'logout' {token}
// далее все запросы с {token}:
//  'topups' | 'topup_confirm' {tid} | 'topup_cancel' {tid} | 'topup_direct' {phone, amount}
//  'chat_threads' | 'chat_get' {phone} | 'chat_send' {phone, text}
//  админ: 'overview' | 'adjust' {phone, amount, note}
//  админ: 'staff_list' | 'staff_invite' {phone, name?} | 'staff_invite_cancel' {phone}
//  админ: 'staff_block'/'staff_unblock' {login} | 'staff_reset' {login} | 'legacy_toggle' {on}
//  админ: 'client_block' {phone, note?} | 'client_unblock' {phone}
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body(16384);
if (!$d) { respond(['ok' => false], 400); }
$action = (string)($d['action'] ?? '');

if ($action === 'login') {
  usleep(300000); // тормозим перебор
  if (!login_rate_check()) { respond(['ok' => false, 'reason' => 'rate_limit'], 429); }
  $login = strtolower(trim((string)($d['login'] ?? '')));
  $pass = (string)($d['password'] ?? '');
  $cfg = auth_config();

  // владелец: свой телефон + пароль администратора → максимальные права, без подтверждения
  if (norm_phone($login) === OWNER_PHONE && !empty($cfg['admin_hash']) && password_verify($pass, $cfg['admin_hash'])) {
    $token = staff_session_create('owner', 'owner', 'Владелец', true);
    audit('login_ok', array('login' => 'owner'));
    respond(['ok' => true, 'role' => 'owner', 'token' => $token, 'name' => 'Владелец']);
  }
  // админ — вход требует подтверждения владельцем
  if (($login === '' || $login === 'admin') && !empty($cfg['admin_hash']) && password_verify($pass, $cfg['admin_hash'])) {
    $token = staff_session_create('admin', 'admin', 'Администратор', false);
    audit('login_pending', array('login' => 'admin'));
    respond(['ok' => true, 'role' => 'admin', 'token' => $token, 'name' => 'Администратор', 'pending' => true]);
  }
  // личный аккаунт бариста — вход требует подтверждения владельцем
  if ($login !== '' && $login !== 'admin') {
    $acc = store_read('staff_accounts.json');
    $a = $acc[$login] ?? null;
    if ($a && password_verify($pass, $a['hash'])) {
      $st = $a['status'] ?? '';
      // pending — регистрация без кода, ждёт «приёма на работу»: пускаем в pending-сессию
      if ($st !== 'active' && $st !== 'pending') { audit('login_blocked', array('login' => $login)); respond(['ok' => false, 'reason' => 'blocked'], 403); }
      store_update('staff_accounts.json', function ($data) use ($login) {
        if (isset($data[$login])) { $data[$login]['last_login'] = date('c'); }
        return [$data, true];
      });
      $token = staff_session_create($login, 'barista', $a['name'], false);
      audit('login_pending', array('login' => $login));
      respond(['ok' => true, 'role' => 'barista', 'token' => $token, 'name' => $a['name'], 'pending' => true]);
    }
  }
  // общий код бариста (legacy, можно выключить в админке) — тоже через подтверждение
  if ($login === '' && !empty($cfg['legacy_barista']) && !empty($cfg['legacy_hash']) && password_verify($pass, $cfg['legacy_hash'])) {
    $token = staff_session_create('_legacy', 'barista', 'Бариста (общий код)', false);
    audit('login_pending', array('login' => '_legacy'));
    respond(['ok' => true, 'role' => 'barista', 'token' => $token, 'name' => 'Бариста', 'pending' => true]);
  }
  login_rate_fail();
  audit('login_fail', array('login' => $login));
  respond(['ok' => false, 'reason' => 'wrong_password'], 401);
}

if ($action === 'logout') {
  $token = (string)($d['token'] ?? '');
  if (preg_match('/^[a-f0-9]{48}$/', $token)) {
    store_update('staff_sessions.json', function ($data) use ($token) { unset($data[$token]); return [$data, true]; });
  }
  respond(['ok' => true]);
}

// статус собственной сессии (доступен и до подтверждения владельцем)
if ($action === 'session_status') {
  $s = staff_auth($d['token'] ?? '', true);
  if (!$s) { respond(['ok' => false, 'reason' => 'expired'], 403); }
  respond(['ok' => true, 'approved' => !isset($s['approved']) || (bool)$s['approved'], 'role' => $s['role'], 'name' => $s['name']]);
}

$sess = staff_auth($d['token'] ?? '');
if (!$sess) { respond(['ok' => false, 'reason' => 'forbidden'], 403); }
$role = $sess['role'];
$who = in_array($role, array('admin', 'owner'), true) ? $role : $sess['login'];

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
  // фискализация: наличное пополнение — аванс, чек ККМ «Аванс» обязателен в момент приёма денег (ПКМ №943)
  if ($st === 'done' && empty($d['fiscal'])) { respond(['ok' => false, 'reason' => 'fiscal_required'], 422); }
  $t = store_update('topups.json', function ($data) use ($tid, $st, $who) {
    if (!isset($data[$tid]) || $data[$tid]['status'] !== 'pending') { return [$data, null]; }
    $data[$tid]['status'] = $st;
    $data[$tid]['by'] = $who;
    $data[$tid]['done_ts'] = date('c');
    if ($st === 'done') { $data[$tid]['fiscal'] = true; }
    return [$data, $data[$tid]];
  });
  if (!$t) { respond(['ok' => false, 'reason' => 'not_pending'], 422); }
  if ($st === 'done') {
    wallet_credit($t['phone'], $t['amount'], 'topup', array('m' => $t['method'], 'by' => $who, 'id' => $t['id'] ?? '', 'name' => $t['name'] ?? '', 'f' => 1));
    audit('topup_confirm', array('by' => $who, 'phone' => $t['phone'], 'amount' => $t['amount'], 'fiscal' => true));
    if ((int)$t['amount'] >= 500000) {
      tg_alert("👛 Крупное пополнение кошелька: " . number_format((int)$t['amount'], 0, '', ' ') . " сум\nКлиент: " . $t['phone'] . "\nПодтвердил: $who");
    }
  }
  respond(['ok' => true]);
}

if ($action === 'topup_direct') {
  $phone = norm_phone($d['phone'] ?? '');
  $amount = (int)($d['amount'] ?? 0);
  // прямое зачисление без кода клиента — главный канал злоупотреблений: жёсткий лимит + чек + алерт владельцу
  if (!$phone || $amount < 1000 || $amount > 300000) { respond(['ok' => false, 'reason' => 'bad_input', 'limit' => 300000], 422); }
  if (empty($d['fiscal'])) { respond(['ok' => false, 'reason' => 'fiscal_required'], 422); }
  $w = wallet_credit($phone, $amount, 'topup', array('m' => 'cash', 'by' => $who, 'f' => 1, 'direct' => 1));
  audit('topup_direct', array('by' => $who, 'phone' => $phone, 'amount' => $amount, 'fiscal' => true));
  tg_alert("⚠️ ПРЯМОЕ зачисление на кошелёк (без кода клиента)\nСумма: " . number_format($amount, 0, '', ' ') . " сум\nКлиент: $phone\nСотрудник: $who\nПроверь: наличные в кассе + чек «Аванс».");
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

// === АБОНЕМЕНТЫ (доступно бариста) ===

if ($action === 'sub_info') {
  $phone = norm_phone($d['phone'] ?? '');
  if (!$phone) { respond(['ok' => false, 'reason' => 'bad_input'], 422); }
  $subs = store_read('subs.json');
  respond(['ok' => true, 'phone' => $phone, 'sub' => sub_public($subs[$phone] ?? null)]);
}

if ($action === 'sub_sell') {
  $phone = norm_phone($d['phone'] ?? '');
  $plan = (string)($d['plan'] ?? '');
  $method = in_array(($d['m'] ?? 'cash'), array('cash', 'card'), true) ? $d['m'] : 'cash';
  $plans = sub_plans();
  if (!$phone || !isset($plans[$plan])) { respond(['ok' => false, 'reason' => 'bad_input'], 422); }
  if (empty($d['fiscal'])) { respond(['ok' => false, 'reason' => 'fiscal_required'], 422); }
  $p = $plans[$plan];
  $who = $sess['name'] ?? $role;
  $res = store_update('subs.json', function ($data) use ($phone, $plan, $p, $who, $method) {
    $s = $data[$phone] ?? null;
    $today = date('Y-m-d');
    if ($s && sub_state($s) !== 'expired' && !empty($s['end'])) {
      // действующий абонемент — продлеваем от даты окончания
      $base = max(strtotime($s['end']), strtotime($today));
      $s['end'] = date('Y-m-d', $base + $p['days'] * 86400);
      $s['freeze_left'] = (int)($s['freeze_left'] ?? 0) + $p['freeze'];
      $s['plan'] = $plan;
    } else {
      $s = array('plan' => $plan, 'start' => $today,
        'end' => date('Y-m-d', strtotime($today) + ($p['days'] - 1) * 86400),
        'freeze_left' => $p['freeze'], 'frozen_until' => '',
        'cid' => $s['cid'] ?? '', 'name' => $s['name'] ?? '',
        'used' => $s['used'] ?? array(), 'hist' => $s['hist'] ?? array());
    }
    $s['hist'][] = array('t' => 'sell', 'plan' => $plan, 'price' => $p['price'], 'm' => $method, 'by' => $who, 'ts' => date('c'));
    if (count($s['hist']) > 500) { $s['hist'] = array_slice($s['hist'], -500); }
    $data[$phone] = $s;
    return [$data, $s];
  });
  audit('sub_sell', array('phone' => $phone, 'plan' => $plan, 'by' => $who, 'm' => $method));
  tg_alert("💳 ПРОДАН АБОНЕМЕНТ «" . $p['n'] . "»\nКлиент: +$phone\nСумма: " . number_format($p['price'], 0, '', ' ') . " сум (" . ($method === 'cash' ? 'наличные' : 'карта') . ")\nСотрудник: $who\nДействует до: " . $res['end']);
  respond(['ok' => true, 'sub' => sub_public($res)]);
}

if ($action === 'sub_redeem') {
  $phone = norm_phone($d['phone'] ?? '');
  if (!$phone) { respond(['ok' => false, 'reason' => 'bad_input'], 422); }
  if (empty($d['fiscal'])) { respond(['ok' => false, 'reason' => 'fiscal_required'], 422); }
  $who = $sess['name'] ?? $role;
  $res = store_update('subs.json', function ($data) use ($phone, $who) {
    $s = $data[$phone] ?? null;
    $st = sub_state($s);
    if ($st === 'none') { return [$data, array('err' => 'no_sub')]; }
    if ($st === 'expired') { return [$data, array('err' => 'expired')]; }
    if ($st === 'frozen') { return [$data, array('err' => 'frozen', 'until' => $s['frozen_until'])]; }
    $today = date('Y-m-d');
    if (isset($s['used'][$today])) { return [$data, array('err' => 'used_today')]; }
    $s['used'][$today] = array('by' => $who, 'ts' => date('c'));
    if (count($s['used']) > 400) { $s['used'] = array_slice($s['used'], -400, null, true); }
    $s['hist'][] = array('t' => 'use', 'by' => $who, 'ts' => date('c'));
    if (count($s['hist']) > 500) { $s['hist'] = array_slice($s['hist'], -500); }
    $data[$phone] = $s;
    return [$data, $s];
  });
  if (isset($res['err'])) {
    audit('sub_redeem_fail', array('phone' => $phone, 'err' => $res['err'], 'by' => $who));
    respond(['ok' => false, 'reason' => $res['err'], 'until' => $res['until'] ?? '']);
  }
  audit('sub_redeem', array('phone' => $phone, 'by' => $who));
  respond(['ok' => true, 'sub' => sub_public($res)]);
}

if ($role !== 'admin' && $role !== 'owner') { respond(['ok' => false, 'reason' => 'admin_only'], 403); }

// === ПОДТВЕРЖДЕНИЯ ВЛАДЕЛЬЦА (только owner) ===

if ($action === 'approvals_list' || $action === 'session_approve' || $action === 'session_reject'
    || $action === 'staff_approve' || $action === 'staff_reject_reg') {
  if ($role !== 'owner') { respond(['ok' => false, 'reason' => 'owner_only'], 403); }

  if ($action === 'approvals_list') {
    $sessions = store_read('staff_sessions.json');
    $accounts = store_read('staff_accounts.json');
    // регистрации без кода админа — ждут «приёма на работу»
    $regs = array();
    foreach ($accounts as $lg => $a) {
      if (($a['status'] ?? '') === 'pending') {
        $regs[] = array('login' => $lg, 'name' => $a['name'] ?? '', 'phone' => $a['phone'] ?? '', 'ts' => $a['created'] ?? '');
      }
    }
    usort($regs, function ($a, $b) { return strcmp($b['ts'], $a['ts']); });
    $out = array();
    $now = time();
    foreach ($sessions as $t => $s) {
      // сессии pending-аккаунтов покрыты карточкой регистрации — приём на работу подтвердит их разом
      if (($accounts[$s['login']]['status'] ?? '') === 'pending') { continue; }
      if (isset($s['approved']) && !$s['approved'] && ($s['exp'] ?? 0) >= $now) {
        $out[] = array('sid' => substr($t, 0, 12), 'login' => $s['login'], 'role' => $s['role'],
          'name' => $s['name'], 'ts' => date('c', $s['ts'] ?? $now));
      }
    }
    usort($out, function ($a, $b) { return strcmp($b['ts'], $a['ts']); });
    respond(['ok' => true, 'pending' => $out, 'registrations' => $regs]);
  }

  if ($action === 'staff_approve' || $action === 'staff_reject_reg') {
    $login = strtolower(trim((string)($d['login'] ?? '')));
    $hire = $action === 'staff_approve';
    $found = store_update('staff_accounts.json', function ($data) use ($login, $hire) {
      if (!isset($data[$login]) || ($data[$login]['status'] ?? '') !== 'pending') { return [$data, null]; }
      $acc = $data[$login];
      if ($hire) { $data[$login]['status'] = 'active'; } else { unset($data[$login]); }
      return [$data, $acc];
    });
    if (!$found) { respond(['ok' => false, 'reason' => 'not_found'], 404); }
    if ($hire) {
      store_update('staff_sessions.json', function ($data) use ($login) {
        foreach ($data as $t => $s) {
          if (($s['login'] ?? '') === $login) { $data[$t]['approved'] = true; }
        }
        return [$data, true];
      });
    } else {
      staff_sessions_kill($login);
    }
    audit($hire ? 'staff_approve' : 'staff_reject_reg', array('by' => 'owner', 'login' => $login));
    respond(['ok' => true]);
  }

  $sid = substr(preg_replace('/[^a-f0-9]/', '', (string)($d['sid'] ?? '')), 0, 12);
  if (strlen($sid) !== 12) { respond(['ok' => false, 'reason' => 'bad_sid'], 422); }
  $approve = $action === 'session_approve';
  $found = store_update('staff_sessions.json', function ($data) use ($sid, $approve) {
    foreach ($data as $t => $s) {
      if (strpos($t, $sid) === 0 && isset($s['approved']) && !$s['approved']) {
        if ($approve) { $data[$t]['approved'] = true; } else { unset($data[$t]); }
        return [$data, $s];
      }
    }
    return [$data, null];
  });
  if (!$found) { respond(['ok' => false, 'reason' => 'not_found'], 404); }
  audit($approve ? 'session_approve' : 'session_reject', array('by' => 'owner', 'login' => $found['login'], 'role' => $found['role']));
  respond(['ok' => true]);
}

if ($action === 'overview') {
  // клиенты: серверные аккаунты + анкеты регистрации
  $accounts = store_read('accounts.json');
  $wallets = store_read('wallets.json');
  $block = store_read('blocklist.json');
  $loyalty = store_read('loyalty.json');
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
  foreach ($clients as $p => &$c) {
    $c['wallet'] = (int)($wallets[$p]['balance'] ?? 0);
    $c['blocked'] = isset($block['phones'][$p]);
    $c['loyalty'] = (int)($loyalty[$p]['tier'] ?? 1);
  }
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
      'blocked' => isset($block['phones'][$p]),
      'tx' => array_slice(array_reverse($w['tx']), 0, 10));
  }

  // --- АНАЛИТИКА (owner-only, 30 дней) ---
  $analytics = null;
  $staffKpi = null;
  if ($role === 'owner') {
    $sinceAn = time() - 30 * 86400;
    $ordersAll = array();
    if (is_file($of)) {
      foreach (file($of, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r) && isset($r['ts']) && strtotime($r['ts']) >= $sinceAn) {
          unset($r['ip']);
          $r['status'] = 'new';
          $ordersAll[$r['id']] = $r;
        }
      }
    }
    if (is_file($sf)) {
      foreach (file($sf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r) && isset($ordersAll[$r['id'] ?? ''])) {
          $ordersAll[$r['id']]['status'] = $r['status'];
          if (!empty($r['by'])) { $ordersAll[$r['id']]['status_by'] = $r['by']; }
        }
      }
    }
    // Хиты продаж — по всем непокрытым заказам
    $items = array();
    // Топ клиенты — по phone, сумма и количество (только не отменённые)
    $payers = array();
    // KPI бариста: считаем «выданные» заказы (status=done) по staff-логину
    $kpi = array();
    foreach ($ordersAll as $o) {
      $cancelled = ($o['status'] ?? '') === 'cancel';
      if (!$cancelled) {
        foreach ($o['items'] ?? array() as $it) {
          $name = trim((string)($it['n'] ?? ''));
          if ($name === '') continue;
          $qty = (int)($it['qty'] ?? 1);
          $sum = (int)($it['s'] ?? $it['p'] ?? 0) * $qty;
          if (!isset($items[$name])) { $items[$name] = array('name' => $name, 'qty' => 0, 'sum' => 0); }
          $items[$name]['qty'] += $qty;
          $items[$name]['sum'] += $sum;
        }
        $ph = norm_phone($o['phone'] ?? '') ?: (string)($o['phone'] ?? '');
        if ($ph !== '') {
          if (!isset($payers[$ph])) { $payers[$ph] = array('phone' => $ph, 'name' => $o['name'] ?? '', 'orders' => 0, 'sum' => 0); }
          $payers[$ph]['orders']++;
          $payers[$ph]['sum'] += (int)($o['total'] ?? 0);
          if (!$payers[$ph]['name']) { $payers[$ph]['name'] = $o['name'] ?? ''; }
        }
      }
      if (($o['status'] ?? '') === 'done') {
        $by = (string)($o['status_by'] ?? '');
        if ($by !== '' && $by !== 'client') {
          if (!isset($kpi[$by])) { $kpi[$by] = array('login' => $by, 'orders' => 0, 'sum' => 0); }
          $kpi[$by]['orders']++;
          $kpi[$by]['sum'] += (int)($o['total'] ?? 0);
        }
      }
    }
    usort($items, function ($a, $b) { return $b['qty'] - $a['qty']; });
    usort($payers, function ($a, $b) { return $b['sum'] - $a['sum']; });
    // добавляем в топ-клиентов кошелёк и лояльность
    foreach ($payers as &$pr) {
      $pr['wallet'] = (int)($wallets[$pr['phone']]['balance'] ?? 0);
      $pr['loyalty'] = (int)($loyalty[$pr['phone']]['tier'] ?? 1);
    }
    unset($pr);
    // KPI — имена бариста
    $staffAcc = store_read('staff_accounts.json');
    foreach ($kpi as &$k) {
      $lg = $k['login'];
      $k['name'] = $lg === 'admin' ? 'Администратор' : ($staffAcc[$lg]['name'] ?? $lg);
      $k['avg'] = $k['orders'] > 0 ? (int)round($k['sum'] / $k['orders']) : 0;
    }
    unset($k);
    usort($kpi, function ($a, $b) { return $b['orders'] - $a['orders']; });

    $analytics = array(
      'period_days' => 30,
      'top_items' => array_slice(array_values($items), 0, 20),
      'top_payers' => array_slice(array_values($payers), 0, 20),
      'orders_total' => count($ordersAll),
    );
    $staffKpi = array_values($kpi);
  }

  respond(['ok' => true,
    'clients' => array_values($clients),
    'orders' => array_values($orders),
    'wallets' => $walletsOut,
    'analytics' => $analytics,
    'staff_kpi' => $staffKpi,
  ]);
}

if ($action === 'adjust') {
  $phone = norm_phone($d['phone'] ?? '');
  $amount = (int)($d['amount'] ?? 0);
  $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 100);
  if (!$phone || $amount === 0 || abs($amount) > 5000000) { respond(['ok' => false, 'reason' => 'bad_input'], 422); }
  $w = wallet_credit($phone, $amount, 'adjust', array('by' => $who, 'note' => $note));
  audit('adjust', array('by' => $who, 'phone' => $phone, 'amount' => $amount, 'note' => $note));
  tg_alert("✏️ Корректировка кошелька: " . ($amount > 0 ? '+' : '') . number_format($amount, 0, '', ' ') . " сум\nКлиент: $phone\nКто: $who\nПричина: " . ($note !== '' ? $note : '—'));
  respond(['ok' => true, 'balance' => $w['balance']]);
}

// === ЛОЯЛЬНОСТЬ КЛИЕНТОВ (только owner) ===
if ($action === 'client_loyalty') {
  if ($role !== 'owner') { respond(['ok' => false, 'reason' => 'owner_only'], 403); }
  $phone = norm_phone($d['phone'] ?? '');
  $tier = (int)($d['tier'] ?? 0);
  $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 100);
  if (!$phone || $tier < 1 || $tier > 5) { respond(['ok' => false, 'reason' => 'bad_input'], 422); }
  store_update('loyalty.json', function ($data) use ($phone, $tier, $note) {
    $data[$phone] = array('tier' => $tier, 'updated' => date('c'), 'by' => 'owner', 'note' => $note);
    return [$data, true];
  });
  audit('client_loyalty', array('phone' => $phone, 'tier' => $tier, 'note' => $note));
  respond(['ok' => true, 'tier' => $tier]);
}

// === УПРАВЛЕНИЕ ПЕРСОНАЛОМ ===

if ($action === 'staff_list') {
  $acc = store_read('staff_accounts.json');
  $out = array();
  foreach ($acc as $login => $a) {
    $out[] = array('login' => $login, 'name' => $a['name'], 'phone' => $a['phone'] ?? '',
      'status' => $a['status'], 'created' => $a['created'] ?? '', 'last_login' => $a['last_login'] ?? '');
  }
  $inv = store_read('staff_invites.json');
  $invites = array();
  $now = time();
  foreach ($inv as $p => $v) {
    if (($v['exp'] ?? 0) >= $now) { $invites[] = array('phone' => $p, 'name' => $v['name'] ?? '', 'exp' => date('c', $v['exp'])); }
  }
  $cfg = auth_config();
  respond(['ok' => true, 'staff' => $out, 'invites' => $invites, 'legacy' => !empty($cfg['legacy_barista'])]);
}

if ($action === 'staff_invite') {
  $phone = norm_phone($d['phone'] ?? '');
  $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 50);
  if (!$phone) { respond(['ok' => false, 'reason' => 'bad_phone'], 422); }
  $code = (string)random_int(100000, 999999);
  store_update('staff_invites.json', function ($data) use ($phone, $code, $name) {
    $now = time();
    foreach ($data as $k => $v) { if (($v['exp'] ?? 0) < $now) unset($data[$k]); }
    $data[$phone] = array('h' => hash('sha256', $phone . ':' . $code), 'exp' => $now + 86400, 'tries' => 0, 'name' => $name);
    return [$data, true];
  });
  audit('staff_invite', array('phone' => $phone, 'name' => $name));
  $sent = sms_send($phone, "Bounty Coffee Bar: kod registratsii barista $code. Deystvuet 24 chasa.");
  // если SMS-провайдер не настроен — код показываем админу, он передаёт бариста лично
  respond(['ok' => true, 'sms_sent' => $sent, 'code' => $sent ? null : $code]);
}

if ($action === 'staff_invite_cancel') {
  $phone = norm_phone($d['phone'] ?? '');
  if (!$phone) { respond(['ok' => false], 422); }
  store_update('staff_invites.json', function ($data) use ($phone) { unset($data[$phone]); return [$data, true]; });
  respond(['ok' => true]);
}

if ($action === 'staff_block' || $action === 'staff_unblock') {
  $login = strtolower(preg_replace('/[^a-z0-9\-]/', '', (string)($d['login'] ?? '')));
  $st = $action === 'staff_block' ? 'blocked' : 'active';
  $ok = store_update('staff_accounts.json', function ($data) use ($login, $st) {
    if (!isset($data[$login])) { return [$data, false]; }
    $data[$login]['status'] = $st;
    return [$data, true];
  });
  if (!$ok) { respond(['ok' => false, 'reason' => 'not_found'], 404); }
  if ($st === 'blocked') { staff_sessions_kill($login); }
  audit($action, array('login' => $login));
  respond(['ok' => true]);
}

if ($action === 'staff_reset') {
  $login = strtolower(preg_replace('/[^a-z0-9\-]/', '', (string)($d['login'] ?? '')));
  $password = gen_password(12);
  $ok = store_update('staff_accounts.json', function ($data) use ($login, $password) {
    if (!isset($data[$login])) { return [$data, false]; }
    $data[$login]['hash'] = password_hash($password, PASSWORD_DEFAULT);
    return [$data, true];
  });
  if (!$ok) { respond(['ok' => false, 'reason' => 'not_found'], 404); }
  staff_sessions_kill($login);
  audit('staff_reset', array('login' => $login));
  respond(['ok' => true, 'login' => $login, 'password' => $password]);
}

if ($action === 'legacy_toggle') {
  $on = !empty($d['on']);
  store_update('auth.json', function ($data) use ($on) {
    $data['legacy_barista'] = $on;
    return [$data, true];
  });
  if (!$on) { staff_sessions_kill('_legacy'); }
  audit('legacy_toggle', array('on' => $on));
  respond(['ok' => true, 'legacy' => $on]);
}

// === БЛОКИРОВКА КЛИЕНТОВ ===

if ($action === 'client_block' || $action === 'client_unblock') {
  $phone = norm_phone($d['phone'] ?? '');
  $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 100);
  if (!$phone) { respond(['ok' => false, 'reason' => 'bad_phone'], 422); }
  $blockIt = $action === 'client_block';
  // блокируем и телефон, и привязанную карту (client_id), чтобы не обойти сменой номера в форме
  $wallets = store_read('wallets.json');
  $accounts = store_read('accounts.json');
  $ids = array();
  if (!empty($wallets[$phone]['id'])) { $ids[] = $wallets[$phone]['id']; }
  if (!empty($accounts[$phone]['id'])) { $ids[] = $accounts[$phone]['id']; }
  store_update('blocklist.json', function ($data) use ($phone, $ids, $blockIt, $note) {
    if (!isset($data['phones'])) { $data['phones'] = array(); }
    if (!isset($data['ids'])) { $data['ids'] = array(); }
    if ($blockIt) {
      $data['phones'][$phone] = array('ts' => date('c'), 'note' => $note);
      foreach ($ids as $id) { $data['ids'][$id] = array('ts' => date('c'), 'phone' => $phone); }
    } else {
      unset($data['phones'][$phone]);
      foreach ($data['ids'] as $id => $v) { if (($v['phone'] ?? '') === $phone) unset($data['ids'][$id]); }
      foreach ($ids as $id) { unset($data['ids'][$id]); }
    }
    return [$data, true];
  });
  audit($action, array('phone' => $phone, 'note' => $note));
  respond(['ok' => true]);
}

respond(['ok' => false, 'reason' => 'bad_action'], 422);
