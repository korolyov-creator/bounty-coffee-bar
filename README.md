# 🌴 Bounty Coffee Bar

> «Тихая пауза в городе» — кофейный бар в Ташкенте с 2019 года

![Status](https://img.shields.io/badge/status-active-success)
![Locations](https://img.shields.io/badge/locations-2_+_планируется_3-blue)
![Brand](https://img.shields.io/badge/brand-navy_%2B_gold-d4a017)
![Updated](https://img.shields.io/badge/обновлено-2026--05--30-lightgrey)

🌐 **Сайт-мокап:** [korolyov-creator.github.io/bounty-coffee-bar](https://korolyov-creator.github.io/bounty-coffee-bar/)
📸 **Instagram:** [@bountycoffeebar.tashkent](https://www.instagram.com/bountycoffeebar.tashkent/)
🌍 **Официальный сайт:** [bountycoffeebar.uz](https://www.bountycoffeebar.uz/)

---

## 📍 Точки

| Точка | Адрес | Режим | Выручка/мес (2025-2026) |
|---|---|---|---|
| **Ц-14 Хадра** | Ц-14, Хадра 25 | **24/7** | ~131.8M сум (~$10.5K) |
| **Сайрам** | улица Сайрам 25/1 | стандартный | ~29.4M сум (~$2.4K) |
| **3-я точка** | планируется | — | под кредит $100K |

---

## 📁 Структура репозитория

```
Bounty/
├── README.md                       ← ты здесь
├── analysis/                       ← финансовый анализ Excel + дашборды
│   ├── cafe-monthly.json
│   ├── dashboard.html              ← финмодель 3-й точки
│   └── *.png                       ← графики выручки
├── business/                       ← bounty-business агент
│   └── competitors/                ← baseline по конкурентам
├── design/                         ← bounty-design агент
│   ├── brand-assets/               ← логотипы, цвета, шрифты
│   │   ├── logo-clean/             ← 6 вариантов лого (gold-on-navy, white-on-black, и т.д.)
│   │   └── sources/                ← оригинальные .cdr от Watta
│   ├── menu-drafts/2026-05-29/     ← новое меню Phase 1 (PDF + HTML)
│   │   ├── menu-vertical.pdf       ← 450×700мм
│   │   └── menu-horizontal.pdf     ← 630×370мм
│   ├── website-mockup/v2/          ← редизайн сайта (Starbucks-style)
│   ├── dashboards/                 ← 5 HTML-дашбордов
│   ├── data/                       ← snapshot-данные (JSON)
│   ├── instagram-bounty/           ← HD-фото из IG
│   ├── instagram-competitors/      ← разбор 8 конкурентов
│   └── location-3-visualization/   ← проект 3-й точки + AI-рендеры
├── org/                            ← оргструктура (11 AI-агентов)
└── docs/                           ← GitHub Pages — публичные страницы
```

---

## 🎯 Активные направления

### 1. Запуск 3-й точки
- **Бюджет:** $100K кредит (12%, 5 лет, 2 года grace)
- **CapEx прогноз:** $24-46K (с HVAC и акустикой)
- **Локация:** на стадии поиска (Юнусабад / Алмазар приоритет)
- [📊 Дашборд анализа локации](./design/dashboards/bounty-location3-verified-2026-05-29.html)

### 2. Повышение цен (Phase 1)
- **Найдено:** 9 позиций с маржой <35% (сэндвичи 10%, Шмель 12.5%, Red Bull 15%)
- **Эффект:** +5-12M сум/мес дополнительного дохода
- [📊 Дашборд цен](./design/dashboards/bounty-prices-2026-05-29.html)

### 3. Бренд и SMM
- Меню переделано в стиле оригинала Watta с новыми ценами
- Сайт-мокап v2 в narrative-стиле
- IG: 1620 фолловеров, 27 постов, 67% Reels

---

## 🤖 AI-команда (через Claude Code)

Проект управляется через 11 специализированных AI-агентов:

| Pillar | Агенты |
|---|---|
| **Finance** | `steve` (CFO), `bounty-pricing` |
| **Market** | `bounty-business`, `bounty-competitors` |
| **Ops** | `bounty-ops` |
| **Brand** | `bounty-design`, `bounty-smm`, `bounty-marketing` |
| **Strategy** | `bounty-location`, `bounty-risk` |
| **Координация** | Claude (Chief of Staff) |

Полная оргструктура: [./design/dashboards/bounty-org-2026-05-28.html](./design/dashboards/bounty-org-2026-05-28.html)

---

## 📊 Дашборды

| Дашборд | Что показывает |
|---|---|
| [Overview 360°](./design/dashboards/bounty-360-2026-05-28.html) | Бренд, точки, IG, конкуренты, действия |
| [Цены Phase 1](./design/dashboards/bounty-prices-2026-05-29.html) | Маржа по 49 позициям, рекомендации повышения |
| [Оргструктура](./design/dashboards/bounty-org-2026-05-28.html) | 11 AI-агентов с зонами ответственности |
| [3-я точка — карта](./design/dashboards/bounty-location3-verified-2026-05-29.html) | 12 районов Ташкента, шорт-лист 15 объявлений |
| [3-я точка — дизайн](./design/location-3-visualization/new-design.html) | 2D-план + AI-рендеры + смета CapEx |

---

## 💡 Как работать с проектом

### Обновить файлы
```bash
cd ~/Desktop/Bounty
git add -A
git commit -m "что изменил"
git push
```

### Скачать на другом устройстве
```bash
git clone git@github.com:korolyov-creator/bounty-coffee-bar.git
```

### Открыть дашборд
```bash
open design/dashboards/bounty-org-2026-05-28.html
```

---

## 📜 История изменений

См. [git log](https://github.com/korolyov-creator/bounty-coffee-bar/commits/main)

---

*Проект создан и поддерживается с помощью [Claude Code](https://claude.com/claude-code) от Anthropic.*
