<?php
$KEY = 'fddec01c0ab722e9d71a3764c2a57ad4';
if (!hash_equals($KEY, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }

$f = dirname(__DIR__, 2) . '/bounty_data/clients.jsonl';
if (!is_file($f)) { exit('no data yet'); }

$by = array();
foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
  $r = json_decode($line, true);
  if (is_array($r) && !empty($r['phone'])) { $by[$r['phone']] = $r; }
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bounty_clients.csv"');
echo "\xEF\xBB\xBF";
echo "name,phone,card_id,registered,oferta_v,oferta_accepted,stamps,total_purchases,free_coffees,last_seen\n";
foreach ($by as $r) {
  echo sprintf("%s,%s,%s,%s,%d,%s,%d,%d,%d,%s\n",
    str_replace(array(',', "\n"), ' ', $r['name']),
    $r['phone'], $r['id'] ?? '', $r['reg'] ?? '',
    $r['oferta_v'] ?? 0, $r['oferta_ts'] ?? '',
    $r['stamps'] ?? 0, $r['total'] ?? 0, $r['free'] ?? 0, $r['ts'] ?? '');
}
