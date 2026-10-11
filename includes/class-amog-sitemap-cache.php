<?php
/**
 * Кэш XML sitemap через transient.
 *
 * Ключи: amog_sitemap_cache_{key}_v{version}
 * Версии хранятся в опции amog_sitemap_cache_version — массив [base_key => int].
 * Инвалидация = инкремент версии, старые транзиенты уходят по TTL.
 *
 * Никаких LIKE-запросов и массовых DELETE в горячем пути.
 */
class AMOG_Sitemap_Cache {

    const PREFIX  = 'amog_sitemap_cache_';
    const VERSION = 'amog_sitemap_cache_version';

    /**
     * Буфер «грязных» ключей в рамках одного запроса.
     * Сбрасывается в опцию один раз на shutdown.
     *
     * @var array<string,bool>
     */
    private static $dirty = [];

    /**
     * Хуки инвалидации.
     */
    public static function register_hooks() {
        add_action( 'save_post',      [ __CLASS__, 'on_save_post' ], 20, 3 );
        add_action( 'deleted_post',   [ __CLASS__, 'on_post_change' ], 20 );
        add_action( 'trashed_post',   [ __CLASS__, 'on_post_change' ], 20 );
        add_action( 'untrashed_post', [ __CLASS__, 'on_post_change' ], 20 );

        add_action( 'edited_term',    [ __CLASS__, 'on_term_change' ], 20, 3 );
        add_action( 'created_term',   [ __CLASS__, 'on_term_change' ], 20, 3 );
        add_action( 'delete_term',    [ __CLASS__, 'on_term_change' ], 20, 3 );

        // Сброс буфера в опцию — один раз за запрос.
        add_action( 'shutdown', [ __CLASS__, 'commit' ] );
    }

    /**
     * save_post: точечная инвалидация по типу поста и его таксономиям.
     */
    public static function on_save_post( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $status = get_post_status( $post_id );
        if ( $status !== 'publish' ) {
            // Черновики и мусор в sitemap не попадают — не инвалидируем.
            return;
        }

        self::bump_for_post( $post_id, $post->post_type );

        // Обновляем _amog_lastmod у терминов поста.
        AMOG_Sitemap_Lastmod::update_for_post( $post_id );
    }

    /**
     * deleted_post / trashed_post / untrashed_post — знаем только ID.
     */
    public static function on_post_change( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return;

        self::bump_for_post( $post_id, $post->post_type );
        AMOG_Sitemap_Lastmod::update_for_post( $post_id );
    }

    /**
     * edited_term / created_term / delete_term.
     */
    public static function on_term_change( $term_id, $tt_id, $taxonomy ) {
        self::bump_tax( $taxonomy );
    }

    /**
     * Инвалидация по посту: тип поста + все его таксономии
     * + index + images + cpt_archive.
     *
     * images и cpt_archive инкрементируются на любой publish-пост,
     * потому что картинки и lastmod архива зависят от постов любого типа.
     */
    private static function bump_for_post( $post_id, $post_type ) {
        $keys = [ 'index', 'images', 'cpt_archive', "type_{$post_type}" ];

        $taxonomies = get_object_taxonomies( $post_type, 'names' );
        foreach ( $taxonomies as $tax ) {
            $keys[] = "tax_{$tax}";
        }

        self::bump_many( $keys );
    }

    /**
     * Инвалидация по таксономии: сама таксономия + index.
     */
    private static function bump_tax( $taxonomy ) {
        self::bump_many( [ 'index', "tax_{$taxonomy}" ] );
    }

    /**
     * Пометить ключи грязными. Реальный bump — на shutdown.
     */
    public static function bump_many( array $keys ) {
        foreach ( $keys as $k ) {
            self::$dirty[ $k ] = true;
        }
    }

    /**
     * Записать накопленные bump'ы в опцию одним update_option.
     */
    public static function commit() {
        if ( empty( self::$dirty ) ) return;

        $versions = get_option( self::VERSION, [] );
        if ( ! is_array( $versions ) ) $versions = [];

        foreach ( array_keys( self::$dirty ) as $k ) {
            $versions[ $k ] = isset( $versions[ $k ] ) ? (int) $versions[ $k ] + 1 : 1;
        }

        update_option( self::VERSION, $versions, false );
        self::$dirty = [];
    }

    /**
     * Версия для базового ключа.
     */
    public static function version( $base ) {
        $versions = get_option( self::VERSION, [] );
        if ( ! is_array( $versions ) ) return 1;
        return isset( $versions[ $base ] ) ? (int) $versions[ $base ] : 1;
    }

    /**
     * Полный ключ транзиента: base_vN.
     */
    public static function key( $base ) {
        return $base . '_v' . self::version( $base );
    }

    public static function get( $base ) {
        return get_transient( self::PREFIX . self::key( $base ) );
    }

    public static function set( $base, $value ) {
        $ttl = (int) AMOG_Sitemap_Settings::get( 'cache_ttl' );
        if ( $ttl < 60 ) $ttl = 60;
        set_transient( self::PREFIX . self::key( $base ), $value, $ttl );
    }

    public static function forget( $base ) {
        delete_transient( self::PREFIX . self::key( $base ) );
    }

    /**
     * Полная очистка — только для деактивации и кнопки «Очистить кэш».
     * В горячем пути НЕ вызывается.
     */
    public static function flush_all() {
        global $wpdb;

        $like    = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
        $like_to = $wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%';

        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s OR option_name LIKE %s",
            $like,
            $like_to
        ) );

        foreach ( $names as $name ) {
            if ( strpos( $name, '_transient_timeout_' ) === 0 ) {
                delete_option( $name );
            } else {
                $key = str_replace( '_transient_', '', $name );
                delete_transient( $key );
            }
        }

        // Сбрасываем версии — следующий рендер сгенерирует заново.
        delete_option( self::VERSION );
    }
}