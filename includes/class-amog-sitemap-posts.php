<?php
/**
 * Urlset для постов, страниц и CPT (с пагинацией).
 *
 * lastmod в UTC — из post_modified_gmt, а не из локальной даты.
 */
class AMOG_Sitemap_Posts {

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'maybe_render' ], 20 );
    }

    public function maybe_render() {
        $kind = get_query_var( 'amog_sitemap' );
        if ( $kind !== 'type' ) return;

        $type = sanitize_key( get_query_var( 'amog_sitemap_type' ) );
        if ( ! $type ) return;

        $page = max( 1, (int) get_query_var( 'amog_sitemap_page' ) );

        if ( ! post_type_exists( $type ) ) {
            status_header( 404 );
            exit;
        }

        $cached = AMOG_Sitemap_Cache::get( "type_{$type}_{$page}" );
        if ( $cached ) {
            echo $cached;
            exit;
        }

        $xml = $this->build( $type, $page );
        AMOG_Sitemap_Cache::set( "type_{$type}_{$page}", $xml );
        echo $xml;
        exit;
    }

    public static function count_pages( $type ) {
        $per_file = (int) AMOG_Sitemap_Settings::get( 'per_file' );
        if ( $per_file < 1 ) $per_file = 1000;

        $total = wp_count_posts( $type );
        $count = isset( $total->publish ) ? (int) $total->publish : 0;

        return max( 1, (int) ceil( $count / $per_file ) );
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

        $lines   = [];
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

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
        }

        $lines[] = '</urlset>';
        return implode( "\n", $lines );
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
}