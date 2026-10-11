# AMOG Sitemap

Yet another sitemap generator for WordPress. XML sitemap index, посты, термины, архивы CPT и карта изображений. Без внешних API, без SaaS, без composer/npm — только чистый PHP.

## Что делает

- `sitemap_index.xml` — общий индекс
- `sitemap-{post_type}.xml` — посты, страницы, CPT (с пагинацией `sitemap-{type}-2.xml`, `...-3.xml`)
- `sitemap-tax-{taxonomy}.xml` — термины таксономий
- `sitemap-cpt-archive.xml` — архивы CPT
- `sitemap-images.xml` — карта изображений (featured, `_seo_og_image`, ACF image/gallery)
- Добавляет строку `Sitemap:` в `robots.txt`

## Что НЕ делает

- Не управляет `noindex` — только читает стандартные мета-ключи `_seo_robots`, `_seo_cascade_noindex`
- Не генерирует `llms.txt`
- Не отправляет данные наружу
- Не требует других плагинов

## Совместимость

AMOG Sitemap не зависит от других плагинов, но уважает стандартные мета-ключи:

- `_seo_robots` (post/term meta) — пер-объектный noindex
- `_seo_cascade_noindex` (term meta) — каскадный noindex для термина и его родителей
- `_seo_og_image` (post meta) — дополнительный источник картинок для image sitemap

Эти ключи ставит, например, [My SEO](https://github.com/hellhellish) или вручную. Если ключей нет — плагин работает с дефолтами.

## Требования

- WordPress 5.9+
- PHP 7.4+

## Установка

1. Скопировать папку `amog-sitemap` в `wp-content/plugins/`
2. Активировать плагин в админке
3. Открыть **AMOG Sitemap** для настройки
4. Проверить `/sitemap_index.xml`

После активации нужно один раз пересохранить постоянные ссылки (**Настройки → Постоянные ссылки → Сохранить**), чтобы зарегистрировались rewrite-правила. Плагин при активации пытается сбросить их сам, но если nginx-конфиг нестандартный — может понадобиться ручной сброс.

## Настройки

- **Sitemap** — вкл/выкл
- **Карта изображений** — вкл/выкл
- **Архивы CPT** — включать ли архивы CPT в sitemap
- **URL на файл** — 100..50000, по умолчанию 1000
- **Время жизни кэша** — сек, по умолчанию 86400
- **Исключения** — рубрики, метки, архивы CPT
- **Очистить кэш** — принудительный сброс

## Архитектура
amog-sitemap/
├── amog-sitemap.php
├── readme.md
├── includes/
│ ├── class-amog-sitemap-settings.php # единая точка опций
│ ├── class-amog-sitemap-cache.php # transient-кэш + инвалидация
│ ├── class-amog-sitemap-index.php # /sitemap_index.xml
│ ├── class-amog-sitemap-posts.php # urlset постов с пагинацией
│ ├── class-amog-sitemap-terms.php # urlset терминов
│ ├── class-amog-sitemap-cpt.php # urlset CPT-архивов
│ ├── class-amog-sitemap-images.php # image sitemap
│ └── class-amog-sitemap-robots.php # Sitemap: в robots.txt
└── admin/
└── class-amog-sitemap-page.php # меню AMOG Sitemap


## Кэш

- Ключи: `amog_sitemap_cache_{type}_{page}`
- TTL: из настроек, по умолчанию сутки
- Инвалидация: `save_post`, `deleted_post`, `trashed_post`, `untrashed_post`, `edited_term`, `created_term`, `delete_term`, изменение настроек

## Стиль кода

- WordPress coding standard
- Префикс `AMOG_Sitemap_` / `amog_sitemap_`
- Без composer/npm
- Комментарии на русском, код на английском

## Лицензия

GPL-2.0-or-later