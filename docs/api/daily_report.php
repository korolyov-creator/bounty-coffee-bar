<?php
// Ежедневная сводка владельцу в Telegram (вызывается cron'ом).
// GET ?key=... — ключ в bounty_data/tg_config.json {"report_key":"..."}
require __DIR__ . '/_lib.php';
$cfg = tg_config();
if (!$cfg || empty($cfg['report_key']) || !hash_equals($cfg['report_key'], (string)($_GET['key'] ?? ''))) {
  http_response_code(403); exit('forbidden');
}

$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) ? $_GET['day'] : date('Y-m-d');
$fromTs = strtotime($day . ' 00:00:00');
$toTs = strtotime($day . ' 23:59:59');

$statuses = array(); $doneVia = array();
$sf = bdata_path('orders_status.jsonl');
if (is_file($sf)) {
  foreach (file($sf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && isset($r['id'], $r['status'])) {
      $statuses[$r['id']] = $r['status'];
      if ($r['status'] === 'done') {
        $by = (string)($r['by'] ?? '');
        $doneVia[$r['id']] = (strpos($by, ':qr') !== false) ? 'qr' : ((strpos($by, ':force') !== false) ? 'force' : 'old');
      }
    }
  }
}

$doneCash = 0; $doneWallet = 0; $doneN = 0; $cancelN = 0; $qrN = 0; $forceN = 0;
$of = bdata_path('orders.jsonl');
if (is_file($of)) {
  foreach (file($of, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $o = json_decode($line, true);
    if (!is_array($o) || !isset($o['ts'])) continue;
    $ts = strtotime($o['ts']);
    if ($ts < $fromTs || $ts > $toTs) continue;
    $st = $statuses[$o['id']] ?? 'new';
    if ($st === 'done') {
      $doneN++;
      if (!empty($o['paid'])) { $doneWallet += (int)$o['total']; } else { $doneCash += (int)$o['total']; }
      $via = $doneVia[$o['id']] ?? 'old';
      if ($via === 'qr') { $qrN++; } elseif ($via === 'force') { $forceN++; }
    }
    if ($st === 'cancel') { $cancelN++; }
  }
}

$topupByStaff = array(); $topupCashSum = 0; $topupCashN = 0; $directs = array();
$adjusts = array(); $refunds = 0; $paySum = 0; $walletTotal = 0; $nofiscalN = 0;
$wallets = store_read('wallets.json');
foreach ($wallets as $phone => $w) {
  $walletTotal += (int)($w['balance'] ?? 0);
  foreach (($w['tx'] ?? array()) as $tx) {
    $ts = strtotime($tx['ts'] ?? '');
    if (!$ts || $ts < $fromTs || $ts > $toTs) continue;
    $t = $tx['t'] ?? '';
    $a = (int)($tx['a'] ?? 0);
    $by = $tx['by'] ?? '?';
    if ($t === 'topup') {
      $topupByStaff[$by] = ($topupByStaff[$by] ?? array('n' => 0, 'sum' => 0));
      $topupByStaff[$by]['n']++;
      $topupByStaff[$by]['sum'] += $a;
      if (($tx['m'] ?? 'cash') === 'cash') { $topupCashSum += $a; $topupCashN++; }
      if (empty($tx['f'])) { $nofiscalN++; }
      if (!empty($tx['direct'])) { $directs[] = "$by → $phone: " . number_format($a, 0, '', ' '); }
    }
    if ($t === 'pay') { $paySum += -$a; }
    if ($t === 'refund') { $refunds += $a; }
    if ($t === 'adjust') { $adjusts[] = "$by → $phone: " . ($a > 0 ? '+' : '') . number_format($a, 0, '', ' ') . (!empty($tx['note']) ? ' (' . $tx['note'] . ')' : ''); }
  }
}

$f = function ($n) { return number_format($n, 0, '', ' '); };
$msg = "📊 Bounty · сводка за " . date('d.m.Y', $fromTs) . "\n\n";
$msg .= "☕ Выдано заказов: $doneN (отменено: $cancelN)\n";
if ($doneN > 0) {
  $msg .= "🔑 По коду/QR клиента: $qrN";
  if ($forceN > 0) { $msg .= " · ⚠️ БЕЗ кода: $forceN"; }
  $msg .= "\n";
}
$msg .= "💵 Выручка касса: " . $f($doneCash) . " сум\n";
$msg .= "👛 Выручка кошелёк: " . $f($doneWallet) . " сум\n\n";
$msg .= "ПОПОЛНЕНИЯ КОШЕЛЬКОВ:\n";
if ($topupByStaff) {
  foreach ($topupByStaff as $by => $v) { $msg .= "· $by: " . $v['n'] . " шт · " . $f($v['sum']) . " сум\n"; }
} else { $msg .= "· не было\n"; }
$msg .= "\n🧾 СВЕРКА КАССЫ: наличных в кассе должно быть " . $f($doneCash + $topupCashSum) . " сум\n";
$msg .= "(продажи " . $f($doneCash) . " + пополнения наличными " . $f($topupCashSum) . ")\n";
$msg .= "Чеков «Аванс» на ККМ должно быть: $topupCashN шт\n";
if ($nofiscalN > 0) { $msg .= "⛔ Пополнений БЕЗ отметки о чеке: $nofiscalN\n"; }
if ($directs) { $msg .= "\n⚠️ ПРЯМЫЕ зачисления (без кода клиента):\n· " . implode("\n· ", $directs) . "\n"; }
if ($adjusts) { $msg .= "\n✏️ Корректировки:\n· " . implode("\n· ", $adjusts) . "\n"; }
if ($refunds > 0) { $msg .= "\n↩️ Возвраты на кошельки: " . $f($refunds) . " сум\n"; }
$msg .= "\n👛 Остаток на всех кошельках: " . $f($walletTotal) . " сум (обязательства)";

tg_alert($msg);
audit('daily_report', array('day' => $day));
respond(['ok' => true]);
