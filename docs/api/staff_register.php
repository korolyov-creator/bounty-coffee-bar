<?php
// Регистрация бариста по коду от администратора (инвайт создаёт админ).
// POST {phone, code, name, login?, password?} → {ok, login, password, token, name, pending}
// Бариста сам придумывает логин и пароль (мастер на barista.html);
// если не переданы — генерируются сервером (хранится только bcrypt-хэш).
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

$wantLogin = strtolower(trim((string)($d['login'] ?? '')));
$password = (string)($d['password'] ?? '');
if ($wantLogin !== '') {
  if ($wantLogin === 'admin' || $wantLogin === 'owner' || $wantLogin === '_legacy' || norm_phone($wantLogin)
      || !preg_match('/^[a-z0-9][a-z0-9\-]{2,19}$/', $wantLogin)) {
    respond(['ok' => false, 'reason' => 'bad_login'], 422);
  }
  $acc = store_read('staff_accounts.json');
  if (isset($acc[$wantLogin])) { respond(['ok' => false, 'reason' => 'login_taken'], 409); }
}
if ($password !== '' && (strlen($password) < 8 || strlen($password) > 64)) {
  respond(['ok' => false, 'reason' => 'bad_password'], 422);
}

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

if ($password === '') { $password = gen_password(12); }
$base = translit_login($name);
$created = store_update('staff_accounts.json', function ($data) use ($wantLogin, $base, $name, $phone, $password) {
  if ($wantLogin !== '') {
    if (isset($data[$wantLogin])) { return [$data, null]; }
    $login = $wantLogin;
  } else {
    do { $login = $base . '-' . random_int(100, 999); } while (isset($data[$login]));
  }
  $data[$login] = array(
    'name' => $name, 'phone' => $phone,
    'hash' => password_hash($password, PASSWORD_DEFAULT),
    'status' => 'active', 'created' => date('c'), 'last_login' => '',
  );
  return [$data, $login];
});
if (!$created) { respond(['ok' => false, 'reason' => 'login_taken'], 409); }

// первая сессия после регистрации ждёт подтверждения владельцем
$token = staff_session_create($created, 'barista', $name, false);
audit('staff_register', array('login' => $created, 'phone' => $phone, 'name' => $name));
respond(['ok' => true, 'login' => $created, 'password' => $password, 'token' => $token, 'role' => 'barista', 'name' => $name, 'pending' => true]);
