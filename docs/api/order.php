<?php
require __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
$raw = file_get_contents('php://input', false, null, 0, 16384);
$d = json_decode($raw, true);
if (!is_array($d) || empty($d['items']) || !is_array($d['items'])) { http_response_code(400); echo '{"ok":false,"error":"bad request"}'; exit; }

$points = array('hadra' => 'Хадра · Ц-14', 'sayram' => 'Сайрам 25/1');
$point = (string)($d['point'] ?? '');
if (!isset($points[$point])) { http_response_code(422); echo '{"ok":false,"error":"bad point"}'; exit; }

$items = array();
$total = 0;
foreach (array_slice($d['items'], 0, 20) as $i) {
  if (!is_array($i)) continue;
  $qty  = max(1, min(9, (int)($i['qty'] ?? 1)));
  $unit = max(0, min(2000000, (int)($i['unit'] ?? 0)));
  $addons = array();
  if (isset($i['addons']) && is_array($i['addons'])) {
    foreach (array_slice($i['addons'], 0, 6) as $a) { $addons[] = mb_substr(trim((string)$a), 0, 40); }
  }
  $items[] = array(
    'n'       => mb_substr(trim((string)($i['n'] ?? '')), 0, 60),
    'size'    => mb_substr(trim((string)($i['size'] ?? '')), 0, 30),
    'qty'     => $qty,
    'addons'  => $addons,
    'comment' => mb_substr(trim((string)($i['comment'] ?? '')), 0, 120),
    'unit'    => $unit
  );
  $total += $unit * $qty;
}
if (!$items || $total <= 0) { http_response_code(422); echo '{"ok":false,"error":"empty"}'; exit; }

$dir = dirname(__DIR__, 2) . '/bounty_data';
if (!is_dir($dir)) { mkdir($dir, 0700, true); }

// Способ оплаты: pickup (при получении) | wallet (списание с кошелька при оформлении)
$pay = (string)($d['pay'] ?? 'pickup');
if (!in_array($pay, array('pickup', 'wallet'), true)) { $pay = 'pickup'; }
$paid = false;

// дневной номер заказа: счётчик под flock
$day = date('Y-m-d');
$cf = fopen($dir . '/order_counter.txt', 'c+');
flock($cf, LOCK_EX);
$cur = trim(stream_get_contents($cf));
list($cday, $cnum) = array_pad(explode(':', $cur), 2, '');
$num = ($cday === $day) ? ((int)$cnum + 1) : 1;
ftruncate($cf, 0); rewind($cf); fwrite($cf, $day . ':' . $num);
flock($cf, LOCK_UN); fclose($cf);

$id = bin2hex(random_bytes(8));

if ($pay === 'wallet') {
  list($wphone, $wid) = wallet_auth($d);
  $res = store_update('wallets.json', function ($data) use ($wphone, $wid, $total, $id, $num, $d) {
    $w = wallet_entry($data, $wphone, $wid, mb_substr(trim((string)($d['name'] ?? '')), 0, 50));
    if ((int)$w['balance'] < $total) { return [$data, array('err' => 'insufficient', 'balance' => (int)$w['balance'])]; }
    $w['balance'] = (int)$w['balance'] - $total;
    wallet_push_tx($w, array('t' => 'pay', 'a' => -$total, 'ts' => date('c'), 'o' => $num, 'oid' => $id));
    $data[$wphone] = $w;
    return [$data, array('balance' => (int)$w['balance'])];
  });
  if (isset($res['err'])) { echo json_encode(array('ok' => false, 'error' => 'insufficient', 'balance' => $res['balance'])); exit; }
  $paid = true;
}

$rec = array(
  'id'         => $id,
  'num'        => $num,
  'ts'         => date('c'),
  'point'      => $point,
  'point_name' => $points[$point],
  'pickup'     => mb_substr(trim((string)($d['pickup'] ?? '')), 0, 30),
  'client_id'  => substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($d['client_id'] ?? '')), 0, 32),
  'name'       => mb_substr(trim((string)($d['name'] ?? '')), 0, 50),
  'phone'      => preg_replace('/[^\d+]/', '', (string)($d['phone'] ?? '')),
  'items'      => $items,
  'total'      => $total,
  'pay'        => $pay,
  'paid'       => $paid,
  'ip'         => $_SERVER['REMOTE_ADDR'] ?? ''
);
$ok = file_put_contents($dir . '/orders.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
if ($ok === false) {
  if ($paid) { wallet_refund_order(norm_phone($rec['phone']), $total, $id, $num, 'store_fail'); }
  http_response_code(500); echo '{"ok":false,"error":"store"}'; exit;
}
echo json_encode(array('ok' => true, 'id' => $id, 'num' => $num, 'paid' => $paid));
