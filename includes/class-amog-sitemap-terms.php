<?php
/**
 * Urlset для терминов таксономий (с пагинацией).
 *
 * Уважает:
 *  - _seo_robots на термине
 *  - _seo_cascade_noindex на термине и его родителях
 *  - свои настройки skip_category / skip_tag
 *
 * lastmod берётся из term meta _amog_lastmod (см. AMOG_Sitemap_Lastmod).
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
            exit;
        }

        // Если таксономия в skip_taxonomies — 404.
        $skip_taxes = (array) AMOG_Sitemap_Settings::get( 'skip_taxonomies' );
        if ( ! empty( $skip_taxes ) && in_array( $tax, $skip_taxes, true ) ) {
            status_header( 404 );
            exit;
        }

        $page = max( 1, (int) get_query_var( 'amog_sitemap_page' ) );

        $cached = AMOG_Sitemap_Cache::get( "tax_{$tax}_{$page}" );
        if ( $cached ) {
            echo $cached;
            exit;
        }

        $xml = $this->build( $tax, $page );
        AMOG_Sitemap_Cache::set( "tax_{$tax}_{$page}", $xml );
        echo $xml;
        exit;
    }

    public static function count_pages( $tax ) {
        $per_file = (int) AMOG_Sitemap_Settings::get( 'per_file' );
        if ( $per_file < 1 ) $per_file = 1000;

        $count = wp_count_terms([
            'taxonomy'   => $tax,
            'hide_empty' => true,
        ]);
        if ( is_wp_error( $count ) ) return 1;

        return max( 1, (int) ceil( $count / $per_file ) );
    }

    /**
     * lastmod таксономии — для sitemapindex.
     */
    public static function get_lastmod( $tax ) {
        return AMOG_Sitemap_Lastmod::get_for_taxonomy( $tax );
    }

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

        $lines   = [];
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        if ( ! is_wp_error( $terms ) ) {
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
            }
        }

        $lines[] = '</urlset>';
        return implode( "\n", $lines );
    }

    /**
     * Проверка noindex для термина.
     *
     * 1. Собственный _seo_robots.
     * 2. _seo_cascade_noindex на термине или любом родителе.
     * 3. Свои настройки skip_category / skip_tag.
     */
    private function is_noindex( $term ) {
        $robots = get_term_meta( $term->term_id, '_seo_robots', true );
        if ( $robots && strpos( $robots, 'noindex' ) !== false ) {
            return true;
        }

        if ( $this->has_cascade_noindex( $term ) ) {
            return true;
        }

        // Своя настройка skip_taxonomies (уже включает category/post_tag
        // через обратную совместимость в Settings::get()).
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
}