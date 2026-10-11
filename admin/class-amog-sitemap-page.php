<?php
/**
 * Верхнеуровневое меню "AMOG Sitemap" с табами.
 *
 * Архитектура:
 *  - одна опция-массив (AMOG_Sitemap_Settings::OPTION)
 *  - один register_setting + один sanitize_callback
 *  - одна форма на всю страницу, таб-маркер через скрытое поле _tab
 *  - sanitize() обрабатывает только поля текущего таба, остальные не трогает
 */
class AMOG_Sitemap_Page {

    const SLUG  = 'amog-sitemap';
    const GROUP = 'amog_sitemap_group';

    /**
     * Карта табов: slug => Название.
     *
     * @return array<string,string>
     */
    public function tabs() {
        return [
            'general'    => 'Основное',
            'post_types' => 'Типы записей',
            'taxonomies' => 'Таксономии',
            'cache'      => 'Кэш и файлы',
        ];
    }

    /**
     * Текущий таб из URL, с валидацией.
     */
    public function current_tab() {
        $tabs = $this->tabs();
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

        if ( $tab && array_key_exists( $tab, $tabs ) ) {
            return $tab;
        }

        // Дефолт — первый таб.
        return array_key_first( $tabs );
    }

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
            self::GROUP,
            AMOG_Sitemap_Settings::OPTION,
            [ 'sanitize_callback' => [ $this, 'sanitize' ] ]
        );
    }

    /**
     * Санитайз: обрабатываем только поля текущего таба.
     * Поля остальных табов берём из текущих опций — не затираем.
     */
    public function sanitize( $input ) {
        $out = AMOG_Sitemap_Settings::get();

        $tab = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';

        switch ( $tab ) {
            case 'general':
                $out = $this->sanitize_general( $out, $input );
                break;

            case 'post_types':
                $out = $this->sanitize_post_types( $out, $input );
                break;

            case 'taxonomies':
                $out = $this->sanitize_taxonomies( $out, $input );
                break;

            case 'cache':
                // На этом табе нет сохраняемых полей — только кнопка очистки кэша.
                // Ничего не меняем.
                break;

            default:
                // Неизвестный таб — ничего не трогаем.
                break;
        }

        return $out;
    }

    /**
     * Таб "Основное".
     */
    private function sanitize_general( $out, $input ) {
        $out['enabled']        = ! empty( $input['enabled'] ) ? 1 : 0;
        $out['images_enabled'] = ! empty( $input['images_enabled'] ) ? 1 : 0;
        $out['cpt_archives']   = ! empty( $input['cpt_archives'] ) ? 1 : 0;

        $per_file = isset( $input['per_file'] ) ? absint( $input['per_file'] ) : 1000;
        if ( $per_file < 100 )   $per_file = 100;
        if ( $per_file > 50000 ) $per_file = 50000;
        $out['per_file'] = $per_file;

        $ttl = isset( $input['cache_ttl'] ) ? absint( $input['cache_ttl'] ) : DAY_IN_SECONDS;
        if ( $ttl < 60 ) $ttl = 60;
        $out['cache_ttl'] = $ttl;

        return $out;
    }

    /**
     * Таб "Типы записей".
     */
    private function sanitize_post_types( $out, $input ) {
        $out['skip_cpt_archive'] = ! empty( $input['skip_cpt_archive'] ) ? 1 : 0;

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

        return $out;
    }

    /**
     * Таб "Таксономии".
     */
    private function sanitize_taxonomies( $out, $input ) {
        $valid_taxes = array_keys( get_taxonomies( [ 'public' => true ], 'names' ) );
        $skip_taxes  = [];

        // Обратная совместимость: старые флаги переносим в массив.
        if ( ! empty( $out['skip_category'] ) && in_array( 'category', $valid_taxes, true ) ) {
            $skip_taxes[] = 'category';
        }
        if ( ! empty( $out['skip_tag'] ) && in_array( 'post_tag', $valid_taxes, true ) ) {
            $skip_taxes[] = 'post_tag';
        }

        if ( ! empty( $input['skip_taxonomies'] ) && is_array( $input['skip_taxonomies'] ) ) {
            foreach ( $input['skip_taxonomies'] as $slug ) {
                $slug = sanitize_key( $slug );
                if ( $slug && in_array( $slug, $valid_taxes, true ) && ! in_array( $slug, $skip_taxes, true ) ) {
                    $skip_taxes[] = $slug;
                }
            }
        }

        $out['skip_taxonomies'] = $skip_taxes;

        // Сбрасываем старые флаги — они больше не UI.
        $out['skip_category'] = 0;
        $out['skip_tag']      = 0;

        return $out;
    }

    public function handle_flush() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Недостаточно прав.' );
        }
        check_admin_referer( 'amog_sitemap_flush_cache' );

        AMOG_Sitemap_Cache::flush_all();

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::SLUG, 'tab' => 'cache', 'flushed' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * Рендер страницы: шапка + табы + одна форма.
     */
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $tabs        = $this->tabs();
        $current     = $this->current_tab();
        $s           = AMOG_Sitemap_Settings::get();
        $option_name = AMOG_Sitemap_Settings::OPTION;
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

            <h2 class="nav-tab-wrapper">
                <?php foreach ( $tabs as $slug => $label ) :
                    $url = add_query_arg(
                        [ 'page' => self::SLUG, 'tab' => $slug ],
                        admin_url( 'admin.php' )
                    );
                    $class = 'nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields( self::GROUP ); ?>

                <input type="hidden"
                       name="<?php echo esc_attr( $option_name ); ?>[_tab]"
                       value="<?php echo esc_attr( $current ); ?>">

                <?php
                switch ( $current ) {
                    case 'general':
                        $this->tab_general( $s );
                        break;
                    case 'post_types':
                        $this->tab_post_types( $s );
                        break;
                    case 'taxonomies':
                        $this->tab_taxonomies( $s );
                        break;
                    case 'cache':
                        $this->tab_cache( $s );
                        break;
                }
                ?>

                <?php if ( $current !== 'cache' ) : ?>
                    <?php submit_button(); ?>
                <?php endif; ?>
            </form>

            <?php if ( $current === 'cache' ) : ?>
                <?php $this->render_flush_form(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Таб "Основное".
     */
    private function tab_general( $s ) {
        $name = AMOG_Sitemap_Settings::OPTION;
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Sitemap</th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr( $name ); ?>[enabled]"
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
                               name="<?php echo esc_attr( $name ); ?>[images_enabled]"
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
                               name="<?php echo esc_attr( $name ); ?>[cpt_archives]"
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
                           name="<?php echo esc_attr( $name ); ?>[per_file]"
                           value="<?php echo esc_attr( $s['per_file'] ); ?>">
                    <p class="description">От 100 до 50 000. Google рекомендует ≤50 000. По умолчанию 1000.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="amog_sitemap_cache_ttl">Время жизни кэша (сек)</label></th>
                <td>
                    <input type="number" min="60"
                           id="amog_sitemap_cache_ttl"
                           name="<?php echo esc_attr( $name ); ?>[cache_ttl]"
                           value="<?php echo esc_attr( $s['cache_ttl'] ); ?>">
                    <p class="description">По умолчанию 86400 (сутки).</p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Таб "Типы записей".
     */
    private function tab_post_types( $s ) {
        $name = AMOG_Sitemap_Settings::OPTION;
        ?>
        <p>Отметьте типы контента, которые <strong>не должны</strong> попадать в sitemap. Пер-объектный <code>_seo_robots</code> учитывается всегда.</p>

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
                                esc_attr( $name ),
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
                               name="<?php echo esc_attr( $name ); ?>[skip_cpt_archive]"
                               value="1" <?php checked( ! empty( $s['skip_cpt_archive'] ) ); ?>>
                        Не включать архивы CPT в <code>sitemap-cpt-archive.xml</code>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Таб "Таксономии".
     */
    private function tab_taxonomies( $s ) {
        $name = AMOG_Sitemap_Settings::OPTION;
        ?>
        <p>Отметьте таксономии, термины которых <strong>не должны</strong> попадать в sitemap. Пер-объектный <code>_seo_robots</code> и каскадный <code>_seo_cascade_noindex</code> учитываются всегда.</p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Не включать таксономии</th>
                <td>
                    <?php
                    $all_taxes  = get_taxonomies( [ 'public' => true ], 'objects' );
                    $skip_taxes = (array) $s['skip_taxonomies'];

                    if ( empty( $all_taxes ) ) {
                        echo '<p><em>Нет публичных таксономий.</em></p>';
                    } else {
                        foreach ( $all_taxes as $tax ) {
                            $checked = in_array( $tax->name, $skip_taxes, true );
                            printf(
                                '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="%1$s[skip_taxonomies][]" value="%2$s" %3$s> %4$s <code>%2$s</code></label>',
                                esc_attr( $name ),
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
        <?php
    }

    /**
     * Таб "Кэш и файлы".
     */
    private function tab_cache( $s ) {
        ?>
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
        <p>Полная очистка кэша sitemap. Используйте, если данные в sitemap не обновляются после правок.</p>
        <?php
    }

    /**
     * Отдельная форма для кнопки "Очистить кэш" (не внутри options.php).
     */
    private function render_flush_form() {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="amog_sitemap_flush_cache">
            <?php wp_nonce_field( 'amog_sitemap_flush_cache' ); ?>
            <?php submit_button( 'Очистить кэш sitemap', 'secondary', 'submit', false ); ?>
        </form>
        <?php
    }
}