<?php
/**
 * Управление _amog_lastmod у терминов.
 *
 * _amog_lastmod = gmdate('c') от post_modified_gmt самого свежего поста,
 * привязанного к термину. Обновляется на save_post.
 *
 * Если meta пусто (старые термины) — ленивая миграция: считаем SQL,
 * пишем в meta, дальше читаем только из meta.
 */
class AMOG_Sitemap_Lastmod {

    const META = '_amog_lastmod';

    /**
     * Обновить _amog_lastmod для всех терминов, к которым привязан пост.
     */
    public static function update_for_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return;

        $taxonomies = get_object_taxonomies( $post->post_type, 'names' );
        if ( empty( $taxonomies ) ) return;

        $modified = $post->post_modified_gmt;
        if ( ! $modified ) return;

        $date = gmdate( 'c', strtotime( $modified . ' UTC' ) );

        foreach ( $taxonomies as $tax ) {
            $terms = wp_get_object_terms( $post_id, $tax, [ 'fields' => 'ids' ] );
            if ( is_wp_error( $terms ) || empty( $terms ) ) continue;

            foreach ( $terms as $term_id ) {
                $current = get_term_meta( $term_id, self::META, true );
                // Обновляем только если новый lastmod свежее.
                if ( ! $current || strcmp( $date, $current ) > 0 ) {
                    update_term_meta( $term_id, self::META, $date );
                }
            }
        }
    }

    /**
     * Получить lastmod термина. Если meta пусто — ленивая миграция.
     */
    public static function get_for_term( $term_id, $taxonomy ) {
        $meta = get_term_meta( $term_id, self::META, true );
        if ( $meta ) return $meta;

        // Ленивая миграция: считаем SQL, пишем, возвращаем.
        $date = self::compute_term_lastmod( $term_id, $taxonomy );
        if ( $date ) {
            update_term_meta( $term_id, self::META, $date );
        }
        return $date;
    }

    /**
     * MAX(post_modified_gmt) по постам, привязанным к термину.
     */
    private static function compute_term_lastmod( $term_id, $taxonomy ) {
        global $wpdb;

        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(p.post_modified_gmt)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tt.taxonomy = %s
               AND tt.term_id = %d
               AND p.post_status = 'publish'",
            $taxonomy,
            $term_id
        ) );

        if ( ! $date ) return '';
        return gmdate( 'c', strtotime( $date . ' UTC' ) );
    }

    /**
     * MAX(_amog_lastmod) по всем терминам таксономии.
     * Для sitemapindex.
     */
    public static function get_for_taxonomy( $taxonomy ) {
        global $wpdb;

        $meta_key = self::META;
        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(tm.meta_value)
             FROM {$wpdb->termmeta} tm
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
             WHERE tt.taxonomy = %s
               AND tm.meta_key = %s",
            $taxonomy,
            $meta_key
        ) );

        if ( $date ) return $date;

        // Fallback: если meta ни у кого нет — считаем SQL по всей таксономии.
        return self::compute_taxonomy_lastmod( $taxonomy );
    }

    private static function compute_taxonomy_lastmod( $taxonomy ) {
        global $wpdb;

        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(p.post_modified_gmt)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tt.taxonomy = %s
               AND p.post_status = 'publish'",
            $taxonomy
        ) );

        if ( ! $date ) return '';
        return gmdate( 'c', strtotime( $date . ' UTC' ) );
    }
}