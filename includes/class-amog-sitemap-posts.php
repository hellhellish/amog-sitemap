<?php
/**
 * Urlset для постов, страниц и CPT (с пагинацией).
 *
 * lastmod в UTC — из post_modified_gmt, а не из локальной даты.
 * Пустой urlset (все посты noindex или страница за пределами данных) отдаёт 404.
 *
 * count_pages() возвращает 0, если опубликованных постов нет —
 * чтобы Index не ссылался на несуществующую карту.
 */
class AMOG_Sitemap_Posts {

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'maybe_render' ], 20 );
    }

    public function maybe_render() {
        $kind = get_query_var( 'amog_sitemap' );
        if ( $kind !== 'type' ) return;

        $type = sanitize_key( get_query_var( 'amog_sitemap_type' ) );
        if ( ! $type || ! post_type_exists( $type ) ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        $page = max( 1, (int) get_query_var( 'amog_sitemap_page' ) );

        // Кэш — отдаём сразу с хедерами.
        $cached = AMOG_Sitemap_Cache::get( "type_{$type}_{$page}" );
        if ( $cached ) {
            $this->send_headers();
            echo $this->xml_declaration();
            echo $cached;
            exit;
        }

        // Собираем. Пусто — 404 без вывода.
        $xml = $this->build( $type, $page );
        if ( $xml === '' ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        AMOG_Sitemap_Cache::set( "type_{$type}_{$page}", $xml );
        $this->send_headers();
        echo $this->xml_declaration();
        echo $xml;
        exit;
    }

    /**
     * Количество страниц urlset.
     * 0 — если опубликованных постов нет.
     */
    public static function count_pages( $type ) {
        $total = wp_count_posts( $type );
        $count = isset( $total->publish ) ? (int) $total->publish : 0;
        if ( $count < 1 ) return 0;

        $per_file = (int) AMOG_Sitemap_Settings::get( 'per_file' );
        if ( $per_file < 1 ) $per_file = 1000;

        return (int) ceil( $count / $per_file );
    }

    public static function get_lastmod( $type ) {
        global $wpdb;
        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = 'publish'",
            $type
        ) );
        if ( ! $date ) return '';
        return gmdate( 'c', strtotime( $date . ' UTC' ) );
    }

    /**
     * @return string XML или '' если ни одного URL.
     */
    private function build( $type, $page ) {
        $per_file = (int) AMOG_Sitemap_Settings::get( 'per_file' );
        if ( $per_file < 1 ) $per_file = 1000;

        $offset = ( $page - 1 ) * $per_file;

        $query = new WP_Query([
            'post_type'      => $type,
            'post_status'    => 'publish',
            'posts_per_page' => $per_file,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        $lines     = [];
        $url_count = 0;

        foreach ( $query->posts as $post ) {
            $post_id = $post->ID;

            if ( $this->is_noindex( $post_id ) ) continue;

            $link = get_permalink( $post_id );
            if ( ! $link || is_wp_error( $link ) ) continue;

            $lastmod = $this->get_lastmod_utc( $post );

            $lines[] = sprintf(
                "<url><loc>%s</loc>%s<changefreq>weekly</changefreq><priority>0.6</priority></url>",
                esc_url( $link ),
                $lastmod ? '<lastmod>' . esc_html( $lastmod ) . '</lastmod>' : ''
            );
            $url_count++;
        }

        if ( $url_count === 0 ) {
            return '';
        }

        $out   = [];
        $out[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $out   = array_merge( $out, $lines );
        $out[] = '</urlset>';

        return implode( "\n", $out );
    }

    /**
     * lastmod в UTC из post_modified_gmt.
     */
    private function get_lastmod_utc( $post ) {
        if ( empty( $post->post_modified_gmt ) || $post->post_modified_gmt === '0000-00-00 00:00:00' ) {
            return '';
        }
        return gmdate( 'c', strtotime( $post->post_modified_gmt . ' UTC' ) );
    }

    /**
     * Уважаем _seo_robots — стандартный мета-ключ,
     * не привязанный к конкретному SEO-плагину.
     */
    private function is_noindex( $post_id ) {
        $robots = get_post_meta( $post_id, '_seo_robots', true );
        if ( $robots && strpos( $robots, 'noindex' ) !== false ) {
            return true;
        }
        return false;
    }

    /**
     * XML-хедеры. Только перед реальным выводом тела.
     */
    private function send_headers() {
        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, follow', true );
    }

    /**
     * XML-декларация. Только вместе с телом.
     */
    private function xml_declaration() {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    }
}