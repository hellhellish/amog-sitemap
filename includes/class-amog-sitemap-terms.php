<?php
/**
 * Urlset для терминов таксономий (с пагинацией).
 *
 * Уважает:
 *  - _seo_robots на термине
 *  - _seo_cascade_noindex на термине и его родителях
 *  - свои настройки skip_taxonomies (включая легаси skip_category / skip_tag)
 *
 * lastmod берётся из term meta _amog_lastmod (см. AMOG_Sitemap_Lastmod).
 * Пустой urlset отдаёт 404.
 *
 * count_pages() возвращает 0, если терминов нет —
 * чтобы Index не ссылался на несуществующую карту.
 */
class AMOG_Sitemap_Terms {

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'maybe_render' ], 20 );
    }

    public function maybe_render() {
        $kind = get_query_var( 'amog_sitemap' );
        if ( $kind !== 'tax' ) return;

        $tax = sanitize_key( get_query_var( 'amog_sitemap_tax' ) );
        if ( ! $tax || ! taxonomy_exists( $tax ) ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        // Если таксономия в skip_taxonomies — 404.
        $skip_taxes = (array) AMOG_Sitemap_Settings::get( 'skip_taxonomies' );
        if ( ! empty( $skip_taxes ) && in_array( $tax, $skip_taxes, true ) ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        $page = max( 1, (int) get_query_var( 'amog_sitemap_page' ) );

        $cached = AMOG_Sitemap_Cache::get( "tax_{$tax}_{$page}" );
        if ( $cached ) {
            $this->send_headers();
            echo $this->xml_declaration();
            echo $cached;
            exit;
        }

        $xml = $this->build( $tax, $page );
        if ( $xml === '' ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        AMOG_Sitemap_Cache::set( "tax_{$tax}_{$page}", $xml );
        $this->send_headers();
        echo $this->xml_declaration();
        echo $xml;
        exit;
    }

    /**
     * Количество страниц urlset.
     * 0 — если терминов нет.
     */
    public static function count_pages( $tax ) {
        $count = wp_count_terms([
            'taxonomy'   => $tax,
            'hide_empty' => true,
        ]);
        if ( is_wp_error( $count ) || (int) $count < 1 ) return 0;

        $per_file = (int) AMOG_Sitemap_Settings::get( 'per_file' );
        if ( $per_file < 1 ) $per_file = 1000;

        return (int) ceil( (int) $count / $per_file );
    }

    /**
     * lastmod таксономии — для sitemapindex.
     */
    public static function get_lastmod( $tax ) {
        return AMOG_Sitemap_Lastmod::get_for_taxonomy( $tax );
    }

    /**
     * @return string XML или '' если ни одного URL.
     */
    private function build( $tax, $page ) {
        $per_file = (int) AMOG_Sitemap_Settings::get( 'per_file' );
        if ( $per_file < 1 ) $per_file = 1000;

        $offset = ( $page - 1 ) * $per_file;

        $terms = get_terms([
            'taxonomy'   => $tax,
            'hide_empty' => true,
            'number'     => $per_file,
            'offset'     => $offset,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
        ]);

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return '';
        }

        $lines     = [];
        $url_count = 0;

        foreach ( $terms as $term ) {
            if ( $this->is_noindex( $term ) ) continue;

            $link = get_term_link( $term );
            if ( is_wp_error( $link ) || ! $link ) continue;

            $lastmod = AMOG_Sitemap_Lastmod::get_for_term( $term->term_id, $term->taxonomy );

            $lines[] = sprintf(
                "<url><loc>%s</loc>%s<changefreq>weekly</changefreq><priority>0.5</priority></url>",
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
     * Проверка noindex для термина.
     */
    private function is_noindex( $term ) {
        $robots = get_term_meta( $term->term_id, '_seo_robots', true );
        if ( $robots && strpos( $robots, 'noindex' ) !== false ) {
            return true;
        }

        if ( $this->has_cascade_noindex( $term ) ) {
            return true;
        }

        $skip_taxes = (array) AMOG_Sitemap_Settings::get( 'skip_taxonomies' );
        if ( ! empty( $skip_taxes ) && in_array( $term->taxonomy, $skip_taxes, true ) ) {
            return true;
        }

        return false;
    }

    private function has_cascade_noindex( $term ) {
        if ( get_term_meta( $term->term_id, '_seo_cascade_noindex', true ) ) {
            return true;
        }

        $ancestors = get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' );
        foreach ( $ancestors as $ancestor_id ) {
            if ( get_term_meta( $ancestor_id, '_seo_cascade_noindex', true ) ) {
                return true;
            }
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