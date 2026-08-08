<?php
// POST {order_id, staff_token} → бариста отмечает заказ как выданный.
// Только при вызове этого endpoint начисляются баллы и кэшбек клиенту.
// Это предотвращает начисление за отменённые/невыданные заказы (задача Wave 1 #3).
require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo '{"ok":false,"error":"method"}';
  exit;
}

$raw = file_get_contents('php://input', false, null, 0, 16384);
$d = json_decode($raw, true);
$orderId   = preg_replace('/[^a-f0-9]/', '', (string)($d['order_id'] ?? ''));
$staffToken = (string)($d['staff_token'] ?? '');

if (strlen($orderId) !== 16 || $staffToken === '') {
  http_response_code(400);
  echo '{"ok":false,"error":"bad_request"}';
  exit;
}

// Авторизация персонала: staff_token должен быть в tokens.json с role='staff'
$tokens = store_read('tokens.json');
$staffEntry = $tokens[$staffToken] ?? null;
if (!is_array($staffEntry) || ($staffEntry['role'] ?? '') !== 'staff') {
  http_response_code(401);
  echo '{"ok":false,"error":"staff_auth"}';
  exit;
}

$dir = dirname(__DIR__, 2) . '/bounty_data';
$ordersFile = $dir . '/orders.jsonl';

if (!is_file($ordersFile)) {
  http_response_code(404);
  echo '{"ok":false,"error":"order_not_found"}';
  exit;
}

// Читаем заказ из orders.jsonl
$order = null;
$lines = file($ordersFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
  $row = json_decode($line, true);
  if (is_array($row) && ($row['id'] ?? '') === $orderId) {
    $order = $row;
    break;
  }
}

if (!$order) {
  http_response_code(404);
  echo '{"ok":false,"error":"order_not_found"}';
  exit;
}

// Уже выдан через новый механизм
if (!empty($order['pts_done']) && $order['pts_done'] === true && isset($order['pts_added'])) {
  http_response_code(409);
  echo json_encode(array('ok' => false, 'error' => 'already_credited', 'points_added' => (int)$order['pts_added'], 'cashback' => (int)($order['cashback'] ?? 0)));
  exit;
}

// Атомарный claim через order_done.json: предотвращает race-condition при параллельных вызовах
$claim = store_update('order_done.json', function ($data) use ($orderId) {
  if (isset($data[$orderId])) {
    return [$data, false];
  }
  $data[$orderId] = array('ts' => date('c'), 'status' => 'processing');
  return [$data, true];
});

if (!$claim) {
  http_response_code(409);
  echo '{"ok":false,"error":"already_credited"}';
  exit;
}

$earned   = 0;
$cashback = 0;

// Старые заказы с полем pts (старый формат до Wave 1) — начисление уже было, не повторяем
$isLegacy = array_key_exists('pts', $order) || array_key_exists('pts_added', $order);

if (!$isLegacy) {
  $earn  = max(0, (int)($order['pts_pending'] ?? 0));
  $phone = norm_phone($order['phone'] ?? '');

  if ($earn > 0 && $phone) {
    $totalPts = store_update('accounts.json', function ($data) use ($phone, $earn, $order) {
      if (!isset($data[$phone])) { return [$data, null]; }
      $a = $data[$phone];
      $a['total'] = (int)($a['total'] ?? 0) + 1;
      $base = isset($a['points']) ? (int)$a['points']
        : ((int)($a['total'] ?? 0) - 1) * 10 + ((int)($a['free'] ?? 0)) * 50; // миграция со штампов
      $a['points'] = $base + $earn;
      // Запись в историю покупок
      $hist_entry = array('t' => 'buy', 'd' => $order['ts'], 'o' => (int)$order['num'], 'amt' => (int)$order['total'], 'pts' => $earn, 'point' => $order['point_name']);
      $hist  = (array)($a['hist'] ?? array());
      $hkey  = 'buy|' . $order['ts'] . '|' . $order['num'];
      $hkeys = array();
      foreach ($hist as $h) { $hkeys[] = (string)($h['t'] ?? '') . '|' . (string)($h['d'] ?? '') . '|' . (string)($h['o'] ?? ''); }
      if (!in_array($hkey, $hkeys, true)) {
        $hist[] = $hist_entry;
        usort($hist, function ($x, $y) { return strcmp((string)($x['d'] ?? ''), (string)($y['d'] ?? '')); });
        $a['hist'] = array_slice($hist, -200);
      }
      $data[$phone] = $a;
      return [$data, (int)$a['points']];
    });

    if ($totalPts !== null) {
      $earned   = $earn;
      $pct      = loy_cashback_pct($totalPts);
      $cashback = (int)floor((int)($order['total'] ?? 0) * $pct / 100);
      if ($cashback > 0) {
        store_update('wallets.json', function ($data) use ($phone, $order, $cashback, $orderId) {
          $w = wallet_entry($data, $phone, (string)($order['client_id'] ?? ''), (string)($order['name'] ?? ''));
          $w['balance'] = (int)$w['balance'] + $cashback;
          wallet_push_tx($w, array('t' => 'cashback', 'a' => $cashback, 'ts' => date('c'), 'o' => (int)($order['num'] ?? 0), 'oid' => $orderId));
          $data[$phone] = $w;
          return [$data, true];
        });
      }
    }
  }
}

// Обновляем запись заказа в orders.jsonl (под flock, полная перезапись)
$fp = fopen($ordersFile, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
  http_response_code(500);
  echo '{"ok":false,"error":"store"}';
  exit;
}
rewind($fp);
$updatedLines = array();
$found = false;
while (($line = fgets($fp)) !== false) {
  $row = json_decode(trim($line), true);
  if (is_array($row) && ($row['id'] ?? '') === $orderId) {
    $row['status']     = 'done';
    $row['done_ts']    = date('c');
    $row['pts_done']   = true;
    $row['pts_added']  = $earned;
    $row['cashback']   = $cashback;
    $found = true;
  }
  if (is_array($row)) { $updatedLines[] = json_encode($row, JSON_UNESCAPED_UNICODE); }
}
if ($found) {
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, implode("\n", $updatedLines) . "\n");
  fflush($fp);
}
flock($fp, LOCK_UN);
fclose($fp);

// Финализируем claim-запись
store_update('order_done.json', function ($data) use ($orderId, $earned, $cashback) {
  $data[$orderId] = array('ts' => date('c'), 'status' => 'done', 'points_added' => $earned, 'cashback' => $cashback);
  return [$data, true];
});

audit('order_done', array('order_id' => $orderId, 'pts' => $earned, 'cashback' => $cashback, 'staff' => $staffEntry['phone'] ?? ''));

echo json_encode(array(
  'ok'           => true,
  'id'           => $orderId,
  'status'       => 'done',
  'points_added' => $earned,
  'cashback'     => $cashback,
), JSON_UNESCAPED_UNICODE);
