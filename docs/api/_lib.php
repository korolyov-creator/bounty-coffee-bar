<?php
// Общая библиотека API Bounty: хранилище, телефоны, SMS (Eskiz)

define('BOUNTY_DATA', dirname(__DIR__, 2) . '/bounty_data');

function bdata_path($name) {
  if (!is_dir(BOUNTY_DATA)) { mkdir(BOUNTY_DATA, 0700, true); }
  return BOUNTY_DATA . '/' . $name;
}

function json_body($max = 8192) {
  $raw = file_get_contents('php://input', false, null, 0, $max);
  $d = json_decode($raw, true);
  return is_array($d) ? $d : null;
}

function respond($arr, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

// Читает-изменяет-пишет JSON-файл под эксклюзивной блокировкой.
// $fn получает массив, возвращает [новый_массив, результат]
function store_update($name, $fn) {
  $path = bdata_path($name);
  $fp = fopen($path, 'c+');
  if (!$fp) { respond(['ok' => false, 'reason' => 'storage'], 500); }
  flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  $data = json_decode($raw, true);
  if (!is_array($data)) { $data = array(); }
  list($data, $result) = $fn($data);
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  return $result;
}

function store_read($name) {
  $path = bdata_path($name);
  if (!is_file($path)) { return array(); }
  $data = json_decode(file_get_contents($path), true);
  return is_array($data) ? $data : array();
}

// Нормализация узбекского номера к формату 998XXXXXXXXX
function norm_phone($p) {
  $d = preg_replace('/\D/', '', (string)$p);
  if (strlen($d) === 9) { $d = '998' . $d; }
  if (strlen($d) === 12 && substr($d, 0, 3) === '998') { return $d; }
  return null;
}

function client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

// === STAFF (бариста / админ) ===
define('BOUNTY_BARISTA_KEY', 'd1015884097fe84349700f90e7d38d0a');
define('BOUNTY_ADMIN_KEY', '8ea8d889a4058e9f0647f925ca8168de');

// null | 'barista' | 'admin' (админ имеет все права баристы)
function staff_role($key) {
  $key = (string)$key;
  if (hash_equals(BOUNTY_ADMIN_KEY, $key)) { return 'admin'; }
  if (hash_equals(BOUNTY_BARISTA_KEY, $key)) { return 'barista'; }
  return null;
}

// === WALLET ===
// wallets.json: { "998XXXXXXXXX": {id, name, balance, tx:[{t,a,ts,m,o,oid,by}]} }
function wallet_entry(&$data, $phone, $id = '', $name = '') {
  if (!isset($data[$phone])) {
    $data[$phone] = array('id' => $id, 'name' => $name, 'balance' => 0, 'tx' => array());
  }
  if ($id !== '' && empty($data[$phone]['id'])) { $data[$phone]['id'] = $id; }
  if ($name !== '' && empty($data[$phone]['name'])) { $data[$phone]['name'] = $name; }
  return $data[$phone];
}

function wallet_push_tx(&$w, $tx) {
  $w['tx'][] = $tx;
  if (count($w['tx']) > 300) { $w['tx'] = array_slice($w['tx'], -300); }
}

// Пополнение/возврат/корректировка. $amount может быть <0 (корректировка админом).
function wallet_credit($phone, $amount, $type, $meta = array()) {
  return store_update('wallets.json', function ($data) use ($phone, $amount, $type, $meta) {
    $w = wallet_entry($data, $phone, $meta['id'] ?? '', $meta['name'] ?? '');
    $w['balance'] = max(0, (int)$w['balance'] + (int)$amount);
    unset($meta['id'], $meta['name']);
    wallet_push_tx($w, array_merge(array('t' => $type, 'a' => (int)$amount, 'ts' => date('c')), $meta));
    $data[$phone] = $w;
    return [$data, $w];
  });
}

// Возврат за отменённый заказ (однократный — по oid)
function wallet_refund_order($phone, $amount, $oid, $num, $by) {
  return store_update('wallets.json', function ($data) use ($phone, $amount, $oid, $num, $by) {
    if (!isset($data[$phone])) { return [$data, false]; }
    $w = $data[$phone];
    foreach ($w['tx'] as $tx) {
      if (($tx['t'] ?? '') === 'refund' && ($tx['oid'] ?? '') === $oid) { return [$data, false]; }
    }
    $w['balance'] = (int)$w['balance'] + (int)$amount;
    wallet_push_tx($w, array('t' => 'refund', 'a' => (int)$amount, 'ts' => date('c'), 'o' => $num, 'oid' => $oid, 'by' => $by));
    $data[$phone] = $w;
    return [$data, $w];
  });
}

// Клиентская авторизация кошелька: телефон + карта (client_id). Привязка при первом обращении.
function wallet_auth($d) {
  $phone = norm_phone($d['phone'] ?? '');
  $id = substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($d['client_id'] ?? '')), 0, 32);
  if (!$phone || $id === '') { respond(['ok' => false, 'reason' => 'bad_auth'], 401); }
  $wallets = store_read('wallets.json');
  if (isset($wallets[$phone]) && !empty($wallets[$phone]['id']) && $wallets[$phone]['id'] !== $id) {
    respond(['ok' => false, 'reason' => 'id_mismatch'], 403);
  }
  return array($phone, $id);
}

// === ORDERS ===
function order_find($oid) {
  $f = bdata_path('orders.jsonl');
  if (!is_file($f)) { return null; }
  foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && ($r['id'] ?? '') === $oid) { return $r; }
  }
  return null;
}

function order_current_status($oid) {
  $st = 'new';
  $f = bdata_path('orders_status.jsonl');
  if (is_file($f)) {
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $r = json_decode($line, true);
      if (is_array($r) && ($r['id'] ?? '') === $oid) { $st = $r['status']; }
    }
  }
  return $st;
}

function order_set_status($oid, $status, $by = '') {
  $rec = array('id' => $oid, 'status' => $status, 'ts' => date('c'));
  if ($by !== '') { $rec['by'] = $by; }
  file_put_contents(bdata_path('orders_status.jsonl'), json_encode($rec) . "\n", FILE_APPEND | LOCK_EX);
}

// === ONLINE PAYMENTS (Click / Payme / Uzum) ===
// Конфиг появится после регистрации мерчанта: bounty_data/pay_config.json
// {"click":{"service_id":"..","merchant_id":".."},"payme":{"merchant_id":".."},"uzum":{"link":"https://...{amount}...{phone}"}}
function pay_methods() {
  $c = store_read('pay_config.json');
  return array(
    'click' => !empty($c['click']['service_id']),
    'payme' => !empty($c['payme']['merchant_id']),
    'uzum'  => !empty($c['uzum']['link']),
  );
}

function pay_link($method, $amount, $phone) {
  $c = store_read('pay_config.json');
  if ($method === 'payme' && !empty($c['payme']['merchant_id'])) {
    $p = 'm=' . $c['payme']['merchant_id'] . ';ac.phone=' . $phone . ';a=' . ($amount * 100);
    return 'https://checkout.paycom.uz/' . base64_encode($p);
  }
  if ($method === 'click' && !empty($c['click']['service_id'])) {
    return 'https://my.click.uz/services/pay?service_id=' . rawurlencode($c['click']['service_id'])
      . '&merchant_id=' . rawurlencode($c['click']['merchant_id'] ?? '')
      . '&amount=' . $amount . '&transaction_param=' . $phone;
  }
  if ($method === 'uzum' && !empty($c['uzum']['link'])) {
    return str_replace(array('{amount}', '{phone}'), array($amount, $phone), $c['uzum']['link']);
  }
  return null;
}

// === Eskiz.uz ===
// Конфиг: bounty_data/sms_config.json {"provider":"eskiz","email":"...","password":"...","from":"4546"}
function sms_config() {
  $c = store_read('sms_config.json');
  return (isset($c['provider']) && $c['provider'] === 'eskiz' && !empty($c['email'])) ? $c : null;
}

function eskiz_token($cfg, $force = false) {
  $tp = bdata_path('eskiz_token.txt');
  if (!$force && is_file($tp) && time() - filemtime($tp) < 20 * 86400) {
    $t = trim(file_get_contents($tp));
    if ($t !== '') { return $t; }
  }
  $ch = curl_init('https://notify.eskiz.uz/api/auth/login');
  curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(array('email' => $cfg['email'], 'password' => $cfg['password'])),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
  ));
  $r = json_decode(curl_exec($ch), true);
  curl_close($ch);
  $t = $r['data']['token'] ?? '';
  if ($t !== '') { file_put_contents($tp, $t); chmod($tp, 0600); }
  return $t;
}

function sms_send($phone, $text) {
  $cfg = sms_config();
  if (!$cfg) { return false; }
  $token = eskiz_token($cfg);
  if ($token === '') { return false; }
  for ($try = 0; $try < 2; $try++) {
    $ch = curl_init('https://notify.eskiz.uz/api/message/sms/send');
    curl_setopt_array($ch, array(
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => http_build_query(array(
        'mobile_phone' => $phone, 'message' => $text, 'from' => $cfg['from'] ?? '4546')),
      CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $token),
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 || $code === 201) { return true; }
    if ($code === 401 && $try === 0) { $token = eskiz_token($cfg, true); continue; }
    return false;
  }
  return false;
}
