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
  // Выдача заказа: подтверждение кодом выдачи (QR у клиента) либо force с алертом владельцу
  $pkVia = '';
  if ($status === 'done') {
    $order = order_find($id);
    if ($order && !empty($order['pk'])) {
      $pkIn = substr(preg_replace('/\D/', '', (string)($d['pk'] ?? '')), 0, 4);
      if ($pkIn !== '' && hash_equals((string)$order['pk'], $pkIn)) {
        $pkVia = 'qr';
      } elseif (!empty($d['force'])) {
        $pkVia = 'force';
        audit('order_done_force', array('id' => $id, 'num' => $order['num'] ?? 0, 'by' => $sess['name'] ?? $role));
        tg_alert("⚠️ Заказ №" . ($order['num'] ?? '?') . " выдан БЕЗ кода клиента\nСотрудник: " . ($sess['name'] ?? $role) . "\nСумма: " . number_format((int)($order['total'] ?? 0), 0, '', ' ') . " сум");
      } elseif ($pkIn !== '') {
        echo json_encode(array('ok' => false, 'reason' => 'pk_wrong')); exit;
      } else {
        echo json_encode(array('ok' => false, 'reason' => 'pk_required')); exit;
      }
    }
  }
  order_set_status($id, $status, $role . ($pkVia ? ':' . $pkVia : ''));
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
      unset($r['ip']); unset($r['pk']);
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
