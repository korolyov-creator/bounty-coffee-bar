<?php
// POST {type, name, contact, text, phone?, client_id?} → сохраняет обращение и уведомляет владельца в TG.
require __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body(8192);
if (!$d) { respond(['ok' => false], 400); }

$type = (string)($d['type'] ?? 'other');
$allow = array('suggestion', 'complaint', 'partnership', 'job', 'other');
if (!in_array($type, $allow, true)) { $type = 'other'; }

$name = mb_substr(trim((string)($d['name'] ?? '')), 0, 60);
$contact = mb_substr(trim((string)($d['contact'] ?? '')), 0, 60);
$text = mb_substr(trim((string)($d['text'] ?? '')), 0, 2000);
$phone = norm_phone($d['phone'] ?? '') ?: '';
$cid = substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($d['client_id'] ?? '')), 0, 32);

if ($name === '' || mb_strlen($text) < 5) { respond(['ok' => false, 'reason' => 'bad_input'], 422); }

$ip = client_ip();
$rec = array(
  'ts' => date('c'),
  'type' => $type,
  'name' => $name,
  'contact' => $contact,
  'text' => $text,
  'phone' => $phone,
  'client_id' => $cid,
  'ip' => $ip,
);

// rate-limit: 5 обращений с IP в час
$ok_rl = store_update('feedback_rate.json', function ($data) use ($ip) {
  $now = time();
  foreach ($data as $k => $ts) { $data[$k] = array_values(array_filter($ts, function ($t) use ($now) { return $now - $t < 3600; })); if (!$data[$k]) unset($data[$k]); }
  if (count($data[$ip] ?? []) >= 5) { return [$data, false]; }
  $data[$ip][] = $now;
  return [$data, true];
});
if (!$ok_rl) { respond(['ok' => false, 'reason' => 'rate_limit'], 429); }

$fp = bdata_path('feedback.jsonl');
file_put_contents($fp, json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

$type_labels = array(
  'suggestion' => '💡 Пожелание / идея',
  'complaint' => '⚠️ Жалоба',
  'partnership' => '🤝 Сотрудничество / франшиза',
  'job' => '💼 Хочу работать',
  'other' => '📝 Другое',
);
$msg = "📩 <b>Новое обращение в приложении</b>\n\n"
  . "<b>Тип:</b> " . ($type_labels[$type] ?? $type) . "\n"
  . "<b>Имя:</b> " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "\n"
  . ($contact !== '' ? "<b>Контакт:</b> " . htmlspecialchars($contact, ENT_QUOTES, 'UTF-8') . "\n" : '')
  . ($phone !== '' ? "<b>Телефон аккаунта:</b> +" . $phone . "\n" : '')
  . "\n" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
tg_alert($msg);

audit('feedback', array('type' => $type, 'name' => $name, 'phone' => $phone));
respond(['ok' => true]);
