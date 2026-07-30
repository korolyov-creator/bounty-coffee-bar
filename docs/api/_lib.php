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
