<?php
/**
 * Sitemap index: /sitemap_index.xml
 * Также хранит rewrite-правила для всех типов.
 *
 * В индекс попадают только те карты, которые:
 *  - имеют count_pages() > 0 (непустой источник), и
 *  - либо ещё не кэшированы (первый прогрев), либо есть в кэше и непустые.
 *
 * Для не-index kind Index НЕ печатает ни заголовков, ни XML-декларации:
 * это делает класс-владелец тела (Posts/Terms/CPT/Images).
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

        add_rewrite_rule(
            '^sitemap-tax-([a-z0-9_-]+?)(?:-(\d+))?\.xml$',
            'index.php?amog_sitemap=tax&amog_sitemap_tax=$matches[1]&amog_sitemap_page=$matches[2]',
            'top'
        );

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

        if ( $kind !== 'index' ) {
            return;
        }

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, follow', true );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

        $this->render_index();
        exit;
    }

    /**
     * Рендер тела sitemap index.
     *
     * Public — чтобы Site Health мог вызвать напрямую, без HTTP-запроса.
     * Метод не ставит хедеры и не печатает XML-декларацию — только тело.
     */
    public function render_index() {
        $cache_key = 'index';
        $cached    = AMOG_Sitemap_Cache::get( $cache_key );

        if ( $cached ) {
            echo $cached;
            return;
        }

        $lines   = [];
        $lines[] = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // Post types
        foreach ( $this->get_indexable_post_types() as $type ) {
            $pages = AMOG_Sitemap_Posts::count_pages( $type );
            if ( $pages < 1 ) continue;

            for ( $i = 1; $i <= $pages; $i++ ) {
                if ( ! $this->include_in_index( "type_{$type}_{$i}" ) ) {
                    continue;
                }

                $url = $i === 1
                    ? home_url( "/sitemap-{$type}.xml" )
                    : home_url( "/sitemap-{$type}-{$i}.xml" );

                $lastmod = AMOG_Sitemap_Posts::get_lastmod( $type );

                $lines[] = $this->sitemap_entry( $url, $lastmod );
            }
        }

        // Таксономии
        foreach ( $this->get_indexable_taxonomies() as $tax ) {
            $pages = AMOG_Sitemap_Terms::count_pages( $tax );
            if ( $pages < 1 ) continue;

            for ( $i = 1; $i <= $pages; $i++ ) {
                if ( ! $this->include_in_index( "tax_{$tax}_{$i}" ) ) {
                    continue;
                }

                $url = $i === 1
                    ? home_url( "/sitemap-tax-{$tax}.xml" )
                    : home_url( "/sitemap-tax-{$tax}-{$i}.xml" );

                $lastmod = AMOG_Sitemap_Terms::get_lastmod( $tax );

                $lines[] = $this->sitemap_entry( $url, $lastmod );
            }
        }

        // CPT-архивы
        if (
            AMOG_Sitemap_Settings::get( 'cpt_archives' ) &&
            ! AMOG_Sitemap_Settings::get( 'skip_cpt_archive' ) &&
            AMOG_Sitemap_CPT::has_any_archive()
        ) {
            if ( $this->include_in_index( 'cpt_archive' ) ) {
                $lastmod = AMOG_Sitemap_CPT::get_lastmod();
                $lines[] = $this->sitemap_entry( home_url( '/sitemap-cpt-archive.xml' ), $lastmod );
            }
        }

        // Image sitemap
        if ( AMOG_Sitemap_Settings::get( 'images_enabled' ) ) {
            $pages = AMOG_Sitemap_Images::count_pages();
            if ( $pages > 0 ) {
                $lastmod = AMOG_Sitemap_Images::get_lastmod();

                for ( $i = 1; $i <= $pages; $i++ ) {
                    if ( ! $this->include_in_index( "images_{$i}" ) ) {
                        continue;
                    }

                    $url = $i === 1
                        ? home_url( '/sitemap-images.xml' )
                        : home_url( "/sitemap-images-{$i}.xml" );

                    $lines[] = $this->sitemap_entry( $url, $lastmod );
                }
            }
        }

        $lines[] = '</sitemapindex>';

        $xml = implode( "\n", $lines );
        AMOG_Sitemap_Cache::set( $cache_key, $xml );
        echo $xml;
    }

    /**
     * Включать ли карту в индекс.
     * Второй барьер после count_pages().
     */
    private function include_in_index( $cache_base ) {
        $cached = AMOG_Sitemap_Cache::get( $cache_base );
        if ( $cached === false ) {
            return true;
        }
        return ! empty( $cached );
    }

    /**
     * Строка <sitemap> с опциональным lastmod.
     */
    private function sitemap_entry( $url, $lastmod ) {
        return sprintf(
            "<sitemap><loc>%s</loc>%s</sitemap>",
            esc_url( $url ),
            $lastmod ? '<lastmod>' . esc_html( $lastmod ) . '</lastmod>' : ''
        );
    }

    public function get_indexable_post_types() {
        $types = get_post_types( [ 'public' => true ], 'names' );
        unset( $types['attachment'] );

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

        $skip = (array) AMOG_Sitemap_Settings::get( 'skip_taxonomies' );
        if ( ! empty( $skip ) ) {
            foreach ( $skip as $slug ) {
                unset( $taxes[ $slug ] );
            }
        }

        return array_values( $taxes );
    }
}