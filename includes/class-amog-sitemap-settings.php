<?php
/**
 * Единая точка чтения/записи настроек sitemap.
 */
class AMOG_Sitemap_Settings {

    const OPTION = 'amog_sitemap_settings';

    /**
     * Дефолты + значения из опции.
     */
    public static function get( $key = null, $default = null ) {
        $opts = get_option( self::OPTION, [] );

        $defaults = [
            'enabled'             => 1,
            'images_enabled'      => 1,
            'cpt_archives'        => 1,
            'per_file'            => 1000,
            'cache_ttl'           => DAY_IN_SECONDS,

            // Старые настройки noindex для архивов (обратная совместимость)
            'skip_category'       => 0,
            'skip_tag'            => 0,
            'skip_cpt_archive'    => 0,

            // Новые: массивы слагов для точечного исключения
            'skip_taxonomies'     => [],
            'skip_post_types'     => [],
        ];

        $opts = wp_parse_args( $opts, $defaults );

        // Обратная совместимость: старые флаги → новые массивы
        $opts = self::migrate_legacy_skip_flags( $opts );

        if ( $key === null ) return $opts;
        return $opts[ $key ] ?? $default;
    }

    /**
     * Если выставлены старые skip_category / skip_tag —
     * добавляем соответствующие слаги в skip_taxonomies.
     * Работает на чтение, в БД не пишет.
     */
    private static function migrate_legacy_skip_flags( $opts ) {
        if ( ! is_array( $opts['skip_taxonomies'] ) ) {
            $opts['skip_taxonomies'] = [];
        }

        if ( ! empty( $opts['skip_category'] ) && ! in_array( 'category', $opts['skip_taxonomies'], true ) ) {
            $opts['skip_taxonomies'][] = 'category';
        }

        if ( ! empty( $opts['skip_tag'] ) && ! in_array( 'post_tag', $opts['skip_taxonomies'], true ) ) {
            $opts['skip_taxonomies'][] = 'post_tag';
        }

        if ( ! is_array( $opts['skip_post_types'] ) ) {
            $opts['skip_post_types'] = [];
        }

        return $opts;
    }

    public static function update( $data ) {
        $opts = get_option( self::OPTION, [] );
        update_option( self::OPTION, array_merge( $opts, $data ) );
    }

    public static function is_enabled() {
        return (bool) self::get( 'enabled' );
    }
}