<?php
// Серверный прайс-лист — единственный источник правды по ценам.
// Держать в синхроне с MENU/ADDON_SETS/COMBO_* в app/index.html (деплоить вместе!).
// PRICES: имя позиции => допустимые базовые цены (по размерам).
// ADDONS: имя добавки => цена. Вкус в скобках («Сироп (Карамель)») отбрасывается при проверке.

// === HTTP-эндпойнт: если файл вызван напрямую — отдать JSON прайса ===
// При include/require из другого скрипта — только константы/функции, без вывода.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/socia361/bounty_data/php_errors.log');
define('PRICES_PHP_INCLUDED', true);

$BOUNTY_PRICES = array(
  '«Студент»' => array(30000),
  '«Бариста-завтрак»' => array(39000),
  '«Сладкое утро»' => array(42000),
  '«Сытный ланч»' => array(55000),
  '«Холодный перекус»' => array(62000),
  'Эспрессо' => array(26000, 32000),
  'Американо' => array(26000, 32000),
  'Лонг блэк' => array(32000, 40000),
  'Капучино' => array(30000, 36000),
  'Латте' => array(32000),
  'Флэт уайт' => array(36000, 45000),
  'Раф Баунти' => array(40000, 46000),
  'Раф классик' => array(40000, 46000),
  'Мокко' => array(38000, 42000),
  'Банановый кофе' => array(40000),
  'Какао американский' => array(45000),
  'Горячий шоколад' => array(32000),
  'ПП-сэндвич с курицей' => array(42000),
  'ПП-сэндвич с тунцом' => array(45000),
  'Овсянка с ягодами' => array(32000),
  'Гранола-боул' => array(38000),
  'Кето-маффин' => array(28000),
  'Печенье овсяное' => array(18000),
  'Круассан миндальный БГ' => array(32000),
  'Айс-капучино' => array(36000),
  'Айс-американо' => array(32000),
  'Айс-латте' => array(32000),
  'Эспрессо-тоник' => array(45000),
  'Cold Brew' => array(35000, 45000),
  'Фраппучино' => array(45000),
  'Шмель' => array(65000),
  'Чай облепиха-имбирь' => array(35000),
  'Чай ягодный' => array(35000),
  'Чай смородина и голубика' => array(35000),
  'Чай клубника и малина' => array(35000),
  'Чай клюква и вишня' => array(35000),
  'Чай роза и личи' => array(35000),
  'Чай маракуйя и манго' => array(35000),
  'Айс-ти' => array(35000),
  'Фрэш яблочный' => array(35000, 48000),
  'Фрэш апельсиновый' => array(50000, 80000),
  'Морковно-яблочный фрэш' => array(42000),
  'Милкшейк классический' => array(35000),
  'Милкшейк банановый' => array(40000),
  'Мохито классический' => array(38000),
  'Лайм-тоник' => array(35000),
  'Сэндвич' => array(30000),
  'Десерт дня' => array(38000),
  'Брауни' => array(28000),
  'Орешки со сгущёнкой' => array(45000),
  'Кофейные орешки' => array(22000),
  'Муравейник' => array(28000),
  'Овсяное печенье' => array(14000),
);

$BOUNTY_ADDONS = array(
  'Эспрессо шот' => 10000,
  'Сироп' => 6000,
  'Мёд' => 5000,
  'Взбитые сливки' => 6000,
  'Молоко доп.' => 10000,
  'Имбирь' => 6000,
  'Топпинг' => 5000,
  'Лёд extra' => 0,
  'Без льда' => 0,
  'Авокадо' => 8000,
  'Доп. тунец / курица' => 10000,
  'Сыр (моцарелла/фета)' => 6000,
  'Овощи extra' => 3000,
  'Оливки' => 4000,
  'Соус без сахара' => 2000,
);

// Кастомизация напитка в комбо (COMBO_MILK / COMBO_SYRUPS / COMBO_SHOT_P в index.html)
$BOUNTY_COMBO_MILK = array('обычное' => 0, 'без лактозы' => 0, 'кокосовое' => 5000, 'овсяное' => 5000, 'миндальное' => 5000);
define('BOUNTY_COMBO_SYRUP_P', 3000);
define('BOUNTY_COMBO_SHOT_P', 7000);

function addon_price($a) {
  global $BOUNTY_ADDONS;
  $a = trim((string)$a);
  if (isset($BOUNTY_ADDONS[$a])) { return $BOUNTY_ADDONS[$a]; }
  // «Сироп (Карамель)» → «Сироп»: вкус в скобках цену не меняет
  $base = preg_replace('/\s*\([^()]*\)\s*$/u', '', $a);
  if ($base !== $a && isset($BOUNTY_ADDONS[$base])) { return $BOUNTY_ADDONS[$base]; }
  return null;
}

// Конструктор комбо: n = «Комбо · Напиток + Еда», unit = round((напиток+кастом+еда)*0.85/500)*500.
// Кастом приходит строками в addons: «молоко: овсяное», «сироп: карамель + ваниль», «+ эспрессо шот».
function combo_price_check($name, $addons, $unit) {
  global $BOUNTY_PRICES, $BOUNTY_COMBO_MILK;
  if (!preg_match('/^Комбо · (.+?) \+ (.+)$/u', $name, $m)) { return null; }
  $drink = trim($m[1]); $food = trim($m[2]);
  if (!isset($BOUNTY_PRICES[$drink]) || !isset($BOUNTY_PRICES[$food])) { return false; }
  $extra = 0;
  foreach ((array)$addons as $a) {
    $a = mb_strtolower(trim((string)$a));
    if (strpos($a, 'молоко:') === 0) {
      $milk = trim(mb_substr($a, mb_strlen('молоко:')));
      $extra += $BOUNTY_COMBO_MILK[$milk] ?? 0;
    } elseif (strpos($a, 'сироп:') === 0) {
      $extra += BOUNTY_COMBO_SYRUP_P * (substr_count($a, ' + ') + 1);
    } elseif (strpos($a, 'эспрессо шот') !== false) {
      $extra += BOUNTY_COMBO_SHOT_P;
    }
    // размер, «без сахара», «без кофеина», «айс» — бесплатны
  }
  $fp = $BOUNTY_PRICES[$food][0];
  foreach ($BOUNTY_PRICES[$drink] as $base) {
    if ((int)round(($base + $extra + $fp) * 0.85 / 500) * 500 === (int)$unit) { return true; }
  }
  return false;
}

// Проверка: unit позиции = базовая цена (один из размеров) + сумма добавок.
// true — цена честная; false — подмена; null — позиции нет в прайсе (заказ отклонить, меню рассинхронено).
function price_check($name, $addons, $unit) {
  global $BOUNTY_PRICES;
  $combo = combo_price_check($name, $addons, $unit);
  if ($combo !== null) { return $combo; }
  if (!isset($BOUNTY_PRICES[$name])) { return null; }
  $addSum = 0;
  foreach ((array)$addons as $a) {
    $p = addon_price($a);
    if ($p === null) { return false; } // неизвестная добавка = подмена
    $addSum += $p;
  }
  foreach ($BOUNTY_PRICES[$name] as $base) {
    if ($base + $addSum === (int)$unit) { return true; }
  }
  return false;
}

// === JSON HTTP-ответ (только при прямом запросе, не при include) ===
if (!defined('ORDER_PHP_INCLUDED')) {
  header('Content-Type: application/json; charset=utf-8');
  $prices_out = array();
  foreach ($BOUNTY_PRICES as $name => $variants) {
    $prices_out[] = array('n' => $name, 'p' => $variants);
  }
  $addons_out = array();
  foreach ($BOUNTY_ADDONS as $name => $price) {
    $addons_out[] = array('n' => $name, 'p' => $price);
  }
  echo json_encode(array(
    'ok'     => true,
    'prices' => $prices_out,
    'addons' => $addons_out,
    'combo'  => array(
      'milk'    => $BOUNTY_COMBO_MILK,
      'syrup_p' => BOUNTY_COMBO_SYRUP_P,
      'shot_p'  => BOUNTY_COMBO_SHOT_P,
    ),
  ), JSON_UNESCAPED_UNICODE);
  exit;
}
