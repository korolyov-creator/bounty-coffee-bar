<?php
// Регистрация бариста по коду из SMS (инвайт создаёт админ).
// POST {phone, code, name} → {ok, login, password, token, name}
// Логин и пароль генерируются сервером, пароль показывается ОДИН раз (хранится только bcrypt-хэш).
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body();
if (!$d) { respond(['ok' => false], 400); }
usleep(300000);
if (!login_rate_check()) { respond(['ok' => false, 'reason' => 'rate_limit'], 429); }

$phone = norm_phone($d['phone'] ?? '');
$code = preg_replace('/\D/', '', (string)($d['code'] ?? ''));
$name = mb_substr(trim((string)($d['name'] ?? '')), 0, 50);
if (!$phone || strlen($code) !== 6 || $name === '') { respond(['ok' => false, 'reason' => 'bad_input'], 422); }

$res = store_update('staff_invites.json', function ($data) use ($phone, $code) {
  $e = $data[$phone] ?? null;
  if (!$e || empty($e['h'])) { return [$data, 'no_invite']; }
  if (time() > ($e['exp'] ?? 0)) { unset($data[$phone]); return [$data, 'expired']; }
  $e['tries'] = ($e['tries'] ?? 0) + 1;
  if ($e['tries'] > 5) { unset($data[$phone]); return [$data, 'too_many_tries']; }
  if (!hash_equals($e['h'], hash('sha256', $phone . ':' . $code))) { $data[$phone] = $e; return [$data, 'wrong_code']; }
  unset($data[$phone]);
  return [$data, 'ok'];
});
if ($res !== 'ok') { login_rate_fail(); respond(['ok' => false, 'reason' => $res], 401); }

$password = gen_password(12);
$base = translit_login($name);
$created = store_update('staff_accounts.json', function ($data) use ($base, $name, $phone, $password) {
  do { $login = $base . '-' . random_int(100, 999); } while (isset($data[$login]));
  $data[$login] = array(
    'name' => $name, 'phone' => $phone,
    'hash' => password_hash($password, PASSWORD_DEFAULT),
    'status' => 'active', 'created' => date('c'), 'last_login' => '',
  );
  return [$data, $login];
});

$token = staff_session_create($created, 'barista', $name);
audit('staff_register', array('login' => $created, 'phone' => $phone, 'name' => $name));
respond(['ok' => true, 'login' => $created, 'password' => $password, 'token' => $token, 'role' => 'barista', 'name' => $name]);
