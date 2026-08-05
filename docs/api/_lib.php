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

// === АУДИТ ===
function audit($ev, $extra = array()) {
  $rec = array_merge(array('ts' => date('c'), 'ev' => $ev, 'ip' => client_ip()), $extra);
  file_put_contents(bdata_path('audit.jsonl'), json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// === АУТЕНТИФИКАЦИЯ ПЕРСОНАЛА ===
// Секреты живут ТОЛЬКО на сервере в bounty_data/auth.json (вне webroot):
// {"admin_hash":"$2y$...","legacy_barista":true,"legacy_hash":"$2y$..."}
function auth_config() { return store_read('auth.json'); }

// Владелец: вход по этому телефону + пароль администратора = роль owner (максимальные права,
// подтверждает вход и регистрацию всего остального персонала, включая админов)
define('OWNER_PHONE', '998998520605');

define('STAFF_SESSION_TTL', 30 * 86400);

// Выдать сессионный токен персонала. $approved=false → сессия ждёт подтверждения владельцем
function staff_session_create($login, $role, $name, $approved = true) {
  $token = bin2hex(random_bytes(24));
  store_update('staff_sessions.json', function ($data) use ($token, $login, $role, $name, $approved) {
    $now = time();
    foreach ($data as $t => $s) { if (($s['exp'] ?? 0) < $now) unset($data[$t]); }
    $data[$token] = array('login' => $login, 'role' => $role, 'name' => $name, 'ts' => $now, 'exp' => $now + STAFF_SESSION_TTL, 'approved' => $approved);
    return [$data, true];
  });
  return $token;
}

function staff_sessions_kill($login) {
  store_update('staff_sessions.json', function ($data) use ($login) {
    foreach ($data as $t => $s) { if (($s['login'] ?? '') === $login) unset($data[$t]); }
    return [$data, true];
  });
}

// Проверка токена → array{login,role,name} | null. Учитывает блокировку бариста.
// Сессии без флага approved (созданные до введения подтверждений) считаются подтверждёнными.
function staff_auth($token, $allowPending = false) {
  $token = (string)$token;
  if (!preg_match('/^[a-f0-9]{48}$/', $token)) { return null; }
  $sessions = store_read('staff_sessions.json');
  $s = $sessions[$token] ?? null;
  if (!$s || ($s['exp'] ?? 0) < time()) { return null; }
  if (!$allowPending && isset($s['approved']) && !$s['approved']) { return null; }
  if ($s['role'] === 'barista' && $s['login'] !== '_legacy') {
    $acc = store_read('staff_accounts.json');
    $st = $acc[$s['login']]['status'] ?? '';
    // pending — зарегистрирован без кода, ждёт «приёма на работу» владельцем
    if ($st !== 'active' && !($allowPending && $st === 'pending')) { return null; }
  }
  if ($s['role'] === 'barista' && $s['login'] === '_legacy') {
    $cfg = auth_config();
    if (empty($cfg['legacy_barista'])) { return null; }
  }
  return $s;
}

// Совместимость: роль по токену ('admin'|'barista'|null)
function staff_role($token) {
  $s = staff_auth($token);
  return $s ? $s['role'] : null;
}

// Рейт-лимит неудачных входов персонала: 15 за час с одного IP
function login_rate_check() {
  $ip = client_ip(); $now = time();
  $fails = store_read('login_fails.json');
  $list = array_filter($fails[$ip] ?? array(), function ($t) use ($now) { return $now - $t < 3600; });
  return count($list) < 15;
}
function login_rate_fail() {
  $ip = client_ip(); $now = time();
  store_update('login_fails.json', function ($data) use ($ip, $now) {
    foreach ($data as $k => $ts) {
      $data[$k] = array_values(array_filter($ts, function ($t) use ($now) { return $now - $t < 3600; }));
      if (!$data[$k]) unset($data[$k]);
    }
    $data[$ip][] = $now;
    return [$data, true];
  });
}

// Генерация креденшлов бариста
function gen_password($len = 12) {
  $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  $p = '';
  for ($i = 0; $i < $len; $i++) { $p .= $alpha[random_int(0, strlen($alpha) - 1)]; }
  return $p;
}

function translit_login($name) {
  $map = array('а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya');
  $s = mb_strtolower(trim($name));
  $out = '';
  for ($i = 0; $i < mb_strlen($s); $i++) {
    $c = mb_substr($s, $i, 1);
    if ($c === ' ' || $c === '-') break;
    if (preg_match('/[a-z0-9]/', $c)) { $out .= $c; }
    elseif (isset($map[$c])) { $out .= $map[$c]; }
  }
  $out = substr($out, 0, 10);
  return $out !== '' ? $out : 'barista';
}

// === БЛОК-ЛИСТ КЛИЕНТОВ ===
// blocklist.json: {"phones":{"998...":{ts,note}},"ids":{"CARD-ID":{ts,note}}}
function is_blocked($phone, $id = '') {
  $b = store_read('blocklist.json');
  if ($phone && isset($b['phones'][$phone])) { return true; }
  if ($id !== '' && isset($b['ids'][$id])) { return true; }
  return false;
}

// Guard для клиентских API: блокированный получает 403 {reason:'blocked'}
function client_guard($phone, $id = '') {
  if (is_blocked($phone, $id)) { respond(['ok' => false, 'reason' => 'blocked', 'error' => 'blocked'], 403); }
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
    // id мог смениться при перевходе (единая сессия) — сверяем с каноном accounts.json и перепривязываем
    $accounts = store_read('accounts.json');
    $canon = isset($accounts[$phone]['id']) ? $accounts[$phone]['id'] : '';
    if ($canon !== '' && $canon === $id) {
      store_update('wallets.json', function ($data) use ($phone, $id) {
        if (isset($data[$phone])) { $data[$phone]['id'] = $id; }
        return [$data, true];
      });
    } else {
      respond(['ok' => false, 'reason' => 'id_mismatch'], 403);
    }
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

// === АБОНЕМЕНТЫ ===
// subs.json: { "998XX": {plan, start, end (Y-m-d), freeze_left, frozen_until, cid, name,
//              used:{"Y-m-d":{by,ts}}, hist:[{t:'sell'|'use'|'freeze'|'unfreeze',...}]} }
function sub_plans() {
  return array(
    'morning'   => array('n' => 'Утро · Месяц', 'days' => 30, 'price' => 349000, 'freeze' => 2,
                         'hours' => '08:00-10:00', 'tag' => '☀️',
                         'desc' => 'Утренний напиток каждое утро с 8:00 до 10:00. Идеально по дороге на работу.'),
    'month'     => array('n' => 'Месяц',   'days' => 30,  'price' => 649000,  'freeze' => 3,
                         'hours' => '', 'tag' => '',
                         'desc' => '1 напиток в день · без ограничений по времени.'),
    'quarter'   => array('n' => 'Квартал', 'days' => 90,  'price' => 1790000, 'freeze' => 10,
                         'hours' => '', 'tag' => '',
                         'desc' => '1 напиток в день · без ограничений по времени.'),
    'half'      => array('n' => 'Полгода', 'days' => 180, 'price' => 3290000, 'freeze' => 20,
                         'hours' => '', 'tag' => '',
                         'desc' => '1 напиток в день · без ограничений по времени.'),
    'year'      => array('n' => 'Год',     'days' => 365, 'price' => 5990000, 'freeze' => 40,
                         'hours' => '', 'tag' => '',
                         'desc' => '1 напиток в день · без ограничений по времени.'),
    'vip'       => array('n' => 'VIP · Год', 'days' => 365, 'price' => 9990000, 'freeze' => 60,
                         'hours' => '', 'tag' => '👑', 'vip' => true,
                         'perks' => array(
                           'До 2 напитков в день (любые, включая фирменный раф Bounty)',
                           'Приоритет в очереди — «VIP» на стакане',
                           'Скидка 20% на всю выпечку и ПП-сэндвичи',
                           'Именной стакан на любимую точку',
                           '60 дней заморозки в подарок',
                         ),
                         'desc' => 'Премиум для тех, кто с нами каждый день.'),
  );
}

// Проверить, попадает ли текущий момент в окно плана (для морнинг-абонемента).
// Возвращает null если ок, или строку с сообщением о запрете.
function sub_hours_check($plan) {
  $p = sub_plans()[$plan] ?? null;
  if (!$p || empty($p['hours'])) { return null; }
  list($from, $to) = array_map('trim', explode('-', $p['hours']));
  $now = date('H:i');
  if ($now < $from || $now >= $to) {
    return $p['hours'];
  }
  return null;
}

function sub_state($s) {
  if (!$s || empty($s['end'])) { return 'none'; }
  $today = date('Y-m-d');
  if (!empty($s['frozen_until']) && $today <= $s['frozen_until']) { return 'frozen'; }
  return ($today <= $s['end']) ? 'active' : 'expired';
}

function sub_days_left($s) {
  if (!$s || empty($s['end'])) { return 0; }
  $d = (strtotime($s['end']) - strtotime(date('Y-m-d'))) / 86400;
  return max(0, (int)round($d) + 1);
}

function sub_public($s) {
  if (!$s) { return null; }
  $today = date('Y-m-d');
  $p = sub_plans()[$s['plan']] ?? null;
  return array(
    'plan' => $s['plan'], 'plan_name' => $p['n'] ?? $s['plan'],
    'end' => $s['end'], 'days_left' => sub_days_left($s), 'state' => sub_state($s),
    'used_today' => isset($s['used'][$today]),
    'frozen_until' => $s['frozen_until'] ?? '', 'freeze_left' => (int)($s['freeze_left'] ?? 0),
    'hours' => $p['hours'] ?? '', 'is_vip' => !empty($p['vip']),
  );
}

// === TELEGRAM-АЛЕРТЫ ВЛАДЕЛЬЦУ ===
// Конфиг: bounty_data/tg_config.json {"token":"...","chat":"263229048","report_key":"..."}
function tg_config() {
  $c = store_read('tg_config.json');
  return (!empty($c['token']) && !empty($c['chat'])) ? $c : null;
}

// Мгновенное уведомление владельцу. Не блокирует ответ клиенту: короткий таймаут, ошибки глотаем.
function tg_alert($text) {
  $c = tg_config();
  if (!$c) { return false; }
  $ch = curl_init('https://api.telegram.org/bot' . $c['token'] . '/sendMessage');
  curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(array('chat_id' => $c['chat'], 'text' => $text)),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
  ));
  curl_exec($ch);
  curl_close($ch);
  return true;
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

// === ДОСТАВКА КОДОВ ВХОДА ЧЕРЕЗ TELEGRAM (@bountycoffeebar_bot) ===
// Самообслуживание: клиент жмёт «Поделиться номером» в боте → api/tg_webhook.php пишет
// привязку в tg_link_map.json {"998XXXXXXXXX": chat_id}. Legacy-пары (RED-бот) — в sms_config tg_otp.
function tg_link_chat($phone) {
  $c = store_read('sms_config.json');
  $map = store_read('tg_link_map.json');
  $bot_token = isset($c['tg_bot_token']) ? (string)$c['tg_bot_token'] : '';
  if ($bot_token !== '' && !empty($map[$phone])) { return array($bot_token, (int)$map[$phone]); }
  $token = isset($c['tg_token']) ? (string)$c['tg_token'] : '';
  $chat = isset($c['tg_otp'][$phone]) ? (int)$c['tg_otp'][$phone] : 0;
  if ($token !== '' && $chat) { return array($token, $chat); }
  return null;
}

// Ссылка на бота для привязки (показывается клиенту, когда SMS недоступна и номер не привязан)
function tg_bot_link() {
  $c = store_read('sms_config.json');
  return !empty($c['tg_bot']) ? 'https://t.me/' . $c['tg_bot'] : '';
}

// Произвольный текст в привязанный Telegram-чат номера (коды входа, инвайты персонала)
function tg_text_send($phone, $text) {
  $lc = tg_link_chat($phone);
  if (!$lc) { return false; }
  list($token, $chat) = $lc;
  $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
  curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(array('chat_id' => $chat, 'text' => $text)),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    // с хостинга первый коннект к api.telegram.org занимал секунды (IPv6/DNS) — пиним IPv4
    CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
  ));
  $r = json_decode(curl_exec($ch), true);
  curl_close($ch);
  return !empty($r['ok']);
}

function tg_otp_send($phone, $code) {
  return tg_text_send($phone, "☕ Bounty Coffee Bar: код для входа {$code}.\n\nВернись в приложение и введи его на экране «Код из SMS». Никому не сообщай код.");
}
