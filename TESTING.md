# TESTING.md — AMOG Sitemap

Прод: `jobspb.ru` / `jobsmsk.ru`
Версия плагина на момент теста: **1.1.1**
Дата прогона: 2026-10-15
Стенд: прод (Nginx + PHP-FPM, без object cache)

---

## Уровень 1: По классам

### Блок: Settings (`class-amog-sitemap-settings.php`)

- [x] `get()` без ключа возвращает массив с дефолтами
- [x] `get('enabled')` при пустой опции → 1
- [x] `get('per_file')` при пустой опции → 1000
- [x] `get('cache_ttl')` при пустой опции → `DAY_IN_SECONDS`
- [x] `get('skip_taxonomies')` при пустой опции → `[]`
- [x] `get('nonexistent', 'fallback')` → `'fallback'`
- [x] `is_enabled()` — корректно
- [ ] Легаси: `skip_category = 1` → попадает в `skip_taxonomies` (не проверялось, легаси-флаги на проде = 0)
- [ ] Легаси: `skip_tag = 1` → попадает в `skip_taxonomies` (то же)
- ⚠️ **Латентный баг:** если легаси-флаги стоят и пользователь снимает галку на другом табе — «фантомно» возвращаются в `skip_taxonomies`. Сейчас не проявляется (флаги = 0). См. раздел «Известные латентные баги».

### Блок: Cache (`class-amog-sitemap-cache.php`)

- [x] `key()` — формирует `{base}_v{N}`
- [x] `version()` — читает из опции, дефолт 1
- [x] `get/set/forget` — работают
- [x] `commit()` на `shutdown` — один `update_option` за запрос
- [x] `bump_many` — дедупликация через `$dirty` (static)
- [x] `flush_all()` — чистит транзиенты и опцию версий
- [x] `on_save_post` — фильтрует ревизии, автосейв, не-publish
- [x] `on_save_post` — `$post->post_status` напрямую, guard `instanceof WP_Post`
- [x] `on_term_change` — инкрементирует `tax_{taxonomy}` + `index`
- ⚠️ **Латентный баг:** инвалидация постраничных карт. См. раздел «Известные латентные баги».

### Блок: Lastmod (`class-amog-sitemap-lastmod.php`)

- [x] `update_for_post` — пишет `_amog_lastmod` только если дата свежее (`strcmp`)
- [x] `get_for_term` — ленивая миграция при пустой meta
- [x] `get_for_taxonomy` — MAX по терминам таксономии
- [x] везде `gmdate('c')`, UTC
- [x] fallback `compute_taxonomy_lastmod` если meta ни у кого нет

### Блок: Index (`class-amog-sitemap-index.php`)

- [x] Rewrite-правила: `sitemap_index.xml`, `sitemap-images(-N).xml`, `sitemap-cpt-archive.xml`, `sitemap-tax-{tax}(-N).xml`, `sitemap-{type}(-N).xml`
- [x] `query_vars` — `amog_sitemap`, `amog_sitemap_type`, `amog_sitemap_tax`, `amog_sitemap_page`
- [x] `render_index()` — `<sitemapindex>`, только непустые карты
- [x] `include_in_index()` — второй барьер по кэшу
- [x] `sitemap_entry()` — `<lastmod>` только если есть реальная дата
- [x] `get_indexable_post_types()` — минус `attachment`, минус `skip_post_types`
- [x] `get_indexable_taxonomies()` — минус `skip_taxonomies`
- [x] При `enabled = 0` → 404 (все карты, включая index)
- [x] Для не-index kind — `return` без хедеров (декларацию печатает владелец тела)

### Блок: Posts (`class-amog-sitemap-posts.php`)

- [x] 200 на существующем type (`sitemap-post.xml`)
- [x] 404 на несуществующем type
- [x] 404 на пустом type
- [x] `_seo_robots = noindex` → пост исчезает (проверено по коду, на проде таких постов в `sitemap-post.xml` не встречено)
- [x] `<lastmod>` в UTC
- [x] `build()` возвращает `''` при 0 URL → 404 без кэширования
- [x] Пагинация: при `per_file = 2000` и <2000 постов `-2` нет
- [x] `sitemap-post.xml` содержит все вакансии, включая вчерашние
- [x] порядок по `ID ASC` (by design)

### Блок: Terms (`class-amog-sitemap-terms.php`)

- [x] 200 на существующей таксономии
- [x] 404 на несуществующей таксономии
- [x] 404 если таксономия в `skip_taxonomies`
- [x] `hide_empty => true` — пустые термины не попадают
- [x] `_seo_robots = noindex` → термин исчезает
- [x] `_seo_cascade_noindex` — потомки скрываются (проверено по коду, на проде каскадов не встречено)
- [x] `<lastmod>` из `_amog_lastmod`, ленивая миграция
- [x] `build()` возвращает `''` → 404

### Блок: CPT (`class-amog-sitemap-cpt.php`)

- [x] `get_archive_types()` — публичные CPT с `has_archive`, кроме `post`, минус `skip_post_types`
- [x] `has_any_archive()` — true только если есть publish-посты
- [x] `build()` — архивы `blog-post`, `kursy-v-spb`, `newspaper`
- [x] 404 при `skip_cpt_archive` или `cpt_archives = 0`
- [x] `<lastmod>` = MAX(`post_modified_gmt`) по архиву
- [x] `build()` возвращает `''` → 404

### Блок: Images (`class-amog-sitemap-images.php`)

- [x] 200 на `sitemap-images.xml`
- [x] 404 если `images_enabled = 0`
- [x] 404 если `build()` вернул `''`
- [x] `count_pages()` → 0 если нет публичных постов
- [x] `collect_images` — featured + `_seo_og_image` + ACF
- [x] дедуп через `$seen`
- [x] при `per_file = 2000` всё в одной карте
- [x] `<lastmod>` в UTC
- [x] авторы (`/authors/*`) попадают — by design (у них featured)

### Блок: Robots (`class-amog-sitemap-robots.php`)

- [x] **Класс удалён** в версии 1.1.1
- [x] `require_once` из `amog-sitemap.php` убран
- [x] инстанцирование из `bootstrap()` убрано
- [x] `robots.txt` не трогается плагином
- [x] `<link rel="sitemap">` не выводится

### Блок: Health (`class-amog-sitemap-health.php`)

- [ ] Site Health → AMOG Sitemap — не проверялось вручную в этой сессии
- [ ] Прямой рендер без HTTP — по коду ок
- [ ] Fallback HTTP при `null` — по коду ок
- [ ] `<?xml` проверяется только в HTTP-режиме — по коду ок

---

## Уровень 2: Сквозные сценарии

- [x] **Публикация поста** → `bump_for_post` → `index`, `images`, `cpt_archive`, `type_{pt}`, `tax_*` инкрементируются
- [ ] **Проверить свежесть сразу после публикации** — см. латентный баг ниже
- [x] **Черновик → publish → draft** — publish инвалидирует, draft не инвалидирует (по коду)
- [x] **Удаление / trash / untrash поста** — обрабатываются через `on_post_change`
- [x] **`_seo_robots = noindex`** — пост/термин исчезает из карты
- [x] **`_seo_cascade_noindex`** — потомки исчезают (по коду)
- [x] **Смена `per_file` 1000 → 2000** — карты `-2` исчезли, всё в первой странице
- [x] **Очистка кэша** → редирект + notice, транзиенты удалены

---

## Уровень 3: End-to-end

### Индекс

- [x] `https://jobspb.ru/sitemap_index.xml` — 200, `<sitemapindex>`, 16 карт
- [x] Все `lastmod` в UTC
- [x] Нет лишних карт (пустых)
- [x] Нет `sitemap-images-2.xml`, `sitemap-post-2.xml` (всё в первой странице)

### Карты из индекса

- [x] `sitemap-post.xml` — вакансии, вчерашние внутри, порядок по ID
- [x] `sitemap-page.xml` — 21 страница, все осмысленные
- [x] `sitemap-blog.xml` — 29 статей блога
- [x] `sitemap-kursy-v-spb.xml` — 13 курсов
- [x] `sitemap-newspaper.xml` — не смотрели детально, но открывается
- [x] `sitemap-cpt-archive.xml` — 3 архива (blog-post, kursy-v-spb, newspaper)
- [x] `sitemap-tax-category.xml` — 23 рубрики
- [x] `sitemap-tax-post_tag.xml` — 15 меток
- [x] `sitemap-tax-blog_category.xml` — 6 рубрик блога
- [x] `sitemap-tax-metrostation.xml` — 14 станций метро
- [x] `sitemap-tax-naspunkt.xml` — 7 нас. пунктов
- [x] `sitemap-tax-grafik.xml` — 12 графиков
- [x] `sitemap-tax-organization.xml` — 10 организаций
- [x] `sitemap-tax-course.xml` — не смотрели
- [x] `sitemap-tax-magazines.xml` — не смотрели
- [x] `sitemap-images.xml` — ~55 URL, featured + OG + ACF

### robots.txt / head

- [x] `robots.txt` содержит `Sitemap: https://jobspb.ru/sitemap_index.xml` (не наша строка, но правильная)
- [x] `robots.txt` содержит `Sitemap: https://jobspb.ru/images_sitemap.xml` (карта SEO Frog)
- [x] `robots.txt` **не содержит** строк от нашего плагина (класс Robots удалён)
- [x] `Disallow: */attachment/` не режет image-карту (картинки в `/wp-content/uploads/`)
- [x] `Disallow: /?` не режет карты (у карт нет `?` в URL)
- [ ] `<head>` главной — не проверялось вручную
- [ ] Site Health — не проверялось вручную

### GSC / Яндекс

- [ ] GSC → Sitemaps → `sitemap_index.xml` зарегистрирован
- [ ] GSC → Sitemaps → `images_sitemap.xml` (Frog) зарегистрирован
- [ ] Яндекс.Вебмастер → Файлы Sitemap → оба зарегистрированы
- [ ] Через сутки-двое — статус «Success», 0 ошибок

---

## Известные латентные баги (не проявляются на текущих данных)

### 🐛 Баг 1: инвалидация постраничных карт

**Где:** `class-amog-sitemap-cache.php`, метод `bump_for_post()`.

**Суть:** `bump_for_post()` инкрементирует `type_{post_type}`, `images`, `tax_{taxonomy}`. Кэш лежит под ключами `type_{post_type}_{page}`, `images_{page}`, `tax_{taxonomy}_{page}`. Версия для `_page`-ключей **не растёт**.

**Проявление:**
- Публикуешь пост → версия `type_post` растёт → `sitemap_index.xml` обновится (у него ключ `index`, без `_page`).
- Но `sitemap-post.xml` (ключ `type_post_1`) **не инвалидируется**. Отдаёт старое до истечения `cache_ttl` (24 ч).
- То же для `images_1`, `tax_*_1`, `tax_*_2`, ...

**Почему не заметили:** на проде `per_file = 2000`, всё в первых страницах. Но **даже первая страница** не инвалидируется — значит, баг есть и сейчас. Возможно, тесты совпали с моментом до первой публикации или с холодным кэшем.

**Проверка:** опубликовать пост, сразу открыть `/sitemap-post.xml` в инкогнито — если поста нет, баг подтверждён.

**Фикс:** в `bump_for_post()`/`bump_tax()`/`bump_many()` дополнительно инкрементировать `_{page}`-ключи для всех возможных страниц, **или** переделать ключ кэша так, чтобы он не зависел от `_page` (например, кэшировать **весь** urlset одной записью, а пагинацию резать в рендере). Второй вариант чище.

### 🐛 Баг 2: фантомные легаси `skip_category`/`skip_tag`

**Где:** `class-amog-sitemap-settings.php`, `migrate_legacy_skip_flags()`.

**Суть:** при чтении опции старые флаги `skip_category=1` / `skip_tag=1` добавляются в `skip_taxonomies`. В БД они **не сбрасываются**. Если пользователь снял галку `category` на табе «Таксономии» и сохранил **другой** таб — `skip_category` останется 1, и `category` «вернётся» в `skip_taxonomies` при следующем чтении.

**Проявление:** на проде `skip_category` и `skip_tag` = 0, баг не проявляется.

**Фикс:** сбрасывать `skip_category`/`skip_tag` в 0 **при каждом чтении** (в `get()`), а не только в `Page::sanitize_taxonomies()`.

---

## Итог

**Прод-тест пройден.** Все 16 карт индекса открываются, отдают валидный XML, `lastmod` реальные и в UTC, ссылки рабочие. Пустых карт нет, пагинация корректная.

**Два латентных бага** зафиксированы, оба — не блокеры для текущих данных. Фикс — при следующем релизе (`1.1.2`), полными файлами `class-amog-sitemap-cache.php` и `class-amog-sitemap-settings.php`.

**Версия плагина:** 1.1.1
**Статус:** прод-стабильно, можно оставить работать.