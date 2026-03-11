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
    private static $chart_data_request_cache = null;

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
     * Renders shortcode output shell for AJAX-driven chart loading.
     *
     * @param array<string, mixed> $atts Shortcode attributes.
     * @return string
     */
    public static function render_shortcode( $atts = [] ) {
        self::mark_assets_required();
        // Fallback for template-level do_shortcode() calls after wp_enqueue_scripts.
        self::enqueue_assets();
        self::$instance_counter++;

        $raw_atts = is_array( $atts ) ? $atts : [];
        $has_explicit_title = array_key_exists( 'title', $raw_atts );
        $has_explicit_description = array_key_exists( 'description', $raw_atts );

        $atts = shortcode_atts(
            [
                'title' => '',
                'description' => '',
                'additional_class' => '',
                'hide_title' => '0',
                'hide_timezone' => '1',
                'section_width' => 'full',
                'section_paddings' => 'normal',
            ],
            $atts,
            self::SHORTCODE
        );

        $shortcode_description = html_entity_decode( (string) $atts['description'], ENT_QUOTES, 'UTF-8' );
        $title = ( $has_explicit_title && '' === trim( (string) $atts['title'] ) )
            ? ''
            : self::resolve_title( $atts['title'] );
        $description = ( $has_explicit_description && '' === trim( $shortcode_description ) )
            ? ''
            : self::resolve_description( $shortcode_description );
        $hide_title = self::is_truthy_shortcode_flag( $atts['hide_title'] );
        $hide_timezone = self::is_truthy_shortcode_flag( $atts['hide_timezone'] );
        $section_width = strtolower( trim( (string) $atts['section_width'] ) );
        if ( ! in_array( $section_width, [ 'container', 'full' ], true ) ) {
            $section_width = 'full';
        }
        $section_paddings = strtolower( trim( (string) $atts['section_paddings'] ) );
        if ( ! in_array( $section_paddings, [ 'big', 'normal', 'small' ], true ) ) {
            $section_paddings = 'normal';
        }
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
        $instance_id = 'national-grid-frontend-' . self::$instance_counter;

        return self::render_template(
            'shortcode-national-grid.php',
            [
                'instance_id' => $instance_id,
                'title' => $title,
                'description' => $description,
                'additional_class' => $additional_class,
                'hide_title' => $hide_title,
                'hide_timezone' => $hide_timezone,
                'section_width' => $section_width,
                'section_paddings' => $section_paddings,
                'live_heading' => self::build_live_heading( self::get_chart_data(), $hide_timezone ),
            ]
        );
    }

    /**
     * Parses shortcode boolean-like attribute values.
     *
     * @param mixed $value Raw shortcode attribute value.
     * @return bool
     */
    private static function is_truthy_shortcode_flag( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (int) $value === 1;
        }

        $normalized = strtolower( trim( (string) $value ) );
        return in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true );
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
        $asset_suffix = self::get_asset_suffix();
        $style_relative_path = 'assets/css/frontend' . $asset_suffix . '.css';
        $script_relative_path = 'assets/js/frontend' . $asset_suffix . '.js';

        wp_enqueue_style(
            'national-grid-frontend',
            NATIONAL_GRID_PLUGIN_URL . $style_relative_path,
            [],
            self::get_asset_version( $style_relative_path )
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
            NATIONAL_GRID_PLUGIN_URL . $script_relative_path,
            [ 'national-grid-chart-js' ],
            self::get_asset_version( $script_relative_path ),
            true
        );

        wp_localize_script(
            'national-grid-frontend',
            'nationalGridFrontend',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'action' => self::AJAX_ACTION,
                'nonce' => wp_create_nonce( self::AJAX_NONCE_ACTION ),
                'timeoutMinutes' => max( 5, (int) get_option( NATIONAL_GRID_OPTION_TIMEOUT, 5 ) ),
                'errorMessage' => __( 'Unable to refresh chart data.', 'national-grid' ),
                'loadingMessage' => __( 'Loading latest data...', 'national-grid' ),
                'updatedAtLabel' => __( 'Updated (UTC): ', 'national-grid' ),
                'noDataMessage' => __( 'No data available yet.', 'national-grid' ),
                'todayLabel' => __( 'Today', 'national-grid' ),
                'liveHeadingPrefix' => __( 'National Grid:', 'national-grid' ),
                'liveHeadingSuffix' => __( ' - Generation Mix and Type', 'national-grid' ),
                'timezoneLabel' => self::get_timezone_label(),
                'timezone' => self::get_timezone_for_js(),
                'chartAnimation' => 1 === (int) get_option( NATIONAL_GRID_OPTION_CHART_ANIMATION, 1 ) ? 1 : 0,
                'chartTextColor' => '#5c5c5c',
                'tooltipTextColor' => '#ffffff',
            ]
        );
    }

    /**
     * Handles frontend AJAX data refresh requests.
     *
     * @return void
     */
    public static function handle_frontend_data_ajax() {
        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );

        if ( is_user_logged_in() && ! check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'Invalid request token.', 'national-grid' ),
                ],
                403
            );
        }

        $chart_data = self::get_chart_data();

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
     * Returns chart data cached within current request lifecycle.
     *
     * @return array<string, mixed>
     */
    private static function get_chart_data() {
        if ( is_array( self::$chart_data_request_cache ) ) {
            return self::$chart_data_request_cache;
        }

        self::$chart_data_request_cache = DatabaseStorage::getFrontendChartData();

        return self::$chart_data_request_cache;
    }

    /**
     * Returns frontend asset suffix based on debug flags.
     *
     * @return string
     */
    private static function get_asset_suffix() {
        $is_debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG )
            || ( defined( 'WP_DEBUG' ) && WP_DEBUG );

        return $is_debug ? '' : '.min';
    }

    /**
     * Returns asset version derived from local file mtime when available.
     *
     * @param string $relative_path Plugin-relative asset path.
     * @return string
     */
    private static function get_asset_version( string $relative_path ): string {
        $full_path = NATIONAL_GRID_PLUGIN_DIR . ltrim( $relative_path, '/\\' );
        if ( is_string( $full_path ) && file_exists( $full_path ) ) {
            $mtime = filemtime( $full_path );
            if ( false !== $mtime ) {
                return (string) $mtime;
            }
        }

        return NATIONAL_GRID_VERSION;
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
     * @param bool $hide_timezone Whether to hide timezone label in heading.
     * @return string
     */
    private static function build_live_heading( array $chart_data, bool $hide_timezone = false ) {
        $timezone_segment = $hide_timezone ? '' : ' (' . self::get_timezone_label() . ')';
        $time = '';
        if (
            isset( $chart_data['latest_five_minutes'] )
            && is_array( $chart_data['latest_five_minutes'] )
            && isset( $chart_data['latest_five_minutes']['time'] )
        ) {
            $time = (string) $chart_data['latest_five_minutes']['time'];
        }
        if ( '' === $time && isset( $chart_data['update_started_at_utc'] ) ) {
            $time = (string) $chart_data['update_started_at_utc'];
        }

        if ( '' === $time ) {
            return sprintf(
                'National Grid: --:-- %s%s - Generation Mix and Type.',
                __( 'Today', 'national-grid' ),
                $timezone_segment
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
                'National Grid: %s %s%s - Generation Mix and Type.',
                $local->format( 'H:i' ),
                $day_label,
                $timezone_segment
            );
        } catch ( Exception $e ) {
            return sprintf(
                'National Grid: --:-- %s%s - Generation Mix and Type.',
                __( 'Today', 'national-grid' ),
                $timezone_segment
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
