<?php
// Общий слой для платёжных провайдеров (Click / Payme / Uzum).
// Все транзакции в bounty_data/payments.jsonl (append-only) и payments_state.json (индекс).
// Файл нужен для интеграции с order.php когда клиент выбирает онлайн-оплату.

require_once __DIR__ . '/../_lib.php';

function pay_config() {
  // bounty_data/pay_config.json:
  // {
  //   "click":  {"merchant_id":"...","service_id":"...","secret_key":"...","merchant_user_id":"..."},
  //   "payme":  {"merchant_id":"...","secret_key":"..."},
  //   "uzum":   {"service_id":"...","secret_key":"..."}
  // }
  return store_read('pay_config.json');
}
function pay_provider_config($provider) {
  $cfg = pay_config();
  return $cfg[$provider] ?? null;
}

function pay_txn_new($provider, $order_id, $amount, $extra = array()) {
  $tid = bin2hex(random_bytes(12));
  $rec = array_merge(array(
    'tid'      => $tid,
    'provider' => $provider,
    'order_id' => $order_id,
    'amount'   => (int)$amount,
    'state'    => 'pending', // pending | paid | cancelled | refunded | failed
    'created'  => date('c'),
  ), $extra);
  file_put_contents(bdata_path('payments.jsonl'), json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
  store_update('payments_state.json', function ($data) use ($tid, $rec) {
    $data[$tid] = $rec;
    return [$data, true];
  });
  return $rec;
}

function pay_txn_get($tid) {
  $data = store_read('payments_state.json');
  return $data[$tid] ?? null;
}

function pay_txn_update($tid, $patch) {
  return store_update('payments_state.json', function ($data) use ($tid, $patch) {
    if (!isset($data[$tid])) { return [$data, null]; }
    $data[$tid] = array_merge($data[$tid], $patch, array('updated' => date('c')));
    file_put_contents(bdata_path('payments.jsonl'), json_encode(array_merge(array('_patch' => $tid), $patch, array('ts' => date('c'))), JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    return [$data, $data[$tid]];
  });
}

function pay_order_paid($order_id, $tid, $amount, $provider) {
  // Помечаем заказ оплаченным — интеграция с существующей order_status:
  // ставим статус 'new' (если ещё не было) и добавляем meta.paid_online = provider
  $sf = bdata_path('orders_status.jsonl');
  file_put_contents($sf, json_encode(array(
    'id' => $order_id, 'status' => 'new', 'paid_by' => $provider, 'txn' => $tid, 'amount' => (int)$amount, 'ts' => date('c'),
  ), JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
  audit('pay_success', array('order' => $order_id, 'provider' => $provider, 'amount' => $amount, 'tid' => $tid));
  tg_alert("💳 Оплата принята · " . strtoupper($provider) . "\nЗаказ #$order_id · " . number_format($amount, 0, '', ' ') . " сум");
}

function pay_signature_check_click($params, $secret) {
  // Click SHOP API prepare/complete: md5(click_trans_id + service_id + secret_key + merchant_trans_id + amount + action + sign_time)
  $expected = md5(
    ($params['click_trans_id'] ?? '') .
    ($params['service_id'] ?? '') .
    $secret .
    ($params['merchant_trans_id'] ?? '') .
    ($params['amount'] ?? '') .
    ($params['action'] ?? '') .
    ($params['sign_time'] ?? '')
  );
  return isset($params['sign_string']) && hash_equals($expected, $params['sign_string']);
}
