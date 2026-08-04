<?php
// Payme Merchant API endpoint (JSON-RPC 2.0).
// URL для настройки в кабинете Payme: https://bountycoffeebar.uz/api/pay/payme.php
// Аутентификация: Basic Paycom:<secret_key> в заголовке Authorization.
// Документация: https://developer.help.paycom.uz/protokol-merchant-api/

require_once __DIR__ . '/_pay_lib.php';
header('Content-Type: application/json; charset=utf-8');

$cfg = pay_provider_config('payme');
if (!$cfg) {
  echo json_encode(array('error' => array('code' => -32603, 'message' => 'Not configured')));
  exit;
}

// Basic-auth check
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($auth, 'Basic ') === 0) {
  $decoded = base64_decode(substr($auth, 6));
  list($user, $pass) = explode(':', $decoded, 2) + array('', '');
  if ($user !== 'Paycom' || !hash_equals($cfg['secret_key'], $pass)) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(array('error' => array('code' => -32504, 'message' => 'Auth failed')));
    exit;
  }
} else {
  header('HTTP/1.1 401 Unauthorized');
  echo json_encode(array('error' => array('code' => -32504, 'message' => 'Auth required')));
  exit;
}

$req = json_decode(file_get_contents('php://input'), true);
$id = $req['id'] ?? null;
$method = $req['method'] ?? '';
$params = $req['params'] ?? array();

function pm_ok($id, $result) { echo json_encode(array('id' => $id, 'result' => $result)); exit; }
function pm_err($id, $code, $msg) { echo json_encode(array('id' => $id, 'error' => array('code' => $code, 'message' => $msg))); exit; }

function pm_order_find($order_id) {
  $of = bdata_path('orders.jsonl');
  if (!is_file($of)) return null;
  foreach (file($of, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && (string)$r['id'] === (string)$order_id) return $r;
  }
  return null;
}

switch ($method) {
  case 'CheckPerformTransaction': {
    $order_id = $params['account']['order_id'] ?? '';
    $o = pm_order_find($order_id);
    if (!$o) { pm_err($id, -31050, 'Order not found'); }
    if (($params['amount'] ?? 0) !== ($o['total'] * 100)) { pm_err($id, -31001, 'Wrong amount'); }
    pm_ok($id, array('allow' => true));
    break;
  }
  case 'CreateTransaction': {
    $order_id = $params['account']['order_id'] ?? '';
    $ptid = $params['id'] ?? ''; // Payme transaction id
    $st = store_read('payments_state.json');
    // ищем существующую по payme_id
    foreach ($st as $tid => $t) {
      if (($t['payme_id'] ?? '') === $ptid) {
        pm_ok($id, array('create_time' => $t['create_time'] ?? (time() * 1000), 'transaction' => $tid, 'state' => 1));
      }
    }
    $o = pm_order_find($order_id);
    if (!$o) { pm_err($id, -31050, 'Order not found'); }
    $create_time = time() * 1000;
    $txn = pay_txn_new('payme', $order_id, $o['total'], array('payme_id' => $ptid, 'create_time' => $create_time, 'state' => 1));
    pm_ok($id, array('create_time' => $create_time, 'transaction' => $txn['tid'], 'state' => 1));
    break;
  }
  case 'PerformTransaction': {
    $ptid = $params['id'] ?? '';
    $st = store_read('payments_state.json');
    $found = null;
    foreach ($st as $tid => $t) if (($t['payme_id'] ?? '') === $ptid) { $found = $t; break; }
    if (!$found) { pm_err($id, -31003, 'Transaction not found'); }
    if (($found['state'] ?? 1) === 2) {
      pm_ok($id, array('perform_time' => $found['perform_time'], 'transaction' => $found['tid'], 'state' => 2));
    }
    $perform_time = time() * 1000;
    pay_txn_update($found['tid'], array('state' => 'paid', 'payme_state' => 2, 'perform_time' => $perform_time));
    pay_order_paid($found['order_id'], $found['tid'], $found['amount'], 'payme');
    pm_ok($id, array('perform_time' => $perform_time, 'transaction' => $found['tid'], 'state' => 2));
    break;
  }
  case 'CancelTransaction': {
    $ptid = $params['id'] ?? '';
    $st = store_read('payments_state.json');
    $found = null;
    foreach ($st as $tid => $t) if (($t['payme_id'] ?? '') === $ptid) { $found = $t; break; }
    if (!$found) { pm_err($id, -31003, 'Transaction not found'); }
    $cancel_time = time() * 1000;
    pay_txn_update($found['tid'], array('state' => 'cancelled', 'payme_state' => -1, 'cancel_time' => $cancel_time, 'reason' => $params['reason'] ?? 0));
    pm_ok($id, array('cancel_time' => $cancel_time, 'transaction' => $found['tid'], 'state' => -1));
    break;
  }
  case 'CheckTransaction': {
    $ptid = $params['id'] ?? '';
    $st = store_read('payments_state.json');
    $found = null;
    foreach ($st as $tid => $t) if (($t['payme_id'] ?? '') === $ptid) { $found = $t; break; }
    if (!$found) { pm_err($id, -31003, 'Transaction not found'); }
    pm_ok($id, array(
      'create_time' => $found['create_time'] ?? 0,
      'perform_time' => $found['perform_time'] ?? 0,
      'cancel_time' => $found['cancel_time'] ?? 0,
      'transaction' => $found['tid'],
      'state' => $found['payme_state'] ?? 1,
      'reason' => $found['reason'] ?? null,
    ));
    break;
  }
  case 'GetStatement': {
    $from = ($params['from'] ?? 0) / 1000;
    $to = ($params['to'] ?? 0) / 1000;
    $st = store_read('payments_state.json');
    $tx = array();
    foreach ($st as $tid => $t) {
      if (($t['provider'] ?? '') !== 'payme') continue;
      $ct = strtotime($t['created']);
      if ($ct >= $from && $ct <= $to) {
        $tx[] = array(
          'id' => $t['payme_id'] ?? '', 'time' => ($t['create_time'] ?? 0),
          'amount' => $t['amount'] * 100, 'account' => array('order_id' => $t['order_id']),
          'create_time' => $t['create_time'] ?? 0,
          'perform_time' => $t['perform_time'] ?? 0,
          'cancel_time' => $t['cancel_time'] ?? 0,
          'transaction' => $t['tid'],
          'state' => $t['payme_state'] ?? 1,
          'reason' => $t['reason'] ?? null,
        );
      }
    }
    pm_ok($id, array('transactions' => $tx));
    break;
  }
  default:
    pm_err($id, -32601, 'Method not found');
}
