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
$IOS_URL = 'https://apps.apple.com/uz/app/bounty-coffee-bar/id6796310216';

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
      array(array('text' => '🍏 iPhone — App Store', 'url' => $IOS_URL)),
    )));
} elseif ($cmd === '/iphone' || $text === '🍏 Установка iPhone') {
  tgw_send($token, $chat_id,
    "🍏 Установка на iPhone — два способа:\n\n" .
    "1️⃣ App Store — рекомендуемый способ:\n" .
    "• нажмите кнопку «Установить из App Store» ниже\n" .
    "• App Store откроется на странице Bounty Coffee Bar — жмите «Загрузить»\n" .
    "• приложение появится на экране «Домой»\n\n" .
    "2️⃣ Без App Store — прямо из Safari:\n" .
    "• откройте bountycoffeebar.uz/app/ в Safari (кнопка ниже)\n" .
    "• нажмите «Поделиться» (квадрат со стрелкой) → «На экран “Домой”»\n" .
    "• иконка Bounty появится как обычное приложение.",
    array('inline_keyboard' => array(
      array(array('text' => '🍏 Установить из App Store', 'url' => $IOS_URL)),
      array(array('text' => '🌐 Открыть в Safari', 'url' => $APP_URL)),
    )));
} elseif ($cmd === '/android' || $text === '🤖 Установка Android') {
  tgw_send($token, $chat_id,
    "🤖 Установка на Android:\n\n" .
    "• откройте bountycoffeebar.uz/app/ в Chrome (кнопка ниже)\n" .
    "• нажмите «Установить приложение» (или меню ⋮ → «Добавить на главный экран»)\n" .
    "• иконка Bounty появится как обычное приложение.\n\n" .
    "☕ Все функции доступны сразу: заказ, бонусы, кошелёк, чат с бариста. Версию для Google Play анонсируем в @bountycoffeebar_uz.\n\n" .
    "💡 Хотите попасть в закрытую бету Google Play, когда билд будет готов? Пришлите ваш Gmail сюда — добавим в список (лимит 100).",
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
    "/iphone — установить на iPhone (App Store или Safari)\n" .
    "/android — установить на Android\n" .
    "/bonus — бонусы: баллы, уровни и кэшбек\n" .
    "/locations — адреса точек\n" .
    "/link — получать коды входа сюда\n" .
    "/beta_status — статус в закрытой бете Android\n" .
    "/channel — канал с новостями\n\n" .
    "Есть вопрос? Напишите его прямо сюда — мы ответим.\n" .
    "Вопросы по заказу — быстрее через чат с бариста в приложении " . $APP_URL . "\n" .
    "Мы работаем 24/7 ☕",
    tgw_main_kb());
} elseif ($cmd === '/beta_status') {
  $list = store_read('beta_testers.json');
  $found = null; $pos = 0; $size = is_array($list) ? count($list) : 0;
  if (is_array($list)) {
    $i = 0;
    foreach ($list as $rec) {
      $i++;
      if (isset($rec['chat_id']) && (int)$rec['chat_id'] === $chat_id) { $found = $rec; $pos = $i; break; }
    }
  }
  if ($found !== null) {
    $email = isset($found['email']) ? $found['email'] : '';
    tgw_send($token, $chat_id,
      "✅ Вы в списке закрытой беты Google Play (позиция {$pos} из 100). Ссылка на установку придёт сюда, как только билд будет готов — ориентировочно осень 2026.\n\n" .
      "Ваш Gmail: {$email}",
      tgw_main_kb());
  } else {
    tgw_send($token, $chat_id,
      "Вас в списке беты пока нет. Пришлите ваш Gmail сюда — добавим (лимит 100). Билд Android — ориентировочно осень 2026.",
      tgw_main_kb());
  }
} else {
  // Приоритет 1: пришёл Gmail-адрес — заявка на бету Google Play.
  $gmail = null;
  $has_other_email = false;
  if ($text !== '' && preg_match('/([A-Za-z0-9._%+\-]+)@(gmail|googlemail)\.com/i', $text, $mg)) {
    $gmail = strtolower($mg[1]) . '@' . strtolower($mg[2]) . '.com';
  } elseif ($text !== '' && preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text)) {
    $has_other_email = true;
  }

  if ($gmail !== null) {
    $existing = null; $list_size = 0; $was_new = false; $list_full = false;
    $uname = isset($msg['from']['username']) ? '@' . $msg['from']['username'] : '';
    $fname = isset($msg['from']['first_name']) ? $msg['from']['first_name'] : '';
    $who = trim($fname . ' ' . $uname); if ($who === '') { $who = 'id' . $from_id; }
    $now_ts = time();
    store_update('beta_testers.json', function ($data) use ($gmail, $chat_id, $who, $now_ts, &$existing, &$list_size, &$was_new, &$list_full) {
      if (!is_array($data)) $data = array();
      foreach ($data as $i => $rec) {
        if (isset($rec['email']) && strcasecmp($rec['email'], $gmail) === 0) {
          $existing = $rec;
          $data[$i]['last_seen'] = $now_ts;
          $list_size = count($data);
          return array($data, true);
        }
      }
      if (count($data) >= 100) { $list_size = count($data); $list_full = true; return array($data, false); }
      $data[] = array('email' => $gmail, 'chat_id' => $chat_id, 'from' => $who, 'added_at' => $now_ts, 'last_seen' => $now_ts, 'sent_at' => null);
      $was_new = true; $list_size = count($data);
      return array($data, true);
    });
    if ($list_full && $existing === null) {
      audit('beta_full', array('chat' => $chat_id, 'email' => $gmail));
      tgw_send($token, $chat_id,
        "🚫 Список закрытой беты Google Play уже заполнен (лимит 100). Как только откроем следующий пул — сообщим в @bountycoffeebar_uz.\n\n" .
        "Пока Android можно ставить как PWA: bountycoffeebar.uz/app в Chrome → «Установить приложение».",
        tgw_main_kb());
    } elseif ($existing !== null) {
      audit('beta_dup', array('chat' => $chat_id, 'email' => $gmail));
      tgw_send($token, $chat_id,
        "✅ Ваш Gmail {$gmail} уже в списке закрытой беты Google Play. Ссылка на установку придёт сюда, как только билд будет готов — ориентировочно осень 2026.",
        tgw_main_kb());
    } else {
      tg_alert("💚 Bounty · новый бета-тестер Google Play\n\nEmail: {$gmail}\nОт: {$who}\nchat_id: {$chat_id}\nВ списке: {$list_size}/100");
      audit('beta_new', array('chat' => $chat_id, 'email' => $gmail, 'size' => $list_size));
      tgw_send($token, $chat_id,
        "✅ Ваш Gmail {$gmail} добавлен в список закрытой беты Google Play (позиция {$list_size} из 100).\n\n" .
        "Ссылка на установку придёт сюда, как только билд будет готов — ориентировочно осень 2026.\n\n" .
        "🔒 Email хранится только для приглашения в Google Play и не передаётся третьим лицам.",
        tgw_main_kb());
    }
    echo 'ok'; exit;
  }

  if ($has_other_email) {
    audit('beta_wrong_domain', array('chat' => $chat_id));
    tgw_send($token, $chat_id,
      "Для закрытой беты Android нужен именно Gmail (Google-аккаунт). Другие почтовые сервисы Google Play не поддерживает.\n\n" .
      "Если у вас есть Gmail — пришлите его сюда, добавим в список.",
      tgw_main_kb());
    echo 'ok'; exit;
  }

  // Незнакомое сообщение = клиент задаёт вопрос. Форвардим владельцу с rate-limit 60 сек.
  // Ответ клиенту — подтверждение приёма, а не «я не понял».
  if ($text !== '') {
    $now_ts = time();
    $forward = false;
    store_update('support_ratelimit.json', function ($data) use ($chat_id, $now_ts, &$forward) {
      foreach ($data as $k => $v) { if ($now_ts - (int)$v > 3600) unset($data[$k]); }
      $last = isset($data[$chat_id]) ? (int)$data[$chat_id] : 0;
      if ($now_ts - $last >= 60) {
        $data[$chat_id] = $now_ts;
        $forward = true;
      }
      return [$data, true];
    });
    if ($forward) {
      $uname = isset($msg['from']['username']) ? '@' . $msg['from']['username'] : '';
      $fname = isset($msg['from']['first_name']) ? $msg['from']['first_name'] : '';
      $who = trim($fname . ' ' . $uname);
      if ($who === '') { $who = 'id' . $from_id; }
      $preview = mb_substr($text, 0, 1500);
      tg_alert("💬 Bounty бот · сообщение клиента\n\nОт: {$who}\nchat_id: {$chat_id}\n\n{$preview}");
      audit('tg_client_msg', array('chat' => $chat_id, 'from' => $who, 'len' => mb_strlen($text)));
    }
    tgw_send($token, $chat_id,
      "✅ Ваше сообщение получено — мы ответим сюда как только освободимся.\n\n" .
      "Быстрые действия — на кнопках ниже. " .
      "Вопросы по конкретному заказу быстрее решаются через чат в приложении " . $APP_URL,
      tgw_main_kb());
  } else {
    tgw_send($token, $chat_id,
      "Выберите кнопку ниже 👇 или напишите вопрос текстом — мы ответим.\n" .
      "Вопросы по заказам — через чат в приложении " . $APP_URL,
      tgw_main_kb());
  }
}
echo 'ok';
