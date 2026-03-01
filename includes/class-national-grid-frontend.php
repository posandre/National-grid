<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class National_Grid_Frontend {
    private const SHORTCODE = 'show-national-grid';
    private const AJAX_ACTION = 'national_grid_frontend_data';
    private const AJAX_NONCE_ACTION = 'national_grid_frontend_nonce';
    private const DEFAULT_TITLE = 'National Grid - Live';
    private const DEFAULT_DESCRIPTION = 'National grid: Today-Generation Mix and Type';

    private static $instance_counter = 0;
    private static $assets_required = false;

    public static function init() {
        add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_frontend_data_ajax' ) );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'handle_frontend_data_ajax' ) );
    }

    public static function render_shortcode( $atts = array() ) {
        self::mark_assets_required();
        self::$instance_counter++;

        $atts = shortcode_atts(
            array(
                'title' => '',
                'description' => '',
                'limit' => 1,
            ),
            $atts,
            self::SHORTCODE
        );

        $title = self::resolve_title( $atts['title'] );
        $description = self::resolve_description( $atts['description'] );
        $limit = max( 1, min( 1000, (int) $atts['limit'] ) );
        $chart_data = DatabaseStorage::getFrontendChartData( $limit );
        $instance_id = 'national-grid-frontend-' . self::$instance_counter;

        $payload = array(
            'title' => $title,
            'description' => $description,
            'chartData' => $chart_data,
        );

        return self::render_template(
            'shortcode-national-grid.php',
            array(
                'instance_id' => $instance_id,
                'title' => $title,
                'description' => $description,
                'limit' => $limit,
                'payload_json' => wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ),
                'live_heading' => self::build_live_heading( $chart_data ),
            )
        );
    }

    public static function mark_assets_required() {
        self::$assets_required = true;
    }

    public static function enqueue_assets() {
        if ( ! self::should_enqueue_assets() ) {
            return;
        }

        wp_enqueue_style(
            'national-grid-frontend',
            NATIONAL_GRID_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            NATIONAL_GRID_VERSION
        );

        wp_enqueue_script(
            'national-grid-chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            array(),
            '4.4.1',
            true
        );

        wp_enqueue_script(
            'national-grid-frontend',
            NATIONAL_GRID_PLUGIN_URL . 'assets/js/frontend.js',
            array( 'national-grid-chart-js' ),
            NATIONAL_GRID_VERSION,
            true
        );

        wp_localize_script(
            'national-grid-frontend',
            'nationalGridFrontend',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'action' => self::AJAX_ACTION,
                'nonce' => wp_create_nonce( self::AJAX_NONCE_ACTION ),
                'timeoutMinutes' => max( 1, (int) get_option( NATIONAL_GRID_OPTION_TIMEOUT, 5 ) ),
                'errorMessage' => __( 'Unable to refresh chart data.', 'national-grid' ),
                'updatedAtLabel' => __( 'Updated (UTC): ', 'national-grid' ),
                'noDataMessage' => __( 'No data available yet.', 'national-grid' ),
                'todayLabel' => __( 'Today', 'national-grid' ),
                'liveHeadingPrefix' => __( 'National Grid:', 'national-grid' ),
                'liveHeadingSuffix' => __( ' - Generation Mix and Type.', 'national-grid' ),
                'timezoneLabel' => self::get_timezone_label(),
                'timezone' => self::get_timezone_for_js(),
            )
        );
    }

    public static function handle_frontend_data_ajax() {
        if ( ! check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid request token.', 'national-grid' ),
                ),
                403
            );
        }

        $limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $chart_data = DatabaseStorage::getFrontendChartData( $limit );

        wp_send_json_success(
            array(
                'data' => $chart_data,
                'updatedAt' => gmdate( 'Y-m-d H:i:s' ),
            )
        );
    }

    private static function resolve_title( $shortcode_title ) {
        $shortcode_title = is_string( $shortcode_title ) ? trim( $shortcode_title ) : '';
        if ( '' !== $shortcode_title ) {
            return $shortcode_title;
        }

        $option_title = trim( (string) get_option( NATIONAL_GRID_OPTION_MODULE_TITLE, '' ) );
        if ( '' !== $option_title ) {
            return $option_title;
        }

        return self::DEFAULT_TITLE;
    }

    private static function resolve_description( $shortcode_description ) {
        $shortcode_description = is_string( $shortcode_description ) ? trim( $shortcode_description ) : '';
        if ( '' !== $shortcode_description ) {
            return $shortcode_description;
        }

        $option_description = trim( (string) get_option( NATIONAL_GRID_OPTION_MODULE_DESCRIPTION, '' ) );
        if ( '' !== $option_description ) {
            return $option_description;
        }

        return self::DEFAULT_DESCRIPTION;
    }

    private static function render_template( $template_name, array $data ) {
        $template_path = NATIONAL_GRID_PLUGIN_DIR . 'templates/' . ltrim( (string) $template_name, '/\\' );
        if ( ! is_readable( $template_path ) ) {
            return '';
        }

        ob_start();
        extract( $data, EXTR_SKIP );
        include $template_path;
        return ob_get_clean();
    }

    private static function build_live_heading( array $chart_data ) {
        $time = '';
        if ( isset( $chart_data['latest_five_minutes']['time'] ) ) {
            $time = (string) $chart_data['latest_five_minutes']['time'];
        }

        if ( '' === $time ) {
            return sprintf(
                'National Grid: --:-- %s (%s) - Generation Mix and Type.',
                __( 'Today', 'national-grid' ),
                self::get_timezone_label()
            );
        }

        try {
            $utc = new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
            $site_tz = wp_timezone();
            $local = $utc->setTimezone( $site_tz );
            $today = new DateTimeImmutable( 'now', $site_tz );
            $day_label = $local->format( 'Y-m-d' ) === $today->format( 'Y-m-d' )
                ? __( 'Today', 'national-grid' )
                : $local->format( 'Y-m-d' );

            return sprintf(
                'National Grid: %s %s (%s) - Generation Mix and Type.',
                $local->format( 'H:i' ),
                $day_label,
                self::get_timezone_label()
            );
        } catch ( Exception $e ) {
            return sprintf(
                'National Grid: --:-- %s (%s) - Generation Mix and Type.',
                __( 'Today', 'national-grid' ),
                self::get_timezone_label()
            );
        }
    }

    private static function get_timezone_label() {
        $label = wp_timezone_string();
        if ( '' !== $label ) {
            return $label;
        }

        $site_tz = wp_timezone();
        return $site_tz->getName();
    }

    private static function get_timezone_for_js() {
        $tz = wp_timezone_string();
        if ( '' !== $tz ) {
            return $tz;
        }

        return 'UTC';
    }

    private static function should_enqueue_assets() {
        if ( is_admin() ) {
            return false;
        }

        if ( self::$assets_required ) {
            return true;
        }

        global $wp_query;
        if ( ! isset( $wp_query->posts ) || ! is_array( $wp_query->posts ) ) {
            return false;
        }

        foreach ( $wp_query->posts as $post ) {
            if ( ! isset( $post->post_content ) || ! is_string( $post->post_content ) ) {
                continue;
            }

            if ( has_shortcode( $post->post_content, self::SHORTCODE ) ) {
                return true;
            }
        }

        return false;
    }
}
