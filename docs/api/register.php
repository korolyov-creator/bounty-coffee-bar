<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
$raw = file_get_contents('php://input', false, null, 0, 4096);
$d = json_decode($raw, true);
if (!is_array($d)) { http_response_code(400); echo '{"ok":false}'; exit; }

$phone = preg_replace('/[^\d+]/', '', (string)($d['phone'] ?? ''));
$name  = mb_substr(trim((string)($d['name'] ?? '')), 0, 50);
if (strlen($phone) < 9 || strlen($phone) > 16 || $name === '') { http_response_code(422); echo '{"ok":false}'; exit; }

$rec = array(
  'ts'        => date('c'),
  'name'      => $name,
  'phone'     => $phone,
  'id'        => substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($d['id'] ?? '')), 0, 32),
  'oferta_v'  => (int)($d['oferta_v'] ?? 0),
  'oferta_ts' => substr((string)($d['oferta_ts'] ?? ''), 0, 32),
  'reg'       => substr((string)($d['reg'] ?? ''), 0, 10),
  'stamps'    => (int)($d['stamps'] ?? 0),
  'total'     => (int)($d['total'] ?? 0),
  'free'      => (int)($d['free'] ?? 0),
  'ip'        => $_SERVER['REMOTE_ADDR'] ?? ''
);

$dir = dirname(__DIR__, 2) . '/bounty_data';
if (!is_dir($dir)) { mkdir($dir, 0700, true); }
$ok = file_put_contents($dir . '/clients.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
echo $ok === false ? '{"ok":false}' : '{"ok":true}';
