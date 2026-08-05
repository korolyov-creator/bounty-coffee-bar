<?php
// Вебхук @bountycoffeebar_bot: клиентский бот кофейни (команды, инфо, привязка номера для кодов входа).
// Аутентификация: заголовок X-Telegram-Bot-Api-Secret-Token == sms_config.tg_webhook_secret.
// Привязки: bounty_data/tg_link_map.json {"998XXXXXXXXX": chat_id}.
require __DIR__ . '/_lib.php';

$c = store_read('sms_config.json');
$secret = isset($c['tg_webhook_secret']) ? (string)$c['tg_webhook_secret'] : '';
$got = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? (string)$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
if ($secret === '' || !hash_equals($secret, $got)) { http_response_code(403); exit; }
$token = isset($c['tg_bot_token']) ? (string)$c['tg_bot_token'] : '';
if ($token === '') { echo 'ok'; exit; }

function tgw_send($token, $chat_id, $text, $markup = null) {
  // Ответ методом в теле HTTP-ответа вебхука: мгновенно для клиента и без
  // исходящего запроса к api.telegram.org (с хостинга он занимает секунды,
  // а его таймауты копили очередь ретраев у Telegram).
  $p = array('method' => 'sendMessage', 'chat_id' => $chat_id, 'text' => $text,
    'disable_web_page_preview' => true);
  if ($markup !== null) { $p['reply_markup'] = $markup; }
  header('Content-Type: application/json');
  echo json_encode($p, JSON_UNESCAPED_UNICODE);
  exit;
}

function tgw_main_kb() {
  return array(
    'keyboard' => array(
      array(array('text' => '📱 Приложение'), array('text' => '🎁 Бонусы')),
      array(array('text' => '🍏 Установка iPhone'), array('text' => '🤖 Установка Android')),
      array(array('text' => '📍 Адреса'), array('text' => '📰 Канал')),
      array(array('text' => '🔑 Код входа'), array('text' => '💬 Помощь')),
    ),
    'resize_keyboard' => true, 'is_persistent' => true,
  );
}

$u = json_decode(file_get_contents('php://input'), true);
$msg = isset($u['message']) ? $u['message'] : null;
if (!$msg) { echo 'ok'; exit; }
$chat_id = isset($msg['chat']['id']) ? (int)$msg['chat']['id'] : 0;
$from_id = isset($msg['from']['id']) ? (int)$msg['from']['id'] : 0;
$type = isset($msg['chat']['type']) ? $msg['chat']['type'] : '';
if (!$chat_id || $type !== 'private') { echo 'ok'; exit; }

$kb_contact = array(
  'keyboard' => array(
    array(array('text' => '📱 Поделиться номером', 'request_contact' => true)),
    array(array('text' => '⬅️ Назад')),
  ),
  'resize_keyboard' => true, 'one_time_keyboard' => true,
);

if (!empty($msg['contact'])) {
  $ct = $msg['contact'];
  $ct_uid = isset($ct['user_id']) ? (int)$ct['user_id'] : 0;
  if ($ct_uid !== $from_id) {
    tgw_send($token, $chat_id, 'Это чужой контакт 🙂 Нажмите кнопку «📱 Поделиться номером» — нужен именно ваш номер.', $kb_contact);
    echo 'ok'; exit;
  }
  $phone = norm_phone(isset($ct['phone_number']) ? $ct['phone_number'] : '');
  if (!$phone) {
    tgw_send($token, $chat_id, 'Поддерживаются только номера Узбекистана (+998). Если ваш номер +998 — напишите нам в приложении.', tgw_main_kb());
    echo 'ok'; exit;
  }
  store_update('tg_link_map.json', function ($m) use ($phone, $chat_id) {
    $m[$phone] = $chat_id;
    return array($m, true);
  });
  audit('tg_link', array('phone' => $phone, 'chat' => $chat_id));
  tgw_send($token, $chat_id,
    "✅ Номер +{$phone} привязан!\n\n" .
    "Теперь коды входа в приложение Bounty будут приходить сюда — SMS не нужна.\n\n" .
    "Вернитесь в приложение и запросите код ещё раз.\n\n" .
    "☕ Новости и акции: @bountycoffeebar_uz",
    tgw_main_kb());
  echo 'ok'; exit;
}

$text = isset($msg['text']) ? trim($msg['text']) : '';
$cmd = mb_strtolower($text, 'UTF-8');
if (strpos($cmd, '/') === 0) { $cmd = preg_replace('/@[a-z0-9_]+$/i', '', strtok($cmd, ' ')); }

$APP_URL = 'https://bountycoffeebar.uz/app/';
$IOS_URL = 'https://testflight.apple.com/join/3z2ayUCS';

if ($cmd === '/start') {
  tgw_send($token, $chat_id,
    "☕ Привет! Это официальный бот кофейни Bounty Coffee Bar — «остров посреди города», Ташкент, 24/7.\n\n" .
    "Что я умею:\n" .
    "📱 Приложение — заказ, предзаказ и бонусы\n" .
    "🍏🤖 Установка — поставить приложение на iPhone или Android\n" .
    "🎁 Бонусы — баллы за заказы и кэшбек до 20% на кошелёк\n" .
    "📍 Адреса — наши точки на карте города\n" .
    "🔑 Код входа — коды для входа в приложение прямо сюда, без SMS\n\n" .
    "Выбирайте кнопку ниже 👇 или команду из меню.",
    tgw_main_kb());
} elseif ($cmd === '/app' || $text === '📱 Приложение') {
  tgw_send($token, $chat_id,
    "📱 Приложение Bounty Coffee Bar:\n\n" .
    "• меню и заказ заранее — заберите без очереди\n" .
    "• карта лояльности: баллы, уровни и кэшбек на кошелёк\n" .
    "• кошелёк и история заказов\n" .
    "• чат с бариста\n\n" .
    "Как установить на телефон — выберите в меню ниже:\n" .
    "🍏 Установка iPhone · 🤖 Установка Android",
    array('inline_keyboard' => array(
      array(array('text' => '🌐 Открыть приложение', 'url' => $APP_URL)),
      array(array('text' => '🍏 iPhone — TestFlight', 'url' => $IOS_URL)),
    )));
} elseif ($cmd === '/iphone' || $text === '🍏 Установка iPhone') {
  tgw_send($token, $chat_id,
    "🍏 Установка на iPhone — два способа:\n\n" .
    "1️⃣ TestFlight — полное приложение:\n" .
    "• установите бесплатный TestFlight (кнопка ниже откроет его автоматически)\n" .
    "• нажмите «Принять» и «Установить» — Bounty появится на экране\n\n" .
    "2️⃣ Без App Store — прямо из Safari:\n" .
    "• откройте bountycoffeebar.uz/app/ в Safari (кнопка ниже)\n" .
    "• нажмите «Поделиться» (квадрат со стрелкой) → «На экран “Домой”»\n" .
    "• иконка Bounty появится как обычное приложение\n\n" .
    "⏳ Версия для App Store уже на проверке Apple — как только выйдет, анонсируем в @bountycoffeebar_uz.",
    array('inline_keyboard' => array(
      array(array('text' => '🛫 Установить через TestFlight', 'url' => $IOS_URL)),
      array(array('text' => '🌐 Открыть в Safari', 'url' => $APP_URL)),
    )));
} elseif ($cmd === '/android' || $text === '🤖 Установка Android') {
  tgw_send($token, $chat_id,
    "🤖 Установка на Android:\n\n" .
    "• откройте bountycoffeebar.uz/app/ в Chrome (кнопка ниже)\n" .
    "• нажмите «Установить приложение» (или меню ⋮ → «Добавить на главный экран»)\n" .
    "• иконка Bounty появится как обычное приложение\n\n" .
    "⏳ Версия для Google Play готовится — анонсируем в @bountycoffeebar_uz.",
    array('inline_keyboard' => array(
      array(array('text' => '🌐 Открыть в Chrome', 'url' => $APP_URL)),
    )));
} elseif ($cmd === '/bonus' || $text === '🎁 Бонусы') {
  tgw_send($token, $chat_id,
    "🎁 Бонусы Bounty:\n\n" .
    "⭐ Баллы за покупки: напиток +10, комбо +15, заказ через приложение +5. Начисляются автоматически.\n" .
    "🏅 Уровни: Бронза → Серебро → Золото → Платина → Бриллиант.\n" .
    "💰 Кэшбек на кошелёк с каждого заказа: от 3% (Бронза) до 20% (Бриллиант).\n" .
    "👛 Кошелёк — пополняйте и платите с баланса.\n\n" .
    "Всё это — в приложении:",
    array('inline_keyboard' => array(
      array(array('text' => '🌐 Открыть приложение', 'url' => $APP_URL)),
    )));
} elseif ($cmd === '/locations' || $text === '📍 Адреса') {
  tgw_send($token, $chat_id,
    "📍 Наши точки в Ташкенте:\n\n" .
    "1️⃣ Хадра (Ц-14) — ул. Хадра, 25 · 24/7\n" .
    "2️⃣ Сайрам — ул. Сайрам, 25/1 · 08:00–20:00\n" .
    "3️⃣ Катта Дархон — ул. Катта Дархон, 15 · скоро открытие ✨",
    array('inline_keyboard' => array(
      array(array('text' => '🗺 Найти на карте', 'url' => 'https://yandex.uz/maps/?text=Bounty%20Coffee%20Bar%20%D0%A2%D0%B0%D1%88%D0%BA%D0%B5%D0%BD%D1%82')),
    )));
} elseif ($cmd === '/channel' || $text === '📰 Канал') {
  tgw_send($token, $chat_id,
    "📰 Наш канал — новости, акции и новинки меню:\n@bountycoffeebar_uz",
    array('inline_keyboard' => array(
      array(array('text' => '➕ Подписаться', 'url' => 'https://t.me/bountycoffeebar_uz')),
    )));
} elseif ($cmd === '/link' || $text === '🔑 Код входа') {
  $map = store_read('tg_link_map.json');
  $linked = '';
  if (is_array($map)) {
    foreach ($map as $ph => $cid) { if ((int)$cid === $chat_id) { $linked = (string)$ph; break; } }
  }
  if ($linked !== '') {
    tgw_send($token, $chat_id,
      "✅ Номер +{$linked} уже привязан — коды входа приходят сюда.\n\n" .
      "Хотите привязать другой номер? Нажмите «📱 Поделиться номером».",
      $kb_contact);
  } else {
    tgw_send($token, $chat_id,
      "🔑 Чтобы получать коды входа в приложение через Telegram (без SMS), поделитесь своим номером — тем же, на который регистрируетесь в приложении.",
      $kb_contact);
  }
} elseif ($cmd === '/help' || $text === '💬 Помощь' || $text === '⬅️ Назад') {
  tgw_send($token, $chat_id,
    "💬 Команды бота:\n\n" .
    "/app — открыть приложение (заказ и бонусы)\n" .
    "/iphone — установить на iPhone (TestFlight или Safari)\n" .
    "/android — установить на Android\n" .
    "/bonus — бонусы: баллы, уровни и кэшбек\n" .
    "/locations — адреса точек\n" .
    "/link — получать коды входа сюда\n" .
    "/channel — канал с новостями\n\n" .
    "Вопросы по заказу — чат с бариста в приложении " . $APP_URL . "\n" .
    "Мы работаем 24/7 ☕",
    tgw_main_kb());
} else {
  tgw_send($token, $chat_id,
    "Я вас не совсем понял 🙂 Выберите кнопку ниже или команду из меню.\n" .
    "Вопросы по заказам — через чат в приложении " . $APP_URL,
    tgw_main_kb());
}
echo 'ok';
