<?php
/**
 * Sitemap index: /sitemap_index.xml
 * Также хранит rewrite-правила для всех типов.
 */
class AMOG_Sitemap_Index {

    public function __construct() {
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'query_vars' ] );
        add_action( 'template_redirect', [ $this, 'maybe_render' ] );
    }

    /**
     * Rewrite-правила для index и всех urlset-файлов.
     */
    public static function add_rewrite_rules() {
        add_rewrite_rule(
            '^sitemap_index\.xml$',
            'index.php?amog_sitemap=index',
            'top'
        );

        add_rewrite_rule(
            '^sitemap-images(?:-(\d+))?\.xml$',
            'index.php?amog_sitemap=images&amog_sitemap_page=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^sitemap-cpt-archive\.xml$',
            'index.php?amog_sitemap=cpt-archive',
            'top'
        );

        // tax-{taxonomy}[-page]
        add_rewrite_rule(
            '^sitemap-tax-([a-z0-9_-]+?)(?:-(\d+))?\.xml$',
            'index.php?amog_sitemap=tax&amog_sitemap_tax=$matches[1]&amog_sitemap_page=$matches[2]',
            'top'
        );

        // {type}[-page]
        add_rewrite_rule(
            '^sitemap-([a-z0-9_-]+?)(?:-(\d+))?\.xml$',
            'index.php?amog_sitemap=type&amog_sitemap_type=$matches[1]&amog_sitemap_page=$matches[2]',
            'top'
        );
    }

    public function query_vars( $vars ) {
        $vars[] = 'amog_sitemap';
        $vars[] = 'amog_sitemap_type';
        $vars[] = 'amog_sitemap_tax';
        $vars[] = 'amog_sitemap_page';
        return $vars;
    }

    public function maybe_render() {
        $kind = get_query_var( 'amog_sitemap' );
        if ( ! $kind ) return;

        if ( ! AMOG_Sitemap_Settings::is_enabled() ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, follow', true );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

        // Только index рендерится и завершается здесь.
        // Остальные kind (type/tax/cpt-archive/images) обрабатываются
        // в своих классах на template_redirect с приоритетом 20.
        if ( $kind === 'index' ) {
            $this->render_index();
            exit;
        }
    }

    private function render_index() {
        $cache_key = 'index';
        $cached    = AMOG_Sitemap_Cache::get( $cache_key );

        if ( $cached ) {
            echo $cached;
            return;
        }

        $now = gmdate( 'c' );

        $lines   = [];
        $lines[] = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // Post types
        foreach ( $this->get_indexable_post_types() as $type ) {
            $pages = AMOG_Sitemap_Posts::count_pages( $type );
            for ( $i = 1; $i <= $pages; $i++ ) {
                $url = $i === 1
                    ? home_url( "/sitemap-{$type}.xml" )
                    : home_url( "/sitemap-{$type}-{$i}.xml" );

                $lastmod = AMOG_Sitemap_Posts::get_lastmod( $type );

                $lines[] = sprintf(
                    "<sitemap><loc>%s</loc><lastmod>%s</lastmod></sitemap>",
                    esc_url( $url ),
                    esc_html( $lastmod ?: $now )
                );
            }
        }

        // Таксономии
        foreach ( $this->get_indexable_taxonomies() as $tax ) {
            $pages = AMOG_Sitemap_Terms::count_pages( $tax );
            for ( $i = 1; $i <= $pages; $i++ ) {
                $url = $i === 1
                    ? home_url( "/sitemap-tax-{$tax}.xml" )
                    : home_url( "/sitemap-tax-{$tax}-{$i}.xml" );

                $lastmod = AMOG_Sitemap_Terms::get_lastmod( $tax );

                $lines[] = sprintf(
                    "<sitemap><loc>%s</loc><lastmod>%s</lastmod></sitemap>",
                    esc_url( $url ),
                    esc_html( $lastmod ?: $now )
                );
            }
        }

        // CPT-архивы
        if ( AMOG_Sitemap_Settings::get( 'cpt_archives' ) ) {
            $archive_types = AMOG_Sitemap_CPT::get_archive_types();
            if ( ! empty( $archive_types ) ) {
                $lastmod = AMOG_Sitemap_CPT::get_lastmod();
                $lines[] = sprintf(
                    "<sitemap><loc>%s</loc><lastmod>%s</lastmod></sitemap>",
                    esc_url( home_url( '/sitemap-cpt-archive.xml' ) ),
                    esc_html( $lastmod ?: $now )
                );
            }
        }

        // Image sitemap
        if ( AMOG_Sitemap_Settings::get( 'images_enabled' ) ) {
            $lastmod = AMOG_Sitemap_Images::get_lastmod();
            $pages   = AMOG_Sitemap_Images::count_pages();

            for ( $i = 1; $i <= $pages; $i++ ) {
                $url = $i === 1
                    ? home_url( '/sitemap-images.xml' )
                    : home_url( "/sitemap-images-{$i}.xml" );

                $lines[] = sprintf(
                    "<sitemap><loc>%s</loc><lastmod>%s</lastmod></sitemap>",
                    esc_url( $url ),
                    esc_html( $lastmod ?: $now )
                );
            }
        }

        $lines[] = '</sitemapindex>';

        $xml = implode( "\n", $lines );
        AMOG_Sitemap_Cache::set( $cache_key, $xml );
        echo $xml;
    }

    public function get_indexable_post_types() {
        $types = get_post_types( [ 'public' => true ], 'names' );
        unset( $types['attachment'] );

        // Исключаем типы, отмеченные в skip_post_types
        $skip = (array) AMOG_Sitemap_Settings::get( 'skip_post_types' );
        if ( ! empty( $skip ) ) {
            foreach ( $skip as $slug ) {
                unset( $types[ $slug ] );
            }
        }

        return array_values( $types );
    }

    public function get_indexable_taxonomies() {
        $taxes = get_taxonomies( [ 'public' => true ], 'names' );

        // Исключаем таксономии из skip_taxonomies
        $skip = (array) AMOG_Sitemap_Settings::get( 'skip_taxonomies' );
        if ( ! empty( $skip ) ) {
            foreach ( $skip as $slug ) {
                unset( $taxes[ $slug ] );
            }
        }

        return array_values( $taxes );
    }
}