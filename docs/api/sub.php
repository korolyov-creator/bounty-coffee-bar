<?php
// Клиентский API абонемента: статус и заморозка.
// POST {phone, client_id, action?: 'status'|'freeze'|'unfreeze', days?}
require __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body();
if (!$d) { respond(['ok' => false], 400); }

$phone = norm_phone($d['phone'] ?? '');
$cid = substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($d['client_id'] ?? '')), 0, 32);
if (!$phone || $cid === '') { respond(['ok' => false, 'reason' => 'bad_auth'], 401); }
client_guard($phone, $cid);

$action = (string)($d['action'] ?? 'status');

// привязка client_id при первом обращении, дальше — строгая проверка
$bind = store_update('subs.json', function ($data) use ($phone, $cid) {
  if (!isset($data[$phone])) { return [$data, array('sub' => null)]; }
  $s = $data[$phone];
  if (empty($s['cid'])) { $s['cid'] = $cid; $data[$phone] = $s; }
  elseif ($s['cid'] !== $cid) { return [$data, array('mismatch' => true)]; }
  return [$data, array('sub' => $s)];
});
if (!empty($bind['mismatch'])) { respond(['ok' => false, 'reason' => 'id_mismatch'], 403); }
$sub = $bind['sub'];

if ($action === 'status') {
  respond(['ok' => true, 'sub' => sub_public($sub), 'plans' => sub_plans()]);
}

if ($action === 'freeze') {
  $days = max(1, min(60, (int)($d['days'] ?? 0)));
  $res = store_update('subs.json', function ($data) use ($phone, $days) {
    $s = $data[$phone] ?? null;
    $st = sub_state($s);
    if ($st === 'none' || $st === 'expired') { return [$data, array('err' => 'no_sub')]; }
    if ($st === 'frozen') { return [$data, array('err' => 'already_frozen')]; }
    if ($days > (int)($s['freeze_left'] ?? 0)) { return [$data, array('err' => 'not_enough_freeze')]; }
    $today = date('Y-m-d');
    if (isset($s['used'][$today])) { return [$data, array('err' => 'used_today')]; }
    $s['frozen_until'] = date('Y-m-d', strtotime($today) + ($days - 1) * 86400);
    $s['end'] = date('Y-m-d', strtotime($s['end']) + $days * 86400);
    $s['freeze_left'] = (int)$s['freeze_left'] - $days;
    $s['hist'][] = array('t' => 'freeze', 'days' => $days, 'ts' => date('c'));
    $data[$phone] = $s;
    return [$data, $s];
  });
  if (isset($res['err'])) { respond(['ok' => false, 'reason' => $res['err']]); }
  audit('sub_freeze', array('phone' => $phone, 'days' => $days));
  respond(['ok' => true, 'sub' => sub_public($res)]);
}

if ($action === 'unfreeze') {
  $res = store_update('subs.json', function ($data) use ($phone) {
    $s = $data[$phone] ?? null;
    if (sub_state($s) !== 'frozen') { return [$data, array('err' => 'not_frozen')]; }
    $today = date('Y-m-d');
    // вернуть неиспользованные дни заморозки
    $back = max(0, (int)round((strtotime($s['frozen_until']) - strtotime($today)) / 86400) + 1);
    $s['frozen_until'] = '';
    $s['end'] = date('Y-m-d', strtotime($s['end']) - $back * 86400);
    $s['freeze_left'] = (int)($s['freeze_left'] ?? 0) + $back;
    $s['hist'][] = array('t' => 'unfreeze', 'back' => $back, 'ts' => date('c'));
    $data[$phone] = $s;
    return [$data, $s];
  });
  if (isset($res['err'])) { respond(['ok' => false, 'reason' => $res['err']]); }
  audit('sub_unfreeze', array('phone' => $phone));
  respond(['ok' => true, 'sub' => sub_public($res)]);
}

respond(['ok' => false, 'reason' => 'bad_action'], 422);
