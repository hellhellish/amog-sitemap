<?php
/**
 * Urlset для CPT-архивов (например /blog-post/, /kursy-v-spb/).
 *
 * Пустой urlset (нет типов, нет постов, либо skip_cpt_archive) отдаёт 404.
 * has_any_archive() позволяет Index не включать пустой архив в индекс.
 */
class AMOG_Sitemap_CPT {

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'maybe_render' ], 20 );
    }

    public function maybe_render() {
        $kind = get_query_var( 'amog_sitemap' );
        if ( $kind !== 'cpt-archive' ) return;

        if ( ! AMOG_Sitemap_Settings::get( 'cpt_archives' ) ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        if ( AMOG_Sitemap_Settings::get( 'skip_cpt_archive' ) ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        $cached = AMOG_Sitemap_Cache::get( 'cpt_archive' );
        if ( $cached ) {
            $this->send_headers();
            echo $this->xml_declaration();
            echo $cached;
            exit;
        }

        $xml = $this->build();
        if ( $xml === '' ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        AMOG_Sitemap_Cache::set( 'cpt_archive', $xml );
        $this->send_headers();
        echo $this->xml_declaration();
        echo $xml;
        exit;
    }

    /**
     * Список публичных CPT с has_archive, кроме post.
     */
    public static function get_archive_types() {
        $types = [];

        $skip = (array) AMOG_Sitemap_Settings::get( 'skip_post_types' );

        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
            if ( empty( $type->has_archive ) ) continue;
            if ( $type->name === 'post' ) continue;

            if ( ! empty( $skip ) && in_array( $type->name, $skip, true ) ) continue;

            $types[] = $type->name;
        }

        return $types;
    }

    /**
     * Есть ли хотя бы один архивный тип с опубликованными постами.
     * Используется в Index, чтобы не включать пустой sitemap-cpt-archive.xml.
     */
    public static function has_any_archive() {
        $types = self::get_archive_types();
        if ( empty( $types ) ) return false;

        foreach ( $types as $type ) {
            $obj = wp_count_posts( $type );
            if ( isset( $obj->publish ) && (int) $obj->publish > 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * lastmod архива CPT = MAX(post_modified_gmt) по его постам.
     */
    public static function get_lastmod() {
        global $wpdb;

        $types = self::get_archive_types();
        if ( empty( $types ) ) return '';

        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}
             WHERE post_type IN ($placeholders) AND post_status = 'publish'",
            ...$types
        ) );

        if ( ! $date ) return '';
        return gmdate( 'c', strtotime( $date . ' UTC' ) );
    }

    /**
     * @return string XML или '' если ни одного URL.
     */
    private function build() {
        $lines     = [];
        $url_count = 0;

        foreach ( self::get_archive_types() as $type_name ) {
            $url = get_post_type_archive_link( $type_name );
            if ( ! $url || is_wp_error( $url ) ) continue;

            $lastmod = self::get_lastmod_for_type( $type_name );

            $lines[] = sprintf(
                "<url><loc>%s</loc>%s<changefreq>daily</changefreq><priority>0.7</priority></url>",
                esc_url( $url ),
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
     * lastmod одного архива.
     */
    private static function get_lastmod_for_type( $type_name ) {
        global $wpdb;

        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = 'publish'",
            $type_name
        ) );

        if ( ! $date ) return '';
        return gmdate( 'c', strtotime( $date . ' UTC' ) );
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