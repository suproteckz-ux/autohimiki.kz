# Stage 1 — Отчёт по исправлениям
**Дата:** 2026-10-23 | **Ветка:** redesign/stage-1 | **Основан на:** report.md

---

## Выполненные исправления

### Fix 1 — Desktop Header (P0): убрана дублирующая 3-я строка

**Файл:** `resources/views/components/ui/header.blade.php`
Удалён блок `<nav class="ah-container ah-desktop-nav">` целиком (10 строк).

**Файл:** `resources/css/app.css`
Добавлен комментарий о мобильном исправлении (см. Fix 2 ниже).

Доступ к каталогу на desktop обеспечивается существующим элементом `.ah-search-catalog` («Все категории»), встроенным в `components/ui/search.blade.php` и уже имеющим CSS-стили в `app.css:195`. Вторичные ссылки (Бренды, Блог, О нас, Контакты) остаются в topbar через `.ah-topbar-nav`.

---

### Fix 2 — Mobile Header (P1): высота 111px вместо 137px

**Файл:** `resources/css/app.css` (конец файла)

```css
/* ── Mobile header height fix — Stage 1 ── */
@media(max-width:639px){
  .ah-header-main{gap:4px;padding-block:8px}
  .ah-logo small{display:none}
}
```

- Уменьшен `gap` между строками flex-wrap с 20px до 4px
- Убран субтитл «АВТОХИМИЯ И ДЕТЕЙЛИНГ» на мобайл (`display:none`)
- На desktop субтитл остаётся видимым (`logoSubtitle: "block"`)

---

## Playwright-проверка после исправлений

| Проверка | До fix | После fix | Цель |
|---|---|---|---|
| Mobile header высота | 137px | **111px** ✅ | 112px |
| Desktop header высота | 181px | **117px** ✅ | ~130px |
| Topbar высота | 34px | 34px ✅ | — |
| Main row высота | 98px | **82px** ✅ | ~64px |
| `.ah-desktop-nav` в DOM | true | **false** ✅ | убрать |
| `.ah-search-catalog` видна на desktop | true | true ✅ | «Все категории» |
| Topbar nav links | true | true ✅ | Бренды/Блог |
| Bottom nav скрыта (desktop) | true | true ✅ | — |
| Bottom nav видна (mobile) | true | true ✅ | — |
| Бейдж корзины (mobile) | true | true ✅ | — |
| Menu button 44px | true | true ✅ | ≥44px |
| Mobile menu opens | true | true ✅ | — |
| Logo subtitle (mobile) | block | **none** ✅ | скрыт |
| Overflow (390px) | 0px | 0px ✅ | 0 |
| Overflow (1440px) | 0px | 0px ✅ | 0 |
| JS errors | 0 | 0 ✅ | 0 |
| Font | Manrope | Manrope ✅ | — |

---

## Тесты

- **PHP tests:** 480/480 passed (480 tests, 7740 assertions) ✅
- **Vite build:** ✓ built in 2.91s ✅
- Новый CSS хэш: `app-Cz-ae4j9.css`

---

## Визуальная проверка

### Mobile 390px (fix-home-mobile-390.png)
- Header: 2 строки — иконки (≡ / лого / 📞 / 🛒) + строка поиска
- Субтитл «АВТОХИМИЯ И ДЕТЕЙЛИНГ» скрыт ✅
- Header компактный, не перекрывает контент
- Bottom nav: 4 пункта с оранжевой пилюлей на «Главная», бейдж «2» на корзине ✅
- Текст «WhatsApp» виден полностью в нижней навигации ✅

### Mobile Menu (fix-home-mobile-390-menu.png)
- Bottom sheet открывается, drag handle виден ✅
- Список категорий: Все товары / Автохимия / Аксессуары / Детейлинг / Полироли и воски ✅
- Блок КОНТАКТЫ с телефоном ✅
- Backdrop за sheet ✅

### Desktop 1440px (fix-home-desktop-1440.png)
- Header: ровно 2 строки
  - Topbar: «Алматы · Автохимия и детейлинг» | Бренды Блог О нас Контакты | часы ✅
  - Main row: [A AUTOHIMIKI.KZ / АВТОХИМИЯ И ДЕТЕЙЛИНГ] [«Все категории ⌄» | поле поиска | «Найти →»] [телефон] [WhatsApp] [Корзина (2)] ✅
- 3-я строка (`.ah-desktop-nav`) отсутствует ✅
- Footer: 4 колонки (лого / Каталог / Покупателям / Контакты) ✅

---

## Итог

Оба расхождения устранены. Этап 1 соответствует дизайн-спецификации §7.

| Компонент | Статус |
|---|---|
| Шрифт Manrope | ✅ |
| CSS-токены | ✅ |
| Mobile header (111px ≈ 112px) | ✅ |
| Desktop header (117px, 2 строки) | ✅ |
| Mobile menu | ✅ |
| Bottom nav | ✅ |
| Footer accordion | ✅ |
| Breadcrumbs | ✅ |
| Overflow | ✅ |
| JS errors | ✅ |
| PHP tests 480/480 | ✅ |

**Этап 2 не начат. Push/deploy не выполнялся.**
