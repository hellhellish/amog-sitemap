<?php
/**
 * Plugin Name: AMOG Sitemap
 * Description: Yet another sitemap generator for WP. XML sitemap index, посты, термины, архивы CPT и карта изображений. Без внешних API.
 * Version: 1.0.5
 * Requires PHP: 7.4
 * Requires at least: 5.9
 * Author: hellhellish
 * Author URI: https://github.com/hellhellish
 * Plugin URI: https://github.com/hellhellish/amog-sitemap
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: amog-sitemap
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AMOG_SITEMAP_VERSION', '1.0.5' );
define( 'AMOG_SITEMAP_PATH', plugin_dir_path( __FILE__ ) );
define( 'AMOG_SITEMAP_URL',  plugin_dir_url( __FILE__ ) );
define( 'AMOG_SITEMAP_FILE', __FILE__ );

require_once AMOG_SITEMAP_PATH . 'includes/class-amog-sitemap-settings.php';
require_once AMOG_SITEMAP_PATH . 'includes/class-amog-sitemap-cache.php';
require_once AMOG_SITEMAP_PATH . 'includes/class-amog-sitemap-lastmod.php';
require_once AMOG_SITEMAP_PATH . 'includes/class-amog-sitemap-index.php';
require_once AMOG_SITEMAP_PATH . 'includes/class-amog-sitemap-posts.php';
require_once AMOG_SITEMAP_PATH . 'includes/class-amog-sitemap-terms.php';
require_once AMOG_SITEMAP_PATH . 'includes/class-amog-sitemap-cpt.php';
require_once AMOG_SITEMAP_PATH . 'includes/class-amog-sitemap-images.php';
require_once AMOG_SITEMAP_PATH . 'includes/class-amog-sitemap-robots.php';
require_once AMOG_SITEMAP_PATH . 'includes/class-amog-sitemap-health.php';

add_action( 'plugins_loaded', 'amog_sitemap_bootstrap' );
add_action( 'plugins_loaded', 'amog_sitemap_maybe_upgrade', 5 );

/**
 * При смене версии плагина — сбрасываем кэш и rewrite-правила.
 * Работает автоматически после деплоя, не надо помнить про кнопку.
 */
function amog_sitemap_maybe_upgrade() {
    $stored = get_option( 'amog_sitemap_version', '' );
    if ( $stored === AMOG_SITEMAP_VERSION ) return;

    // Сбрасываем версии кэша — следующий рендер сгенерирует заново.
    delete_option( AMOG_Sitemap_Cache::VERSION );

    // Rewrite-правила — на случай новых URL.
    flush_rewrite_rules();

    update_option( 'amog_sitemap_version', AMOG_SITEMAP_VERSION, false );
}

function amog_sitemap_bootstrap() {
    require_once AMOG_SITEMAP_PATH . 'admin/class-amog-sitemap-page.php';

    // Роутинг sitemap
    new AMOG_Sitemap_Index();
    new AMOG_Sitemap_Posts();
    new AMOG_Sitemap_Terms();
    new AMOG_Sitemap_CPT();
    new AMOG_Sitemap_Images();

    // Инвалидация кэша + обновление _amog_lastmod
    AMOG_Sitemap_Cache::register_hooks();

    // Строка Sitemap: в robots.txt
    new AMOG_Sitemap_Robots();

    // Админка
    if ( is_admin() ) {
        new AMOG_Sitemap_Page();
        new AMOG_Sitemap_Health();
    }
}

/**
 * Активация: регистрируем rewrite-правила и сбрасываем их.
 */
register_activation_hook( __FILE__, 'amog_sitemap_activate' );
function amog_sitemap_activate() {
    AMOG_Sitemap_Index::add_rewrite_rules();
    flush_rewrite_rules();
    update_option( 'amog_sitemap_version', AMOG_SITEMAP_VERSION, false );
}

/**
 * Деактивация: чистим правила и кэш.
 */
register_deactivation_hook( __FILE__, 'amog_sitemap_deactivate' );
function amog_sitemap_deactivate() {
    flush_rewrite_rules();
    AMOG_Sitemap_Cache::flush_all();
}