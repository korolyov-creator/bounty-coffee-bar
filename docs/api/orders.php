<?php
// Панель бариста: список заказов (GET) и смена статуса (POST). Доступ по ключу.
header('Content-Type: application/json; charset=utf-8');
$KEY = 'd1015884097fe84349700f90e7d38d0a';
$key = (string)($_GET['key'] ?? '');
if (!hash_equals($KEY, $key)) { http_response_code(403); echo '{"ok":false}'; exit; }

$dir = dirname(__DIR__, 2) . '/bounty_data';
$statusFile = $dir . '/orders_status.jsonl';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $d = json_decode(file_get_contents('php://input', false, null, 0, 1024), true);
  $id = substr(preg_replace('/[^a-f0-9]/', '', (string)($d['id'] ?? '')), 0, 16);
  $status = (string)($d['status'] ?? '');
  if (!$id || !in_array($status, array('new', 'ready', 'done', 'cancel'), true)) {
    http_response_code(422); echo '{"ok":false}'; exit;
  }
  if (!is_dir($dir)) { mkdir($dir, 0700, true); }
  $rec = array('id' => $id, 'status' => $status, 'ts' => date('c'));
  file_put_contents($statusFile, json_encode($rec) . "\n", FILE_APPEND | LOCK_EX);
  echo '{"ok":true}'; exit;
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
    }
  }
}
echo json_encode(array('ok' => true, 'orders' => array_values($orders)), JSON_UNESCAPED_UNICODE);
