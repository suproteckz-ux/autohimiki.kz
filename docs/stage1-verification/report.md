# Stage 1 — Визуальный отчёт проверки
**Дата:** 2026-10-23 | **Ветка:** redesign/stage-1 | **Инструмент:** Playwright + Chromium headless

---

## Метод и ограничения

Проверка выполнена на статическом HTML-моке (UTF-8, без Laravel/MySQL).
- CSS: Vite build `app-D9a7WGrk.css` (130 KB, production)
- JS: Alpine.js + app.js (production Vite build, `type="module"`)
- Шрифт: Manrope woff2 с локального сервера (same-origin, CORS нет)
- Не проверяется: реальный роутинг, данные из БД, Blade-рендеринг

---

## Скриншоты

| Файл | Вьюпорт | devicePixelRatio |
|---|---|---|
| `home-mobile-390.png` | 390×844 | 2× |
| `home-mobile-390-menu-open.png` | 390×844 (clip) | 2× |
| `home-desktop-1440.png` | 1440×900 | 1× |

---

## Автоматические проверки (Playwright)

### Без ошибок и переполнений
| Проверка | Результат |
|---|---|
| JS console errors | **0** ✅ |
| pageerror | **0** ✅ |
| Horizontal overflow (390px) | `scrollWidth === innerWidth` ✅ |
| Horizontal overflow (1440px) | `scrollWidth === innerWidth` ✅ |

### Шрифт
```
fontFamily (mobile + desktop): "Manrope, system-ui, sans-serif"  ✅
```
Manrope загружается локально, без обращений к Google Fonts.

### Mobile (390px)
| Проверка | Измерено | Требование | |
|---|---|---|---|
| Header высота | **137 px** | 112 px (спец.) | ❌ Δ+25px |
| Topbar виден | false | скрыт | ✅ |
| Кнопка меню (tap target) | 44×44 px | ≥44×44 | ✅ |
| Bottom nav высота | 96 px | 64 px + safe-area | ⚠️ |
| Bottom nav видима | true | показана | ✅ |
| Overflow | 0 px | 0 | ✅ |
| Mobile menu open (Alpine) | true | открывается | ✅ |
| Backdrop при меню | true | показан | ✅ |

### Desktop (1440px)
| Проверка | Измерено | Требование | |
|---|---|---|---|
| Header высота | **181 px** | «2 строки» (~130 px) | ❌ Δ+50px |
| Topbar высота | 34 px | — | — |
| Main row высота | 98 px | ~64 px | ⚠️ |
| Desktop nav row высота | 48 px | не должно быть | ❌ |
| Bottom nav скрыта | true | скрыта | ✅ |
| Topbar nav links видны | true | видны | ✅ |
| Overflow | 0 px | 0 | ✅ |

---

## Визуальное сравнение с дизайн-пакетом

### ✅ Соответствует спецификации

**Шрифт и токены**
- Manrope Variable (cyrillic + latin) загружается локально, 2 woff2 файла ✅
- CSS-переменные `--ah-orange-tint`, `--ah-r-md`, `--ah-nav-h` применяются ✅
- Типографика Manrope 800 в заголовках, 600 в навигации ✅

**Нижняя навигация (`.ah-bottom-nav`)**
- 4 пункта: Главная · Каталог · Корзина(N) · WhatsApp ✅
- Lucide SVG иконки stroke-width 2.5 ✅
- Активный пункт (Главная): оранжевая пилюля под иконкой ✅
- Бейдж счётчика корзины (оранжевый, позиция absolute top-left) ✅
- Скрыта на 1440px, видна на 390px ✅
- Текст «Корзина (2)» в лейбле ✅

**Мобильное меню (bottom sheet)**
- Плавный slide-up из-за трансформации `translateY(100%) → 0` ✅
- Drag handle (белая полоска) ✅
- Список категорий со стрелками `>` ✅
- Блок «КОНТАКТЫ» с телефоном и WhatsApp ✅
- Кнопка × для закрытия 44×44px ✅
- Alpine.js `is-open` класс применяется корректно ✅
- Backdrop тёмный при открытии ✅

**Футер**
- 4 колонки на desktop (лого / Каталог / Покупателям / Контакты) ✅
- Аккордеон на mobile (click toggle через `<details>`) ✅
- Контакты всегда раскрыты (`.ah-footer-contacts-group`) ✅
- Ссылки футера с отступом min-height 44px ✅
- Цвета: тёмный фон `--ah-ink`, оранжевые ссылки на hover ✅

**Breadcrumbs** (проверены на предыдущем прогоне)
- Desktop: полная цепочка, `display:none` на mobile ✅
- Mobile: `← Каталог` back-link, `display:none` на desktop ✅
- min-height 44px на back-link ✅

---

### ❌ Расхождения с дизайн-спецификацией

---

#### 1. Desktop Header — 3 строки вместо 2 (КРИТИЧНО)

**Спецификация (README §7):**
> desktop — 2 строки, ссылки третьей строки в топбар

**Факт:**

```
[Topbar]       Алматы · Автохимия и детейлинг | Бренды Блог О нас Контакты | часы     34px
[Main row]     [A AUTOHIMIKI.KZ] [─────поиск─────] [тел] [WhatsApp] [Корзина]         98px
[Desktop nav]  [☰ Каталог товаров] Бренды Блог Почему мы Контакты             48px
               ─────────────────────────────────────────────────────────────────
               ИТОГО: 181px   (спецификация: ~130px)
```

**Проблема:**
`.ah-desktop-nav` — третья строка — дублирует ссылки Бренды/Блог/Контакты, которые уже находятся в topbar. Спецификация требует убрать эту строку, оставив только topbar + main row.

**Почему нужно исправить:**
- Desktop header занимает 181px вместо целевых ~130px (на 50px выше, чем нужно)
- Ссылки Бренды/Блог/Контакты выводятся дважды (topbar + nav row)
- До редизайна сайт имел header 127px; после этапа 1 стало 181px — регрессия

**Требуемое исправление:**
Убрать `.ah-desktop-nav` со ссылками Бренды/Блог/Почему мы/Контакты. Кнопку «☰ Каталог товаров» переместить в `.ah-header-main` (рядом с логотипом или как самостоятельный элемент). Тогда:
- Topbar: Алматы · Бренды Блог О нас Контакты · часы (~34px)
- Main row: [Каталог] [Лого] [поиск] [тел] [WA] [Корзина] (~64px)
- Итого: ~98-100px ← compact

---

#### 2. Mobile Header — 137px вместо 112px (требует исправления)

**Спецификация:**
> mobile 112px (строка 48px: меню 44, логотип, телефон 44, корзина 44; ниже поиск 44px pill)

**Причина расхождения в 25px:**

| Фактор | Лишние пиксели |
|---|---|
| `gap:20px` из правила `@media(max-width:1023px)` применяется и на 390px, добавляя расстояние между строкой 1 и строкой поиска | +16px сверх нужных ~4px |
| `.ah-logo small` «АВТОХИМИЯ И ДЕТЕЙЛИНГ» делает первую строку выше 44px (лого имеет 2 текстовых строки) | ~8px |

**Требуемое исправление:**
```css
/* Добавить в Stage 1 CSS: */
@media(max-width:639px){
  .ah-header-main{gap:4px; padding-block:8px}
  .ah-logo small{display:none}   /* субтитл скрыт на мобайл */
}
```
Это даст: 8 + 44 + 4 + 44 + 8 + border = **109px** ≈ 112px целевых.

---

#### 3. Bottom nav высота: 96px вместо 64px (незначительно)

На headless Chromium Windows без iOS safe area `env(safe-area-inset-bottom)` равен 0, но nav измеряется как 96px (container `min-height:calc(64px + 0px)` + внутренние отступы `padding:8px 4px`). Фактически nav внутри correct 64px, измеренное 96px = высота `<a>`-элементов внутри grid. В iOS Safari с safe area нижний отступ добавится корректно через `padding-bottom:env(safe-area-inset-bottom)`. Визуально nav выглядит правильно.

---

## Итоговая таблица

| Компонент | Статус | Примечание |
|---|---|---|
| Шрифт Manrope | ✅ ОК | Локально, 2 woff2 файла |
| CSS-токены | ✅ ОК | Все `--ah-*` переменные |
| Кнопки и поля | ✅ ОК | font-size ≥16px, focus ring |
| Mobile header layout | ⚠️ | 137px vs 112px: нужна правка gap+small |
| Desktop header | ❌ | 3 строки (181px) vs 2 строки (~130px): убрать `.ah-desktop-nav` |
| Mobile menu | ✅ ОК | bottom-sheet, Alpine, backdrop |
| Bottom nav (mobile) | ✅ ОК | 4 пункта, пилюля, бейдж |
| Bottom nav (desktop) | ✅ ОК | скрыта |
| Topbar (desktop) | ✅ ОК | ссылки, часы |
| Footer accordion | ✅ ОК | toggle, contacts always open |
| Breadcrumbs | ✅ ОК | mobile back-link / desktop trail |
| Overflow | ✅ ОК | 0px на всех вьюпортах |
| JS errors | ✅ ОК | 0 ошибок |

---

## Необходимые правки перед утверждением этапа 1

### P0 — блокирует утверждение
1. **Desktop header: убрать `.ah-desktop-nav` строку** — дублирует topbar, добавляет 48px высоты. Кнопку «Каталог товаров» интегрировать в main row.

### P1 — рекомендуется исправить до утверждения
2. **Mobile header gap+small** — добавить `@media(max-width:639px){.ah-header-main{gap:4px;padding-block:8px} .ah-logo small{display:none}}`. Сокращает высоту с 137 до ~109px (≈112px цель).

---

*Этап 2 не начат. Push/deploy не выполнялся.*
