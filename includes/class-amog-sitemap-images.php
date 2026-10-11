<?php
/**
 * Image sitemap: /sitemap-images.xml, /sitemap-images-2.xml, ...
 *
 * Источники изображений:
 *  - featured image
 *  - _seo_og_image (стандартный мета-ключ, ставит My SEO или вручную)
 *  - ACF-поля типа image/gallery
 *
 * lastmod в UTC — из post_modified_gmt.
 * Пагинация по per_file из настроек.
 * Пустой urlset (ни одной картинки на странице) отдаёт 404.
 *
 * count_pages() возвращает 0, если вообще нет публичных постов —
 * чтобы Index не ссылался на несуществующую карту.
 */
class AMOG_Sitemap_Images {

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'maybe_render' ], 20 );
    }

    public function maybe_render() {
        $kind = get_query_var( 'amog_sitemap' );
        if ( $kind !== 'images' ) return;

        if ( ! AMOG_Sitemap_Settings::get( 'images_enabled' ) ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        $page = max( 1, (int) get_query_var( 'amog_sitemap_page' ) );

        $cached = AMOG_Sitemap_Cache::get( "images_{$page}" );
        if ( $cached ) {
            $this->send_headers();
            echo $this->xml_declaration();
            echo $cached;
            exit;
        }

        $xml = $this->build( $page );
        if ( $xml === '' ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        AMOG_Sitemap_Cache::set( "images_{$page}", $xml );
        $this->send_headers();
        echo $this->xml_declaration();
        echo $xml;
        exit;
    }

    /**
     * Количество страниц image sitemap.
     * 0 — если нет публичных постов без attachment.
     */
    public static function count_pages() {
        $types = get_post_types( [ 'public' => true ], 'names' );
        unset( $types['attachment'] );
        if ( empty( $types ) ) return 0;

        $count = 0;
        foreach ( $types as $type ) {
            $obj = wp_count_posts( $type );
            if ( isset( $obj->publish ) ) {
                $count += (int) $obj->publish;
            }
        }
        if ( $count < 1 ) return 0;

        $per_file = (int) AMOG_Sitemap_Settings::get( 'per_file' );
        if ( $per_file < 1 ) $per_file = 1000;

        return (int) ceil( $count / $per_file );
    }

    /**
     * lastmod image sitemap = MAX(post_modified_gmt) по всем публичным типам без attachment.
     */
    public static function get_lastmod() {
        global $wpdb;

        $types = get_post_types( [ 'public' => true ], 'names' );
        unset( $types['attachment'] );
        if ( empty( $types ) ) return '';

        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}
             WHERE post_type IN ($placeholders) AND post_status = 'publish'",
            ...array_values( $types )
        ) );

        if ( ! $date ) return '';
        return gmdate( 'c', strtotime( $date . ' UTC' ) );
    }

    /**
     * @return string XML или '' если ни одного URL.
     */
    private function build( $page ) {
        $per_file = (int) AMOG_Sitemap_Settings::get( 'per_file' );
        if ( $per_file < 1 ) $per_file = 1000;

        $offset = ( $page - 1 ) * $per_file;

        $post_types = get_post_types( [ 'public' => true ], 'names' );
        unset( $post_types['attachment'] );

        $query = new WP_Query([
            'post_type'      => array_values( $post_types ),
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

            $images = $this->collect_images( $post_id );
            if ( empty( $images ) ) continue;

            $url = get_permalink( $post_id );
            if ( ! $url || is_wp_error( $url ) ) continue;

            $lastmod = $this->get_lastmod_utc( $post );

            $chunks   = [];
            $chunks[] = '<url>';
            $chunks[] = '<loc>' . esc_url( $url ) . '</loc>';
            if ( $lastmod ) {
                $chunks[] = '<lastmod>' . esc_html( $lastmod ) . '</lastmod>';
            }

            foreach ( $images as $img ) {
                $chunks[] = '<image:image>';
                $chunks[] = '<image:loc>' . esc_url( $img['url'] ) . '</image:loc>';
                if ( ! empty( $img['title'] ) ) {
                    $chunks[] = '<image:title>' . esc_html( $img['title'] ) . '</image:title>';
                }
                if ( ! empty( $img['caption'] ) ) {
                    $chunks[] = '<image:caption>' . esc_html( $img['caption'] ) . '</image:caption>';
                }
                $chunks[] = '</image:image>';
            }

            $chunks[] = '</url>';
            $lines[]  = implode( '', $chunks );
            $url_count++;
        }

        if ( $url_count === 0 ) {
            return '';
        }

        $out   = [];
        $out[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
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

    private function collect_images( $post_id ) {
        $images = [];
        $seen   = [];

        // Featured
        if ( has_post_thumbnail( $post_id ) ) {
            $thumb_id = (int) get_post_thumbnail_id( $post_id );
            $url      = get_the_post_thumbnail_url( $post_id, 'full' );
            if ( $url && ! isset( $seen[ $url ] ) ) {
                $seen[ $url ] = true;
                $images[] = [
                    'url'     => $url,
                    'title'   => get_the_title( $thumb_id ),
                    'caption' => wp_get_attachment_caption( $thumb_id ),
                ];
            }
        }

        // _seo_og_image
        $og = get_post_meta( $post_id, '_seo_og_image', true );
        if ( $og && ! isset( $seen[ $og ] ) ) {
            $seen[ $og ] = true;
            $images[] = [ 'url' => $og, 'title' => '', 'caption' => '' ];
        }

        // ACF: image / gallery
        if ( function_exists( 'get_fields' ) ) {
            $fields = get_fields( $post_id );
            if ( is_array( $fields ) ) {
                foreach ( $fields as $val ) {
                    $this->collect_acf_images( $val, $images, $seen );
                }
            }
        }

        return $images;
    }

    private function collect_acf_images( $val, &$images, &$seen ) {
        if ( is_array( $val ) ) {
            if ( isset( $val['url'] ) && is_string( $val['url'] ) && preg_match( '#^https?://#i', $val['url'] ) ) {
                $url = $val['url'];
                if ( ! isset( $seen[ $url ] ) ) {
                    $seen[ $url ] = true;
                    $images[] = [
                        'url'     => $url,
                        'title'   => isset( $val['title'] ) ? (string) $val['title'] : '',
                        'caption' => isset( $val['caption'] ) ? (string) $val['caption'] : '',
                    ];
                }
                return;
            }

            foreach ( $val as $item ) {
                $this->collect_acf_images( $item, $images, $seen );
            }
        }
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