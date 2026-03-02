<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class National_Grid_Frontend {
    /** Shortcode tag used to render the frontend widget. */
    private const SHORTCODE = 'show-national-grid';
    /** AJAX action name for frontend data refresh requests. */
    private const AJAX_ACTION = 'national_grid_frontend_data';
    /** Nonce action name used to validate frontend AJAX requests. */
    private const AJAX_NONCE_ACTION = 'national_grid_frontend_nonce';
    /** Fallback widget title when no custom value is configured. */
    private const DEFAULT_TITLE = 'National Grid - Live';
    /** Fallback widget description when no custom value is configured. */
    private const DEFAULT_DESCRIPTION = '';

    private static $instance_counter = 0;
    private static $assets_required = false;

    /**
     * Registers frontend shortcode, assets and AJAX handlers.
     *
     * @return void
     */
    public static function init() {
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'handle_frontend_data_ajax' ] );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ __CLASS__, 'handle_frontend_data_ajax' ] );
    }

    /**
     * Renders shortcode output with initial chart payload.
     *
     * @param array<string, mixed> $atts Shortcode attributes.
     * @return string
     */
    public static function render_shortcode( $atts = [] ) {
        self::mark_assets_required();
        // Fallback for template-level do_shortcode() calls after wp_enqueue_scripts.
        self::enqueue_assets();
        self::$instance_counter++;

        $atts = shortcode_atts(
            [
                'title' => '',
                'description' => '',
                'additional_class' => '',
            ],
            $atts,
            self::SHORTCODE
        );

        $title = self::resolve_title( $atts['title'] );
        $description = self::resolve_description( $atts['description'] );
        $additional_class = trim( (string) $atts['additional_class'] );
        $additional_class = '' !== $additional_class
            ? implode(
                ' ',
                array_filter(
                    array_map(
                        'sanitize_html_class',
                        preg_split( '/\s+/', $additional_class )
                    )
                )
            )
            : '';
        $chart_data = DatabaseStorage::getFrontendChartData();
        $instance_id = 'national-grid-frontend-' . self::$instance_counter;

        $payload = [
            'title' => $title,
            'description' => $description,
            'chartData' => $chart_data,
        ];

        return self::render_template(
            'shortcode-national-grid.php',
            [
                'instance_id' => $instance_id,
                'title' => $title,
                'description' => $description,
                'additional_class' => $additional_class,
                'payload_json' => wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ),
                'live_heading' => self::build_live_heading( $chart_data ),
            ]
        );
    }

    /**
     * Marks that frontend assets are required for the current request.
     *
     * @return void
     */
    public static function mark_assets_required() {
        self::$assets_required = true;
    }

    /**
     * Enqueues frontend CSS/JS and localized runtime config.
     *
     * @return void
     */
    public static function enqueue_assets() {
        if ( ! self::should_enqueue_assets() ) {
            return;
        }

        wp_enqueue_style(
            'national-grid-frontend',
            NATIONAL_GRID_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            NATIONAL_GRID_VERSION
        );

        wp_enqueue_script(
            'national-grid-chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        wp_enqueue_script(
            'national-grid-frontend',
            NATIONAL_GRID_PLUGIN_URL . 'assets/js/frontend.js',
            [ 'national-grid-chart-js' ],
            NATIONAL_GRID_VERSION,
            true
        );

        wp_localize_script(
            'national-grid-frontend',
            'nationalGridFrontend',
            [
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
            ]
        );
    }

    /**
     * Handles frontend AJAX data refresh requests.
     *
     * @return void
     */
    public static function handle_frontend_data_ajax() {
        if ( ! check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'Invalid request token.', 'national-grid' ),
                ],
                403
            );
        }

        $chart_data = DatabaseStorage::getFrontendChartData();

        wp_send_json_success(
            [
                'data' => $chart_data,
                'updatedAt' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
    }

    /**
     * Resolves module title from shortcode attr, option or fallback.
     *
     * @param mixed $shortcode_title Title from shortcode attributes.
     * @return string
     */
    private static function resolve_title( $shortcode_title ) {
        $shortcode_title = is_string( $shortcode_title ) ? trim( $shortcode_title ) : '';
        if ( '' !== $shortcode_title ) {
            return $shortcode_title;
        }

        $option_title = get_option( NATIONAL_GRID_OPTION_MODULE_TITLE, false );
        if ( false === $option_title ) {
            return self::DEFAULT_TITLE;
        }

        return trim( (string) $option_title );
    }

    /**
     * Resolves module description from shortcode attr, option or fallback.
     *
     * @param mixed $shortcode_description Description from shortcode attributes.
     * @return string
     */
    private static function resolve_description( $shortcode_description ) {
        $shortcode_description = is_string( $shortcode_description ) ? trim( $shortcode_description ) : '';
        if ( '' !== $shortcode_description ) {
            return $shortcode_description;
        }

        $option_description = get_option( NATIONAL_GRID_OPTION_MODULE_DESCRIPTION, false );
        if ( false === $option_description ) {
            return self::DEFAULT_DESCRIPTION;
        }

        return trim( (string) $option_description );
    }

    /**
     * Renders a template and returns its buffered HTML output.
     *
     * @param string $template_name Template file name.
     * @param array<string, mixed> $data Template variables.
     * @return string
     */
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

    /**
     * Builds the live heading string with site timezone formatting.
     *
     * @param array<string, mixed> $chart_data Frontend chart payload.
     * @return string
     */
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

    /**
     * Returns site timezone label for UI display.
     *
     * @return string
     */
    private static function get_timezone_label() {
        $label = wp_timezone_string();
        if ( '' !== $label ) {
            return $label;
        }

        $site_tz = wp_timezone();
        return $site_tz->getName();
    }

    /**
     * Returns site timezone identifier for JavaScript date formatting.
     *
     * @return string
     */
    private static function get_timezone_for_js() {
        $tz = wp_timezone_string();
        if ( '' !== $tz ) {
            return $tz;
        }

        return 'UTC';
    }

    /**
     * Determines whether frontend assets should be enqueued.
     *
     * @return bool
     */
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
