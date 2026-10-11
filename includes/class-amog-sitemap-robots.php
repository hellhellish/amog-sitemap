<?php
/**
 * Добавляет строку Sitemap: в robots.txt.
 *
 * Если активен My SEO — он отдаёт robots.txt через фильтр robots_txt,
 * и мы добавляемся к нему. Если My SEO нет — WP отдаёт свой robots.txt,
 * и мы тоже добавляемся. Никакой зависимости от My SEO.
 */
class AMOG_Sitemap_Robots {

    public function __construct() {
        add_filter( 'robots_txt', [ $this, 'add_sitemap_line' ], 20, 2 );
    }

    public function add_sitemap_line( $output, $public ) {
        if ( ! AMOG_Sitemap_Settings::is_enabled() ) {
            return $output;
        }

        // Не дублируем
        if ( strpos( $output, 'sitemap_index.xml' ) !== false ) {
            return $output;
        }

        $output .= "\nSitemap: " . home_url( '/sitemap_index.xml' ) . "\n";
        return $output;
    }
}