<?php
// Полный отчёт для бухгалтера по всем движениям (только админ).
// GET ?token=...&from=YYYY-MM-DD&to=YYYY-MM-DD → CSV (журнал операций + итоги)
require __DIR__ . '/_lib.php';
$sess = staff_auth($_GET['token'] ?? '');
if (!$sess || $sess['role'] !== 'admin') { http_response_code(403); exit('forbidden'); }

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$fromTs = strtotime($from . ' 00:00:00');
$toTs   = strtotime($to . ' 23:59:59');

function cell($s) { return str_replace(array(';', "\n", "\r"), array(',', ' ', ' '), (string)$s); }

$stName = array('new' => 'Новый', 'making' => 'В работе', 'ready' => 'Готов', 'done' => 'Выдан', 'cancel' => 'Отменён');
$txName = array('topup' => 'Пополнение кошелька', 'pay' => 'Оплата с кошелька', 'refund' => 'Возврат на кошелёк', 'adjust' => 'Корректировка (админ)');
$mName = array('cash' => 'наличные/касса', 'click' => 'Click', 'payme' => 'Payme', 'uzum' => 'Uzum');

// статусы заказов
$statuses = array();
$sf = bdata_path('orders_status.jsonl');
if (is_file($sf)) {
  foreach (file($sf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && isset($r['id'], $r['status'])) { $statuses[$r['id']] = $r['status']; }
  }
}

$rows = array(); // [ts, type, num, name, phone, point, amount, method, status, by, note]
$sum = array('done_cash' => 0, 'done_wallet' => 0, 'done_n' => 0, 'cancel_n' => 0,
  'topup' => 0, 'pay' => 0, 'refund' => 0, 'adj_plus' => 0, 'adj_minus' => 0);
$topupByMethod = array();

// заказы
$of = bdata_path('orders.jsonl');
if (is_file($of)) {
  foreach (file($of, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $o = json_decode($line, true);
    if (!is_array($o) || !isset($o['ts'])) continue;
    $ts = strtotime($o['ts']);
    if ($ts < $fromTs || $ts > $toTs) continue;
    $st = $statuses[$o['id']] ?? 'new';
    $items = array();
    foreach (($o['items'] ?? array()) as $i) { $items[] = $i['qty'] . 'x ' . $i['n']; }
    $rows[] = array($ts, 'Предзаказ', '№' . ($o['num'] ?? ''), $o['name'] ?? '', $o['phone'] ?? '',
      $o['point_name'] ?? '', (int)($o['total'] ?? 0),
      !empty($o['paid']) ? 'кошелёк' : 'касса при получении',
      $stName[$st] ?? $st, '', implode(', ', $items));
    if ($st === 'done') {
      $sum['done_n']++;
      if (!empty($o['paid'])) { $sum['done_wallet'] += (int)$o['total']; } else { $sum['done_cash'] += (int)$o['total']; }
    }
    if ($st === 'cancel') { $sum['cancel_n']++; }
  }
}

// операции по кошелькам
$wallets = store_read('wallets.json');
$walletTotal = 0;
foreach ($wallets as $phone => $w) {
  $walletTotal += (int)($w['balance'] ?? 0);
  foreach (($w['tx'] ?? array()) as $tx) {
    $ts = strtotime($tx['ts'] ?? '');
    if (!$ts || $ts < $fromTs || $ts > $toTs) continue;
    $t = $tx['t'] ?? '';
    $a = (int)($tx['a'] ?? 0);
    $rows[] = array($ts, $txName[$t] ?? $t, isset($tx['o']) && $tx['o'] ? '№' . $tx['o'] : '',
      $w['name'] ?? '', $phone, '', $a,
      isset($tx['m']) ? ($mName[$tx['m']] ?? $tx['m']) : '',
      '', $tx['by'] ?? '', $tx['note'] ?? '');
    if ($t === 'topup') { $sum['topup'] += $a; $mk = $tx['m'] ?? 'cash'; $topupByMethod[$mk] = ($topupByMethod[$mk] ?? 0) + $a; }
    if ($t === 'pay') { $sum['pay'] += -$a; }
    if ($t === 'refund') { $sum['refund'] += $a; }
    if ($t === 'adjust') { if ($a >= 0) { $sum['adj_plus'] += $a; } else { $sum['adj_minus'] += -$a; } }
  }
}

usort($rows, function ($a, $b) { return $a[0] - $b[0]; });

audit('report', array('by' => 'admin', 'from' => $from, 'to' => $to));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bounty_otchet_' . $from . '_' . $to . '.csv"');
echo "\xEF\xBB\xBF";
echo "BOUNTY COFFEE BAR — отчёт по движениям за период " . $from . " — " . $to . "\n";
echo "Сформирован;" . date('Y-m-d H:i') . "\n\n";
echo "Дата;Время;Операция;Заказ;Клиент;Телефон;Точка;Сумма (сум);Способ;Статус;Кем;Примечание\n";
foreach ($rows as $r) {
  echo date('d.m.Y', $r[0]) . ';' . date('H:i', $r[0]) . ';' . cell($r[1]) . ';' . cell($r[2]) . ';'
    . cell($r[3]) . ';' . ($r[4] !== '' ? "+" . preg_replace('/\D/', '', $r[4]) : '') . ';' . cell($r[5]) . ';'
    . $r[6] . ';' . cell($r[7]) . ';' . cell($r[8]) . ';' . cell($r[9]) . ';' . cell($r[10]) . "\n";
}
echo "\nИТОГИ ЗА ПЕРИОД\n";
echo "Выдано предзаказов;" . $sum['done_n'] . "\n";
echo "Выручка предзаказов — оплата на кассе;" . $sum['done_cash'] . "\n";
echo "Выручка предзаказов — оплата с кошелька;" . $sum['done_wallet'] . "\n";
echo "Выручка предзаказов всего;" . ($sum['done_cash'] + $sum['done_wallet']) . "\n";
echo "Отменено заказов;" . $sum['cancel_n'] . "\n";
echo "Пополнения кошельков всего;" . $sum['topup'] . "\n";
foreach ($topupByMethod as $m => $v) { echo "  в т.ч. " . cell($mName[$m] ?? $m) . ";" . $v . "\n"; }
echo "Списано с кошельков за заказы;" . $sum['pay'] . "\n";
echo "Возвраты на кошельки;" . $sum['refund'] . "\n";
echo "Корректировки админом (+);" . $sum['adj_plus'] . "\n";
echo "Корректировки админом (−);" . $sum['adj_minus'] . "\n";
echo "\nОстаток на всех кошельках клиентов (обязательства) на " . date('d.m.Y H:i') . ";" . $walletTotal . "\n";
