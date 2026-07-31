<?php
// Отмена заказа клиентом — только пока бариста не взял его в работу (status='new').
// POST {id, phone, client_id} → {ok, refunded}
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body();
if (!$d) { respond(['ok' => false], 400); }
$oid = substr(preg_replace('/[^a-f0-9]/', '', (string)($d['id'] ?? '')), 0, 16);
if (!$oid) { respond(['ok' => false, 'reason' => 'bad_id'], 422); }

$order = order_find($oid);
if (!$order) { respond(['ok' => false, 'reason' => 'not_found'], 404); }

// заказ должен принадлежать клиенту (по телефону или карте)
$phone = norm_phone($d['phone'] ?? '');
$cid = substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($d['client_id'] ?? '')), 0, 32);
$ownPhone = norm_phone($order['phone'] ?? '');
$owns = ($cid !== '' && $cid === ($order['client_id'] ?? '')) || ($phone && $phone === $ownPhone);
if (!$owns) { respond(['ok' => false, 'reason' => 'not_yours'], 403); }

$st = order_current_status($oid);
if ($st !== 'new') { respond(['ok' => false, 'reason' => 'too_late', 'status' => $st]); }

order_set_status($oid, 'cancel', 'client');

$refunded = false;
if (!empty($order['paid']) && ($order['pay'] ?? '') === 'wallet' && $ownPhone) {
  $refunded = (bool)wallet_refund_order($ownPhone, (int)$order['total'], $oid, $order['num'] ?? 0, 'auto_cancel');
}
respond(['ok' => true, 'refunded' => $refunded]);
