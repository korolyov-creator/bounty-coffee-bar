<?php
// Клиентский endpoint: получить ссылку/deeplink для оплаты заказа.
// POST {order_id, provider: 'click'|'payme'|'uzum'} → {ok:true, url: 'https://...'}
// Приложение переходит на url, клиент оплачивает.

require_once __DIR__ . '/_pay_lib.php';
header('Content-Type: application/json; charset=utf-8');

// Онлайн-эквайринг отключён: только наличный расчёт у бариста.
respond(['ok' => false, 'reason' => 'method_disabled'], 400);

$cfg = pay_provider_config($provider);
if (!$cfg) { respond(['ok' => false, 'reason' => 'provider_not_configured']); }

// найти заказ
$of = bdata_path('orders.jsonl');
$order = null;
if (is_file($of)) {
  foreach (file($of, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && (string)$r['id'] === $order_id) { $order = $r; break; }
  }
}
if (!$order) { respond(['ok' => false, 'reason' => 'order_not_found'], 404); }

$amount = (int)$order['total'];

if ($provider === 'click') {
  // URL на checkout.click.uz
  $url = 'https://my.click.uz/services/pay?service_id=' . urlencode($cfg['service_id'])
       . '&merchant_id=' . urlencode($cfg['merchant_id'])
       . '&amount=' . $amount
       . '&transaction_param=' . urlencode($order_id)
       . '&return_url=' . urlencode('https://bountycoffeebar.uz/app/#order-' . $order_id);
  respond(['ok' => true, 'url' => $url]);
}

if ($provider === 'payme') {
  // Payme checkout: base64 закодированные параметры
  $params = 'm=' . $cfg['merchant_id'] . ';ac.order_id=' . $order_id . ';a=' . ($amount * 100);
  $url = 'https://checkout.paycom.uz/' . base64_encode($params);
  respond(['ok' => true, 'url' => $url]);
}

if ($provider === 'uzum') {
  // Uzum deeplink (открывает приложение Uzum Bank)
  $url = 'https://www.uzumbank.uz/open-service?serviceId=' . urlencode($cfg['service_id'])
       . '&type=order&id=' . urlencode($order_id)
       . '&amount=' . ($amount * 100);
  respond(['ok' => true, 'url' => $url]);
}

respond(['ok' => false, 'reason' => 'unknown_provider'], 400);
