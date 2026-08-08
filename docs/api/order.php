<?php
define('ORDER_PHP_INCLUDED', true); // prevents prices.php from outputting HTTP response when included
require __DIR__ . '/_lib.php';
require __DIR__ . '/prices.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
$raw = file_get_contents('php://input', false, null, 0, 16384);
$d = json_decode($raw, true);
if (!is_array($d) || empty($d['items']) || !is_array($d['items'])) { http_response_code(400); echo '{"ok":false,"error":"bad request"}'; exit; }

$points = array('hadra' => 'Хадра · Ц-14', 'sayram' => 'Сайрам 25/1');
$point = (string)($d['point'] ?? '');
if (!isset($points[$point])) { http_response_code(422); echo '{"ok":false,"error":"bad point"}'; exit; }

// Доверенный клиент: токен входа (otp_verify) совпадает с номером в заказе.
// Только доверенным начисляются баллы/кэшбек — чужой номер без токена накрутить нельзя.
$phoneN = norm_phone($d['phone'] ?? '');
$trusted = false;
$tok = (string)($d['token'] ?? '');
if (preg_match('/^[a-f0-9]{48}$/', $tok)) {
  $tokens = store_read('tokens.json');
  if (isset($tokens[$tok]) && $phoneN && $tokens[$tok]['phone'] === $phoneN) { $trusted = true; }
}

// Идемпотентность: повторный POST того же заказа (даблтап, ретрай сети) возвращает прежний ответ.
// Новые клиенты шлют idem (случайный id на состав корзины); для старых — хэш содержимого с окном 120с.
$idem = substr(preg_replace('/[^a-f0-9]/', '', (string)($d['idem'] ?? '')), 0, 32);
$idemKey = $idem !== ''
  ? 'i:' . $idem
  : 'h:' . hash('sha256', $phoneN . '|' . (string)($d['client_id'] ?? '') . '|' . (string)($d['pay'] ?? '') . '|' . json_encode($d['items']));

// Атомарный O_EXCL файловый лок: защита от race-condition при параллельных дублях (сеть + даблтап).
// Имя lock-файла — sha256(idemKey), чтобы избежать спецсимволов в пути файловой системы.
// Выполняется ДО store_update, чтобы предотвратить двойное списание при одновременных запросах.
$idemLockSafe = hash('sha256', (string)$idemKey);
$idemLockDir  = dirname(__DIR__, 2) . '/bounty_data/order_idem';
$idemLockPath = $idemLockDir . '/' . $idemLockSafe . '.lock';
if (!is_dir($idemLockDir)) { @mkdir($idemLockDir, 0700, true); }
$idemLockHandle = @fopen($idemLockPath, 'x'); // O_EXCL: только один поток создаёт файл
if ($idemLockHandle === false) {
  // Лок занят — проверяем возраст файла
  clearstatcache(true, $idemLockPath);
  $lockAge = time() - (int)@filemtime($idemLockPath);
  if ($lockAge <= 10) {
    // Свежий лок (≤10 сек): параллельный дубль активен — вернуть 409
    http_response_code(409); echo '{"ok":false,"error":"duplicate"}'; exit;
  }
  // Stale-лок (>10 сек): предыдущий запрос упал не почистив себя — удаляем и продолжаем
  @unlink($idemLockPath);
  $idemLockHandle = @fopen($idemLockPath, 'x');
}
if ($idemLockHandle) {
  fwrite($idemLockHandle, (string)getmypid());
  fclose($idemLockHandle);
  register_shutdown_function(static function () use ($idemLockPath): void { @unlink($idemLockPath); });
}

$prev = store_update('order_idem.json', function ($data) use ($idemKey, $idem) {
  $now = time();
  foreach ($data as $k => $v) { if ($now - ($v['ts'] ?? 0) > 86400) { unset($data[$k]); } }
  $e = $data[$idemKey] ?? null;
  if ($e && isset($e['resp']) && ($idem !== '' || $now - $e['ts'] <= 120)) { return [$data, $e['resp']]; }
  $data[$idemKey] = array('ts' => $now);
  return [$data, null];
});
if ($prev) {
  audit('order_idem_hit', array('key' => $idemKey));
  echo json_encode($prev, JSON_UNESCAPED_UNICODE); exit;
}

// Рейт-лимит: не больше 6 заказов в час на номер (или IP, если номера нет)
$rateKey = $phoneN ?: ('ip:' . client_ip());
$rateOk = store_update('order_rate.json', function ($data) use ($rateKey) {
  $now = time();
  foreach ($data as $k => $ts) {
    $data[$k] = array_values(array_filter((array)$ts, function ($t) use ($now) { return $now - $t < 3600; }));
    if (!$data[$k]) { unset($data[$k]); }
  }
  if (count($data[$rateKey] ?? array()) >= 6) { return [$data, false]; }
  $data[$rateKey][] = $now;
  return [$data, true];
});
if (!$rateOk) {
  audit('order_rate_limit', array('key' => $rateKey));
  http_response_code(429); echo '{"ok":false,"error":"rate"}'; exit;
}


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
  $name = mb_substr(trim((string)($i['n'] ?? '')), 0, 60);
  // серверная проверка цены: защита от подмены на клиенте
  $pc = price_check($name, $addons, $unit);
  if ($pc === false) {
    audit('price_reject', array('n' => $name, 'unit' => $unit, 'ip' => $_SERVER['REMOTE_ADDR'] ?? ''));
    tg_alert("🚨 Попытка заказа с ПОДМЕНЁННОЙ ценой\n$name за " . number_format($unit, 0, '', ' ') . " сум\nТел: " . preg_replace('/[^\d+]/', '', (string)($d['phone'] ?? '?')) . "\nЗаказ отклонён.");
    http_response_code(422); echo '{"ok":false,"error":"price"}'; exit;
  }
  if ($pc === null) {
    // позиции нет в серверном прайсе — с ценой клиента не верим, заказ отклоняем (меню и прайс деплоятся вместе)
    audit('price_unknown_reject', array('n' => $name, 'unit' => $unit));
    tg_alert("⚠️ Заказ с позицией вне серверного прайса (рассинхрон меню?)\n$name за " . number_format($unit, 0, '', ' ') . " сум\nЗаказ отклонён — проверь api/prices.php.");
    http_response_code(422); echo '{"ok":false,"error":"price"}'; exit;
  }
  $items[] = array(
    'n'       => $name,
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

// блокировка клиента админом
client_guard(norm_phone($d['phone'] ?? ''), substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($d['client_id'] ?? '')), 0, 32));

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
$pk = (string)random_int(1000, 9999); // код выдачи: клиент показывает QR/цифры, бариста подтверждает

if ($pay === 'wallet') {
  // токен прислан, но чужой/не совпал с номером — списание запрещаем сразу
  if ($tok !== '' && !$trusted) { http_response_code(401); echo '{"ok":false,"error":"bad_token"}'; exit; }
  if (!$trusted) { audit('wallet_pay_no_token', array('phone' => $phoneN)); }
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

// Рассчитываем ожидаемые баллы лояльности для хранения в заказе.
// ВАЖНО: фактическое начисление баллов и кэшбека происходит ТОЛЬКО при выдаче заказа
// через endpoint api/order_mark_done.php (бариста нажимает «Выдать»).
// Это предотвращает начисление за заказы, которые были отменены или не получены.
$ptsPending = 0;
$lphone = norm_phone($d['phone'] ?? '');
if ($lphone && $trusted) {
  $ptsPending = loy_points_for($total);
} elseif ($lphone) {
  // без валидного токена даже pending не ставим: иначе накрутка через curl
  audit('loyalty_skip_untrusted', array('phone' => $lphone));
}

$rec = array(
  'id'          => $id,
  'num'         => $num,
  'ts'          => date('c'),
  'point'       => $point,
  'point_name'  => $points[$point],
  'pickup'      => mb_substr(trim((string)($d['pickup'] ?? '')), 0, 30),
  'client_id'   => substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($d['client_id'] ?? '')), 0, 32),
  'name'        => mb_substr(trim((string)($d['name'] ?? '')), 0, 50),
  'phone'       => preg_replace('/[^\d+]/', '', (string)($d['phone'] ?? '')),
  'items'       => $items,
  'total'       => $total,
  'pay'         => $pay,
  'paid'        => $paid,
  'pk'          => $pk,
  'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
  'status'      => 'pending',
  'pts_pending' => $ptsPending,
  'pts_done'    => false,
);
$ok = file_put_contents($dir . '/orders.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
if ($ok === false) {
  if ($paid) { wallet_refund_order(norm_phone($rec['phone']), $total, $id, $num, 'store_fail'); }
  http_response_code(500); echo '{"ok":false,"error":"store"}'; exit;
}

// Баллы и кэшбек НЕ начисляем здесь — только при выдаче (order_mark_done.php).
// Клиент получит cashback:0 и points_added:0 при оформлении — реальные значения придут
// через accountPull после изменения статуса баристой.
$resp = array('ok' => true, 'id' => $id, 'num' => $num, 'paid' => $paid, 'pk' => $pk, 'cashback' => 0, 'points_added' => 0);
store_update('order_idem.json', function ($data) use ($idemKey, $resp) {
  $data[$idemKey] = array('ts' => time(), 'resp' => $resp);
  return [$data, true];
});
echo json_encode($resp, JSON_UNESCAPED_UNICODE);
