<?php
// Click SHOP API endpoint для prepare (action=0) и complete (action=1).
// URL для настройки в кабинете Click: https://bountycoffeebar.uz/api/pay/click.php
// POST — form-encoded, параметры Click.
// Документация: https://docs.click.uz/en/click-shop-api-prepare/ и /click-shop-api-complete/

require_once __DIR__ . '/_pay_lib.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(array('error' => -8, 'error_note' => 'Bad method')); exit; }

$cfg = pay_provider_config('click');
if (!$cfg) { echo json_encode(array('error' => -9, 'error_note' => 'Provider not configured')); exit; }

$p = $_POST ?: (array)json_decode(file_get_contents('php://input'), true);
$action = (int)($p['action'] ?? -1);
$merchant_trans_id = (string)($p['merchant_trans_id'] ?? ''); // = наш order_id
$amount = (float)($p['amount'] ?? 0);
$click_trans_id = (string)($p['click_trans_id'] ?? '');
$error = (int)($p['error'] ?? 0);

// 1) Signature check
if (!pay_signature_check_click($p, $cfg['secret_key'])) {
  echo json_encode(array('error' => -1, 'error_note' => 'SIGN CHECK FAILED'));
  exit;
}

// 2) Order lookup — берём заказ из orders.jsonl
$order = null;
$of = bdata_path('orders.jsonl');
if (is_file($of)) {
  foreach (file($of, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && (string)$r['id'] === $merchant_trans_id) { $order = $r; break; }
  }
}
if (!$order) { echo json_encode(array('error' => -5, 'error_note' => 'Order not found')); exit; }
if ((float)$order['total'] !== $amount) { echo json_encode(array('error' => -2, 'error_note' => 'Incorrect amount')); exit; }

// 3) Действие
if ($action === 0) { // prepare
  // проверка что заказ ещё не оплачен
  $st = store_read('payments_state.json');
  foreach ($st as $tid => $t) {
    if ($t['order_id'] === $merchant_trans_id && $t['state'] === 'paid') {
      echo json_encode(array('error' => -4, 'error_note' => 'Already paid'));
      exit;
    }
  }
  $txn = pay_txn_new('click', $merchant_trans_id, (int)$amount, array('click_trans_id' => $click_trans_id));
  echo json_encode(array(
    'click_trans_id'    => $click_trans_id,
    'merchant_trans_id' => $merchant_trans_id,
    'merchant_prepare_id' => $txn['tid'],
    'error'             => 0,
    'error_note'        => 'Success',
  ));
  exit;
}

if ($action === 1) { // complete
  $prepare_id = (string)($p['merchant_prepare_id'] ?? '');
  $txn = pay_txn_get($prepare_id);
  if (!$txn) { echo json_encode(array('error' => -6, 'error_note' => 'Transaction not found')); exit; }
  if ($error < 0) {
    pay_txn_update($prepare_id, array('state' => 'cancelled', 'click_error' => $error));
    echo json_encode(array(
      'click_trans_id'     => $click_trans_id,
      'merchant_trans_id'  => $merchant_trans_id,
      'merchant_confirm_id' => $prepare_id,
      'error'              => 0,
      'error_note'         => 'Payment cancelled',
    ));
    exit;
  }
  pay_txn_update($prepare_id, array('state' => 'paid'));
  pay_order_paid($merchant_trans_id, $prepare_id, (int)$amount, 'click');
  echo json_encode(array(
    'click_trans_id'      => $click_trans_id,
    'merchant_trans_id'   => $merchant_trans_id,
    'merchant_confirm_id' => $prepare_id,
    'error'               => 0,
    'error_note'          => 'Success',
  ));
  exit;
}

echo json_encode(array('error' => -3, 'error_note' => 'Action not found'));
