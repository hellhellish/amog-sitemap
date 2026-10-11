<?php
/**
 * Site Health тест для AMOG Sitemap.
 *
 * Проверяет: sitemap_index.xml доступен, отдаёт XML, содержит <sitemapindex>.
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
            'description' => '<p>Основной файл sitemap отвечает корректно.</p>',
            'actions'     => sprintf(
                '<a href="%s" target="_blank" rel="noopener">Открыть sitemap_index.xml</a>',
                esc_url( home_url( '/sitemap_index.xml' ) )
            ),
            'test'        => 'amog_sitemap_index',
        ];

        // Если sitemap отключён — не проверяем.
        if ( ! AMOG_Sitemap_Settings::is_enabled() ) {
            $result['status']      = 'recommended';
            $result['label']       = 'XML sitemap отключён';
            $result['description'] = '<p>В настройках AMOG Sitemap выключен вывод sitemap.</p>';
            return $result;
        }

        $response = wp_remote_get(
            home_url( '/sitemap_index.xml' ),
            [
                'timeout'     => 10,
                'redirection' => 3,
                'sslverify'   => false,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $result['status']      = 'critical';
            $result['label']       = 'Sitemap недоступен';
            $result['description'] = '<p>Не удалось получить <code>sitemap_index.xml</code>: '
                . esc_html( $response->get_error_message() ) . '</p>';
            return $result;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $result['status']      = 'critical';
            $result['label']       = 'Sitemap отдаёт ' . $code;
            $result['description'] = '<p><code>sitemap_index.xml</code> вернул HTTP ' . $code . '.</p>';
            return $result;
        }

        $content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
        if ( stripos( $content_type, 'xml' ) === false ) {
            $result['status']      = 'recommended';
            $result['label']       = 'Sitemap отдаёт неверный Content-Type';
            $result['description'] = '<p>Content-Type: <code>' . esc_html( $content_type ) . '</code>. '
                . 'Ожидается <code>application/xml</code>.</p>';
            return $result;
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( strpos( $body, '<sitemapindex' ) === false ) {
            $result['status']      = 'critical';
            $result['label']       = 'Sitemap не содержит <sitemapindex>';
            $result['description'] = '<p>Ответ не похож на sitemap index. Проверьте, не перехватывает ли '
                . 'запрос другой плагин или тема.</p>';
            return $result;
        }

        return $result;
    }
}