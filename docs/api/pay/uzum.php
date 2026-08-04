<?php
// Uzum Bank Merchants — callback endpoint.
// Клиент открывает deeplink → оплачивает в приложении Uzum Bank → Uzum шлёт callback сюда.
// URL для настройки: https://bountycoffeebar.uz/api/pay/uzum.php
// Документация: https://developer.uzumbank.uz/en/merchant/

require_once __DIR__ . '/_pay_lib.php';
header('Content-Type: application/json; charset=utf-8');

$cfg = pay_provider_config('uzum');
if (!$cfg) { echo json_encode(array('ok' => false, 'error' => 'not_configured')); exit; }

$req = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// Uzum шлёт: order_id, transaction_id, amount, status, signature
$order_id = (string)($req['order_id'] ?? '');
$transaction_id = (string)($req['transaction_id'] ?? '');
$amount = (int)($req['amount'] ?? 0);
$status = (string)($req['status'] ?? '');
$signature = (string)($req['signature'] ?? '');

// Подпись: sha256(secret_key + order_id + transaction_id + amount + status)
$expected = hash('sha256', $cfg['secret_key'] . $order_id . $transaction_id . $amount . $status);
if (!hash_equals($expected, $signature)) {
  echo json_encode(array('ok' => false, 'error' => 'bad_signature'));
  exit;
}

// Amount у Uzum — в тийинах (сум × 100)
$sum_amount = intval($amount / 100);

if ($status === 'SUCCESS' || $status === 'paid') {
  $st = store_read('payments_state.json');
  foreach ($st as $tid => $t) {
    if (($t['uzum_txn'] ?? '') === $transaction_id) { echo json_encode(array('ok' => true, 'already' => true)); exit; }
  }
  $txn = pay_txn_new('uzum', $order_id, $sum_amount, array('uzum_txn' => $transaction_id, 'state' => 'paid'));
  pay_order_paid($order_id, $txn['tid'], $sum_amount, 'uzum');
  echo json_encode(array('ok' => true));
  exit;
}

if ($status === 'CANCELLED' || $status === 'cancelled') {
  // ищем и помечаем
  $st = store_read('payments_state.json');
  foreach ($st as $tid => $t) {
    if (($t['uzum_txn'] ?? '') === $transaction_id) {
      pay_txn_update($tid, array('state' => 'cancelled'));
      echo json_encode(array('ok' => true, 'cancelled' => true));
      exit;
    }
  }
}

echo json_encode(array('ok' => false, 'error' => 'unknown_status'));
