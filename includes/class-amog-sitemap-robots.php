<?php
/**
 * Сообщает краулеру, где находится sitemap:
 *  - строка Sitemap: в robots.txt (фильтр robots_txt)
 *  - <link rel="sitemap"> в <head> на главной (хук wp_head)
 *
 * robots.txt: если активен My SEO или другой плагин, отдающий robots.txt
 * через фильтр robots_txt, мы добавляемся к нему.
 *
 * <link rel="sitemap">: запасной канал на случай, если у сайта физический
 * robots.txt в корне (тогда фильтр robots_txt не сработает).
 *
 * Дедуп для robots.txt: если в выводе уже есть ЛЮБАЯ строка "Sitemap:"
 * (регистронезависимо), ничего не добавляем — не навязываем свою карту поверх чужой.
 */
class AMOG_Sitemap_Robots {

    /**
     * Защита от повторного вывода <link rel="sitemap"> на одной странице.
     *
     * @var bool
     */
    private static $link_printed = false;

    public function __construct() {
        add_filter( 'robots_txt', [ $this, 'add_sitemap_line' ], 20, 2 );
        add_action( 'wp_head',    [ $this, 'add_sitemap_link' ], 5 );
    }

    /**
     * Строка Sitemap: в robots.txt.
     */
    public function add_sitemap_line( $output, $public ) {
        if ( ! AMOG_Sitemap_Settings::is_enabled() ) {
            return $output;
        }

        // Дедуп: если любая строка Sitemap: уже есть — не трогаем.
        if ( preg_match( '/^\s*Sitemap:/im', $output ) ) {
            return $output;
        }

        $url = $this->get_sitemap_url();

        // Нормализуем: убираем хвостовые переводы строк, добавляем ровно один блок.
        return rtrim( $output ) . "\n\nSitemap: " . $url . "\n";
    }

    /**
     * <link rel="sitemap"> в <head> на главной.
     */
    public function add_sitemap_link() {
        if ( self::$link_printed ) return;
        if ( ! AMOG_Sitemap_Settings::is_enabled() ) return;
        if ( ! is_front_page() ) return;

        self::$link_printed = true;

        printf(
            '<link rel="sitemap" type="application/xml" title="Sitemap" href="%s" />' . "\n",
            esc_url( $this->get_sitemap_url() )
        );
    }

    /**
     * URL sitemap index.
     */
    private function get_sitemap_url() {
        return trailingslashit( home_url() ) . 'sitemap_index.xml';
    }
}