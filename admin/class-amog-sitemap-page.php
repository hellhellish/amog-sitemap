<?php
/**
 * Верхнеуровневое меню "AMOG Sitemap".
 */
class AMOG_Sitemap_Page {

    const SLUG = 'amog-sitemap';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_amog_sitemap_flush_cache', [ $this, 'handle_flush' ] );
    }

    public function add_page() {
        add_menu_page(
            'AMOG Sitemap',
            'AMOG Sitemap',
            'manage_options',
            self::SLUG,
            [ $this, 'render' ],
            'dashicons-networking',
            82
        );
    }

    public function register_settings() {
        register_setting(
            'amog_sitemap_group',
            AMOG_Sitemap_Settings::OPTION,
            [ 'sanitize_callback' => [ $this, 'sanitize' ] ]
        );
    }

    public function sanitize( $input ) {
        $out = AMOG_Sitemap_Settings::get();

        // Чекбоксы
        $out['enabled']          = ! empty( $input['enabled'] ) ? 1 : 0;
        $out['images_enabled']   = ! empty( $input['images_enabled'] ) ? 1 : 0;
        $out['cpt_archives']     = ! empty( $input['cpt_archives'] ) ? 1 : 0;
        $out['skip_cpt_archive'] = ! empty( $input['skip_cpt_archive'] ) ? 1 : 0;

        // Числа
        $per_file = isset( $input['per_file'] ) ? absint( $input['per_file'] ) : 1000;
        if ( $per_file < 100 )   $per_file = 100;
        if ( $per_file > 50000 ) $per_file = 50000;
        $out['per_file'] = $per_file;

        $ttl = isset( $input['cache_ttl'] ) ? absint( $input['cache_ttl'] ) : DAY_IN_SECONDS;
        if ( $ttl < 60 ) $ttl = 60;
        $out['cache_ttl'] = $ttl;

        // skip_taxonomies[] — массив слагов, только существующие таксономии
        $valid_taxes = array_keys( get_taxonomies( [ 'public' => true ], 'names' ) );
        $skip_taxes  = [];

        // Обратная совместимость: если в БД были старые флаги — переносим их
        if ( ! empty( $out['skip_category'] ) && in_array( 'category', $valid_taxes, true ) ) {
            $skip_taxes[] = 'category';
        }
        if ( ! empty( $out['skip_tag'] ) && in_array( 'post_tag', $valid_taxes, true ) ) {
            $skip_taxes[] = 'post_tag';
        }

        // То, что пришло из формы
        if ( ! empty( $input['skip_taxonomies'] ) && is_array( $input['skip_taxonomies'] ) ) {
            foreach ( $input['skip_taxonomies'] as $slug ) {
                $slug = sanitize_key( $slug );
                if ( $slug && in_array( $slug, $valid_taxes, true ) && ! in_array( $slug, $skip_taxes, true ) ) {
                    $skip_taxes[] = $slug;
                }
            }
        }

        $out['skip_taxonomies'] = $skip_taxes;

        // skip_post_types[] — массив слагов, только существующие публичные типы без attachment
        $valid_types = array_keys( get_post_types( [ 'public' => true ], 'names' ) );
        $valid_types = array_diff( $valid_types, [ 'attachment' ] );

        $skip_types = [];
        if ( ! empty( $input['skip_post_types'] ) && is_array( $input['skip_post_types'] ) ) {
            foreach ( $input['skip_post_types'] as $slug ) {
                $slug = sanitize_key( $slug );
                if ( $slug && in_array( $slug, $valid_types, true ) && ! in_array( $slug, $skip_types, true ) ) {
                    $skip_types[] = $slug;
                }
            }
        }
        $out['skip_post_types'] = $skip_types;

        // Сбрасываем старые флаги — они больше не UI
        $out['skip_category'] = 0;
        $out['skip_tag']      = 0;

        flush_rewrite_rules();

        return $out;
    }

    public function handle_flush() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Недостаточно прав.' );
        }
        check_admin_referer( 'amog_sitemap_flush_cache' );

        AMOG_Sitemap_Cache::flush_all();

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::SLUG, 'flushed' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $s = AMOG_Sitemap_Settings::get();
        ?>
        <div class="wrap">
            <h1>AMOG Sitemap</h1>

            <?php settings_errors( AMOG_Sitemap_Settings::OPTION ); ?>

            <?php if ( isset( $_GET['flushed'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Кэш sitemap очищен.</p>
                </div>
            <?php endif; ?>

            <p>
                Основной файл:
                <a href="<?php echo esc_url( home_url( '/sitemap_index.xml' ) ); ?>" target="_blank" rel="noopener">
                    <?php echo esc_html( home_url( '/sitemap_index.xml' ) ); ?>
                </a>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields( 'amog_sitemap_group' ); ?>

                <h2>Основное</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Sitemap</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr( AMOG_Sitemap_Settings::OPTION ); ?>[enabled]"
                                       value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
                                Включить XML sitemap
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Карта изображений</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr( AMOG_Sitemap_Settings::OPTION ); ?>[images_enabled]"
                                       value="1" <?php checked( ! empty( $s['images_enabled'] ) ); ?>>
                                Включить image sitemap
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Архивы CPT</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr( AMOG_Sitemap_Settings::OPTION ); ?>[cpt_archives]"
                                       value="1" <?php checked( ! empty( $s['cpt_archives'] ) ); ?>>
                                Включать архивы CPT в sitemap
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="amog_sitemap_per_file">URL на файл</label></th>
                        <td>
                            <input type="number" min="100" max="50000"
                                   id="amog_sitemap_per_file"
                                   name="<?php echo esc_attr( AMOG_Sitemap_Settings::OPTION ); ?>[per_file]"
                                   value="<?php echo esc_attr( $s['per_file'] ); ?>">
                            <p class="description">От 100 до 50 000. Google рекомендует ≤50 000. По умолчанию 1000.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="amog_sitemap_cache_ttl">Время жизни кэша (сек)</label></th>
                        <td>
                            <input type="number" min="60"
                                   id="amog_sitemap_cache_ttl"
                                   name="<?php echo esc_attr( AMOG_Sitemap_Settings::OPTION ); ?>[cache_ttl]"
                                   value="<?php echo esc_attr( $s['cache_ttl'] ); ?>">
                            <p class="description">По умолчанию 86400 (сутки).</p>
                        </td>
                    </tr>
                </table>

                <h2>Исключения из sitemap</h2>
                <p>Отметьте типы контента, которые <strong>не должны</strong> попадать в sitemap. Пер-объектный <code>_seo_robots</code> и каскадный <code>_seo_cascade_noindex</code> учитываются всегда.</p>

                <h3>Типы записей</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Не включать типы</th>
                        <td>
                            <?php
                            $all_types = get_post_types( [ 'public' => true ], 'objects' );
                            unset( $all_types['attachment'] );

                            $skip_types = (array) $s['skip_post_types'];

                            if ( empty( $all_types ) ) {
                                echo '<p><em>Нет публичных типов записей.</em></p>';
                            } else {
                                foreach ( $all_types as $type ) {
                                    $checked = in_array( $type->name, $skip_types, true );
                                    printf(
                                        '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="%1$s[skip_post_types][]" value="%2$s" %3$s> %4$s <code>%2$s</code></label>',
                                        esc_attr( AMOG_Sitemap_Settings::OPTION ),
                                        esc_attr( $type->name ),
                                        checked( $checked, true, false ),
                                        esc_html( $type->labels->name )
                                    );
                                }
                            }
                            ?>
                            <p class="description">
                                Посты выбранных типов не будут попадать в свои <code>sitemap-{type}.xml</code>,
                                их архивы — в <code>sitemap-cpt-archive.xml</code>.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Архивы CPT в urlset</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr( AMOG_Sitemap_Settings::OPTION ); ?>[skip_cpt_archive]"
                                       value="1" <?php checked( ! empty( $s['skip_cpt_archive'] ) ); ?>>
                                Не включать архивы CPT в <code>sitemap-cpt-archive.xml</code>
                            </label>
                        </td>
                    </tr>
                </table>

                <h3>Таксономии</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Не включать таксономии</th>
                        <td>
                            <?php
                            $all_taxes = get_taxonomies( [ 'public' => true ], 'objects' );
                            $skip_taxes = (array) $s['skip_taxonomies'];

                            if ( empty( $all_taxes ) ) {
                                echo '<p><em>Нет публичных таксономий.</em></p>';
                            } else {
                                foreach ( $all_taxes as $tax ) {
                                    $checked = in_array( $tax->name, $skip_taxes, true );
                                    printf(
                                        '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="%1$s[skip_taxonomies][]" value="%2$s" %3$s> %4$s <code>%2$s</code></label>',
                                        esc_attr( AMOG_Sitemap_Settings::OPTION ),
                                        esc_attr( $tax->name ),
                                        checked( $checked, true, false ),
                                        esc_html( $tax->labels->name )
                                    );
                                }
                            }
                            ?>
                            <p class="description">
                                Термины выбранных таксономий не будут попадать в <code>sitemap-tax-{taxonomy}.xml</code>,
                                а сама карта — в <code>sitemap_index.xml</code>.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2>Файлы sitemap</h2>
            <ul>
                <li><a href="<?php echo esc_url( home_url( '/sitemap_index.xml' ) ); ?>" target="_blank" rel="noopener">sitemap_index.xml</a></li>
                <?php if ( ! empty( $s['images_enabled'] ) ) : ?>
                    <li><a href="<?php echo esc_url( home_url( '/sitemap-images.xml' ) ); ?>" target="_blank" rel="noopener">sitemap-images.xml</a></li>
                <?php endif; ?>
                <?php if ( ! empty( $s['cpt_archives'] ) && empty( $s['skip_cpt_archive'] ) ) : ?>
                    <li><a href="<?php echo esc_url( home_url( '/sitemap-cpt-archive.xml' ) ); ?>" target="_blank" rel="noopener">sitemap-cpt-archive.xml</a></li>
                <?php endif; ?>
            </ul>

            <h2>Кэш</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="amog_sitemap_flush_cache">
                <?php wp_nonce_field( 'amog_sitemap_flush_cache' ); ?>
                <?php submit_button( 'Очистить кэш sitemap', 'secondary', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }
}