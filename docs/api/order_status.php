<?php
header('Content-Type: application/json; charset=utf-8');
$ids = array_filter(array_map(function($s){ return substr(preg_replace('/[^a-f0-9]/', '', $s), 0, 16); },
  explode(',', (string)($_GET['ids'] ?? ''))));
$ids = array_slice($ids, 0, 20);
if (!$ids) { echo '{"ok":true,"statuses":{}}'; exit; }

$dir = dirname(__DIR__, 2) . '/bounty_data';
$statuses = array();
foreach ($ids as $id) { $statuses[$id] = 'new'; }
$f = $dir . '/orders_status.jsonl';
if (is_file($f)) {
  foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && isset($r['id'], $r['status']) && isset($statuses[$r['id']])) {
      $statuses[$r['id']] = $r['status'];
    }
  }
}
echo json_encode(array('ok' => true, 'statuses' => $statuses));
