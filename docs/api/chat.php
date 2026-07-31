<?php
// Чат клиент ↔ бариста. Одна нить на клиента (по телефону).
// Клиент: POST {phone, client_id, action:'get'|'send', text?, since?}
// chat.jsonl: {phone, from:'client'|'staff', text, ts, name?}
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body();
if (!$d) { respond(['ok' => false], 400); }
list($phone, $id) = wallet_auth($d);
$action = (string)($d['action'] ?? 'get');

if ($action === 'send') {
  $text = mb_substr(trim((string)($d['text'] ?? '')), 0, 500);
  if ($text === '') { respond(['ok' => false, 'reason' => 'empty'], 422); }
  $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 50);
  $rec = array('phone' => $phone, 'from' => 'client', 'text' => $text, 'ts' => date('c'), 'name' => $name);
  file_put_contents(bdata_path('chat.jsonl'), json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
  respond(['ok' => true]);
}

// get: последние 60 сообщений своей нити
$msgs = array();
$f = bdata_path('chat.jsonl');
if (is_file($f)) {
  foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && ($r['phone'] ?? '') === $phone) { $msgs[] = $r; }
  }
}
respond(['ok' => true, 'msgs' => array_slice($msgs, -60)]);
