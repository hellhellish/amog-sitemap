<?php
/**
 * Site Health тест для AMOG Sitemap.
 *
 * Проверяет sitemap_index.xml:
 *  - генерируется (прямой вызов рендера, без HTTP)
 *  - содержит <sitemapindex>
 *  - содержит хотя бы одну <sitemap>
 *  - fallback: HTTP-запрос с sslverify=true, если прямой рендер не удался
 *
 * Важно: render_index() возвращает ТОЛЬКО тело <sitemapindex>...</sitemapindex>,
 * без XML-декларации. Декларацию печатает maybe_render() — владелец полного ответа.
 * Поэтому проверка на "<?xml" имеет смысл только в HTTP-режиме,
 * где тело — это полный ответ сайта. В прямом режиме её быть не должно.
 *
 * Регистрируется в стандартном экране «Здоровье сайта».
 */
class AMOG_Sitemap_Health {

    public function __construct() {
        add_filter( 'site_status_tests', [ $this, 'register_tests' ] );
    }

    public function register_tests( $tests ) {
        $tests['direct']['amog_sitemap_index'] = [
            'label' => 'AMOG Sitemap',
            'test'  => [ $this, 'test_index' ],
        ];
        return $tests;
    }

    public function test_index() {
        $result = [
            'label'       => 'XML sitemap доступен и отдаёт корректный XML',
            'status'      => 'good',
            'badge'       => [
                'label' => 'AMOG Sitemap',
                'color' => 'blue',
            ],
            'description' => '<p>Основной файл sitemap генерируется корректно.</p>',
            'actions'     => $this->open_action(),
            'test'        => 'amog_sitemap_index',
        ];

        // Если sitemap отключён — не проверяем.
        if ( ! AMOG_Sitemap_Settings::is_enabled() ) {
            $result['status']      = 'recommended';
            $result['label']       = 'XML sitemap отключён';
            $result['description'] = '<p>В настройках AMOG Sitemap выключен вывод sitemap.</p>';
            return $result;
        }

        // 1. Прямой рендер (без сети, без HTTP). Тело без XML-декларации.
        $direct_body = $this->render_index_direct();

        if ( $direct_body !== null ) {
            // Прямой рендер удался — проверяем тело как есть, без "<?xml".
            return $this->validate_body( $direct_body, false, $result );
        }

        // 2. Fallback: HTTP-запрос. Здесь тело — полный ответ с XML-декларацией.
        $http = $this->fetch_index_http();

        if ( is_wp_error( $http ) ) {
            return $this->fail(
                'Sitemap недоступен',
                '<p>Не удалось получить <code>sitemap_index.xml</code>: '
                    . esc_html( $http->get_error_message() ) . '</p>'
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $http );
        if ( $code !== 200 ) {
            return $this->fail(
                'Sitemap отдаёт ' . $code,
                '<p><code>sitemap_index.xml</code> вернул HTTP ' . $code . '.</p>'
            );
        }

        $content_type = (string) wp_remote_retrieve_header( $http, 'content-type' );
        if ( stripos( $content_type, 'xml' ) === false ) {
            $result['status']      = 'recommended';
            $result['label']       = 'Sitemap отдаёт неверный Content-Type';
            $result['description'] = '<p>Content-Type: <code>' . esc_html( $content_type ) . '</code>. '
                . 'Ожидается <code>application/xml</code>.</p>';
            return $result;
        }

        $body = (string) wp_remote_retrieve_body( $http );

        // В HTTP-режиме XML-декларация обязательна.
        return $this->validate_body( $body, true, $result );
    }

    /**
     * Общие проверки тела.
     *
     * @param string $body           Тело XML.
     * @param bool   $require_xml    Требовать ли "<?xml" в начале (только для HTTP).
     * @param array  $result         Заготовка результата.
     * @return array
     */
    private function validate_body( $body, $require_xml, $result ) {
        if ( $body === '' ) {
            return $this->fail(
                'Sitemap пустой',
                '<p><code>sitemap_index.xml</code> вернул пустое тело.</p>'
            );
        }

        if ( $require_xml && strpos( ltrim( $body ), '<?xml' ) !== 0 ) {
            return $this->fail(
                'Sitemap не начинается с XML-декларации',
                '<p>Ответ не похож на XML. Проверьте, не перехватывает ли запрос '
                    . 'другой плагин или тема.</p>'
            );
        }

        if ( strpos( $body, '<sitemapindex' ) === false ) {
            return $this->fail(
                'Sitemap не содержит <sitemapindex>',
                '<p>Ответ не похож на sitemap index. Проверьте, не перехватывает ли '
                    . 'запрос другой плагин или тема.</p>'
            );
        }

        if ( strpos( $body, '<sitemap>' ) === false ) {
            $result['status']      = 'recommended';
            $result['label']       = 'Sitemap index пуст';
            $result['description'] = '<p><code>sitemap_index.xml</code> не содержит ни одной '
                . 'дочерней карты. Проверьте, есть ли опубликованный контент и включены ли '
                . 'соответствующие карты в настройках.</p>';
            return $result;
        }

        return $result;
    }

    /**
     * Прямой рендер index в буфер. Без сети, без HTTP, без sslverify.
     *
     * @return string|null Тело XML (без декларации) или null при ошибке.
     */
    private function render_index_direct() {
        if ( ! class_exists( 'AMOG_Sitemap_Index' ) ) {
            return null;
        }

        $index = new AMOG_Sitemap_Index();

        if ( ! method_exists( $index, 'render_index' ) ) {
            return null;
        }

        ob_start();
        try {
            $index->render_index();
        } catch ( \Throwable $e ) {
            ob_end_clean();
            return null;
        }
        $body = (string) ob_get_clean();

        // Пустое тело — тоже считаем «прямой рендер не удался»,
        // чтобы уйти в HTTP-fallback и получить более точную ошибку.
        if ( $body === '' ) {
            return null;
        }

        return $body;
    }

    /**
     * HTTP-fallback. sslverify=true — на боевом это правильное поведение.
     */
    private function fetch_index_http() {
        return wp_remote_get(
            home_url( '/sitemap_index.xml' ),
            [
                'timeout'     => 10,
                'redirection' => 3,
                'sslverify'   => true,
            ]
        );
    }

    /**
     * Хелпер для critical-результата.
     */
    private function fail( $label, $description ) {
        return [
            'label'       => $label,
            'status'      => 'critical',
            'badge'       => [
                'label' => 'AMOG Sitemap',
                'color' => 'blue',
            ],
            'description' => $description,
            'actions'     => $this->open_action(),
            'test'        => 'amog_sitemap_index',
        ];
    }

    /**
     * Ссылка «Открыть sitemap_index.xml».
     */
    private function open_action() {
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener">Открыть sitemap_index.xml</a>',
            esc_url( home_url( '/sitemap_index.xml' ) )
        );
    }
}