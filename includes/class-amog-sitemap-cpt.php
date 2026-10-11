<?php
/**
 * Urlset для CPT-архивов (например /blog-post/, /kursy-v-spb/).
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
            exit;
        }

        $cached = AMOG_Sitemap_Cache::get( 'cpt_archive' );
        if ( $cached ) {
            echo $cached;
            exit;
        }

        $xml = $this->build();
        AMOG_Sitemap_Cache::set( 'cpt_archive', $xml );
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
            if ( $type->name === 'post' ) continue; // архив post = home, не дублируем

            // Исключаем типы из skip_post_types
            if ( ! empty( $skip ) && in_array( $type->name, $skip, true ) ) continue;

            $types[] = $type->name;
        }

        return $types;
    }

    /**
     * lastmod архива CPT = MAX(post_modified_gmt) по его постам.
     * Для sitemapindex.
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

    private function build() {
        $s = AMOG_Sitemap_Settings::get();

        $lines   = [];
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ( self::get_archive_types() as $type_name ) {
            // Своя настройка noindex для CPT-архивов
            if ( ! empty( $s['skip_cpt_archive'] ) ) continue;

            $url = get_post_type_archive_link( $type_name );
            if ( ! $url ) continue;

            $lastmod = self::get_lastmod_for_type( $type_name );

            $lines[] = sprintf(
                "<url><loc>%s</loc>%s<changefreq>daily</changefreq><priority>0.7</priority></url>",
                esc_url( $url ),
                $lastmod ? '<lastmod>' . esc_html( $lastmod ) . '</lastmod>' : ''
            );
        }

        $lines[] = '</urlset>';
        return implode( "\n", $lines );
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
}