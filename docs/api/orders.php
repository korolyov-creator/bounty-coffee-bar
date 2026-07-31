<?php
// Панель персонала: список заказов (GET) и смена статуса (POST). Доступ по сессионному токену (бариста или админ).
require __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=utf-8');
$sess = staff_auth((string)($_GET['token'] ?? ''));
if (!$sess) { http_response_code(403); echo '{"ok":false}'; exit; }
$role = $sess['role'];

$dir = dirname(__DIR__, 2) . '/bounty_data';
$statusFile = $dir . '/orders_status.jsonl';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $d = json_decode(file_get_contents('php://input', false, null, 0, 1024), true);
  $id = substr(preg_replace('/[^a-f0-9]/', '', (string)($d['id'] ?? '')), 0, 16);
  $status = (string)($d['status'] ?? '');
  if (!$id || !in_array($status, array('new', 'making', 'ready', 'done', 'cancel'), true)) {
    http_response_code(422); echo '{"ok":false}'; exit;
  }
  order_set_status($id, $status, $role);
  $refunded = false;
  if ($status === 'cancel') {
    $order = order_find($id);
    if ($order && !empty($order['paid']) && ($order['pay'] ?? '') === 'wallet') {
      $p = norm_phone($order['phone'] ?? '');
      if ($p) { $refunded = (bool)wallet_refund_order($p, (int)$order['total'], $id, $order['num'] ?? 0, $role); }
    }
  }
  echo json_encode(array('ok' => true, 'refunded' => $refunded)); exit;
}

// GET: заказы за последние 24 часа + актуальные статусы
$since = time() - 86400;
$orders = array();
$f = $dir . '/orders.jsonl';
if (is_file($f)) {
  foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && isset($r['ts']) && strtotime($r['ts']) >= $since) {
      unset($r['ip']);
      $r['status'] = 'new';
      $orders[$r['id']] = $r;
    }
  }
}
if (is_file($statusFile)) {
  foreach (file($statusFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && isset($r['id']) && isset($orders[$r['id']])) {
      $orders[$r['id']]['status'] = $r['status'];
      if (!empty($r['by'])) { $orders[$r['id']]['status_by'] = $r['by']; }
    }
  }
}
echo json_encode(array('ok' => true, 'orders' => array_values($orders)), JSON_UNESCAPED_UNICODE);
