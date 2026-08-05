<?php
// POST {token} → безвозвратно удаляет аккаунт номера: accounts, все токены, кошелёк, OTP, аватар.
// Заказы (orders.jsonl) остаются как бизнес-записи. App Store 5.1.1(v) / Google Play account deletion.
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(['ok' => false], 405); }
$d = json_body();
if (!$d) { respond(['ok' => false], 400); }
$token = (string)($d['token'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $token)) { respond(['ok' => false, 'reason' => 'bad_token'], 401); }
$tokens = store_read('tokens.json');
if (!isset($tokens[$token])) { respond(['ok' => false, 'reason' => 'bad_token'], 401); }
$phone = $tokens[$token]['phone'];

store_update('tokens.json', function ($data) use ($phone) {
  foreach ($data as $t => $v) { if (($v['phone'] ?? '') === $phone) { unset($data[$t]); } }
  return [$data, true];
});
$wallet = null;
store_update('wallets.json', function ($data) use ($phone, &$wallet) {
  if (isset($data[$phone])) { $wallet = (int)($data[$phone]['balance'] ?? 0); unset($data[$phone]); }
  return [$data, true];
});
store_update('accounts.json', function ($data) use ($phone) { unset($data[$phone]); return [$data, true]; });
store_update('otp.json', function ($data) use ($phone) { unset($data[$phone]); return [$data, true]; });
$av = bdata_path('avatars') . '/' . $phone . '.txt';
if (is_file($av)) { @unlink($av); }
audit('account_delete', array('phone' => $phone, 'wallet_lost' => $wallet));
respond(['ok' => true]);
