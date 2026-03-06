<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class National_Grid_Admin {
    /** Settings page slug used in admin URLs. */
    private const PAGE_SLUG = 'national-grid-settings';
    /** Action name for manual update requests. */
    private const UPDATE_ACTION = 'national_grid_update_data';
    /** Action name for fetching rendered log section HTML. */
    private const FETCH_LOG_ACTION = 'national_grid_fetch_log_section';
    /** Action name for clearing stored logs. */
    private const CLEAR_LOG_ACTION = 'national_grid_clear_log';
    /** Action name for clearing debug log file. */
    private const CLEAR_DEBUG_LOG_ACTION = 'national_grid_clear_debug_log';
    /** Cron hook name for scheduled data updates. */
    private const CRON_HOOK = 'national_grid_cron_update_data';
    /** Single cron hook name for initial data update after plugin activation. */
    private const INITIAL_CRON_HOOK = 'national_grid_cron_initial_update_data';
    /** Custom cron schedule key based on configured timeout. */
    private const CRON_SCHEDULE = 'national_grid_custom_interval';
    /** Cron hook name for scheduled plugin log cleanup. */
    private const LOG_CLEAR_CRON_HOOK = 'national_grid_cron_clear_log';
    /** Custom cron schedule key for plugin log cleanup. */
    private const LOG_CLEAR_CRON_SCHEDULE = 'national_grid_log_clear_interval';

    /**
     * Registers admin page, settings, actions and cron hooks.
     *
     * @return void
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        add_action( 'admin_post_' . self::UPDATE_ACTION, [ __CLASS__, 'handle_update_data' ] );
        add_action( 'wp_ajax_' . self::UPDATE_ACTION, [ __CLASS__, 'handle_update_data_ajax' ] );
        add_action( 'wp_ajax_' . self::FETCH_LOG_ACTION, [ __CLASS__, 'handle_fetch_log_section_ajax' ] );

        add_action( 'admin_post_' . self::CLEAR_LOG_ACTION, [ __CLASS__, 'handle_clear_log' ] );
        add_action( 'admin_post_' . self::CLEAR_DEBUG_LOG_ACTION, [ __CLASS__, 'handle_clear_debug_log' ] );

        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] );
        add_action( 'init', [ __CLASS__, 'maybe_sync_cron_event' ] );
        add_action( 'init', [ __CLASS__, 'maybe_sync_log_clear_cron_event' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'handle_cron_update' ] );
        add_action( self::INITIAL_CRON_HOOK, [ __CLASS__, 'handle_initial_cron_update' ] );
        add_action( self::LOG_CLEAR_CRON_HOOK, [ __CLASS__, 'handle_cron_clear_log' ] );
    }

    /**
     * Registers plugin settings page in WordPress admin.
     *
     * @return void
     */
    public static function register_menu() {
        add_options_page(
            __( 'National Grid', 'national-grid' ),
            __( 'National Grid', 'national-grid' ),
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * Registers plugin settings, sections and fields.
     *
     * @return void
     */
    public static function register_settings() {
        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_TIMEOUT,
            [
                'type' => 'integer',
                'sanitize_callback' => [ __CLASS__, 'sanitize_timeout' ],
                'default' => 5,
            ]
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_MODULE_TITLE,
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_MODULE_DESCRIPTION,
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default' => '',
            ]
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_CHART_ANIMATION,
            [
                'type' => 'boolean',
                'sanitize_callback' => [ __CLASS__, 'sanitize_chart_animation' ],
                'default' => 1,
            ]
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_AUTO_UPDATE,
            [
                'type' => 'boolean',
                'sanitize_callback' => [ __CLASS__, 'sanitize_auto_update' ],
                'default' => 0,
            ]
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_DEBUG_MODE,
            [
                'type' => 'boolean',
                'sanitize_callback' => [ __CLASS__, 'sanitize_debug_mode' ],
                'default' => 0,
            ]
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_ENABLE_LOG,
            [
                'type' => 'boolean',
                'sanitize_callback' => [ __CLASS__, 'sanitize_enable_log' ],
                'default' => 1,
            ]
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_AUTO_CLEAR_LOG,
            [
                'type' => 'boolean',
                'sanitize_callback' => [ __CLASS__, 'sanitize_auto_clear_log' ],
                'default' => 0,
            ]
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_LOG_CLEAR_INTERVAL_HOURS,
            [
                'type' => 'integer',
                'sanitize_callback' => [ __CLASS__, 'sanitize_log_clear_interval_hours' ],
                'default' => 336,
            ]
        );

        add_settings_section(
            'national_grid_main',
            __( 'National Grid Settings', 'national-grid' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_TIMEOUT,
            __( 'National Grid Data Update Timed Out', 'national-grid' ),
            [ __CLASS__, 'render_timeout_field' ],
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_AUTO_UPDATE,
            __( 'Automatic cron update', 'national-grid' ),
            [ __CLASS__, 'render_auto_update_field' ],
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_DEBUG_MODE,
            __( 'Debug mode', 'national-grid' ),
            [ __CLASS__, 'render_debug_mode_field' ],
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_ENABLE_LOG,
            __( 'Update event log', 'national-grid' ),
            [ __CLASS__, 'render_enable_log_field' ],
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_AUTO_CLEAR_LOG,
            __( 'Automatic log cleanup', 'national-grid' ),
            [ __CLASS__, 'render_auto_clear_log_field' ],
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_LOG_CLEAR_INTERVAL_HOURS,
            __( 'Log cleanup interval', 'national-grid' ),
            [ __CLASS__, 'render_log_clear_interval_hours_field' ],
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_MODULE_TITLE,
            __( 'Module title', 'national-grid' ),
            [ __CLASS__, 'render_module_title_field' ],
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_MODULE_DESCRIPTION,
            __( 'Module description', 'national-grid' ),
            [ __CLASS__, 'render_module_description_field' ],
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_CHART_ANIMATION,
            __( 'Chart animation', 'national-grid' ),
            [ __CLASS__, 'render_chart_animation_field' ],
            self::PAGE_SLUG,
            'national_grid_main'
        );
    }

    /**
     * Sanitizes timeout value in minutes.
     *
     * @param mixed $value Raw timeout option value.
     * @return int
     */
    public static function sanitize_timeout( $value ) {
        $value = absint( $value );

        if ( $value < 5 ) {
            $value = 5;
        }

        return $value;
    }

    /**
     * Normalizes auto-update toggle to 1 or 0.
     *
     * @param mixed $value Raw auto-update option value.
     * @return int
     */
    public static function sanitize_auto_update( $value ) {
        return ! empty( $value ) ? 1 : 0;
    }

    /**
     * Normalizes debug mode toggle to 1 or 0.
     *
     * @param mixed $value Raw debug mode option value.
     * @return int
     */
    public static function sanitize_debug_mode( $value ) {
        return ! empty( $value ) ? 1 : 0;
    }

    /**
     * Normalizes update event logging toggle to 1 or 0.
     *
     * @param mixed $value Raw log toggle value.
     * @return int
     */
    public static function sanitize_enable_log( $value ) {
        return ! empty( $value ) ? 1 : 0;
    }

    /**
     * Normalizes chart animation toggle to 1 or 0.
     *
     * @param mixed $value Raw chart animation option value.
     * @return int
     */
    public static function sanitize_chart_animation( $value ) {
        return ! empty( $value ) ? 1 : 0;
    }

    /**
     * Normalizes automatic log cleanup toggle to 1 or 0.
     *
     * @param mixed $value Raw automatic log cleanup option value.
     * @return int
     */
    public static function sanitize_auto_clear_log( $value ) {
        return ! empty( $value ) ? 1 : 0;
    }

    /**
     * Sanitizes log cleanup interval in hours.
     *
     * @param mixed $value Raw interval value.
     * @return int
     */
    public static function sanitize_log_clear_interval_hours( $value ) {
        $value = absint( $value );

        if ( $value <= 0 ) {
            $value = 336;
        }

        return $value;
    }

    /**
     * Renders timeout input field.
     *
     * @return void
     */
    public static function render_timeout_field() {
        $value = max( 5, (int) get_option( NATIONAL_GRID_OPTION_TIMEOUT, 5 ) );
        printf(
            '<input type="number" name="%1$s" id="%1$s" value="%2$d" class="small-text" min="5" /> <span>%3$s</span>',
            esc_attr( NATIONAL_GRID_OPTION_TIMEOUT ),
            $value,
            esc_html__( 'minutes', 'national-grid' )
        );
    }

    /**
     * Renders automatic update checkbox field.
     *
     * @return void
     */
    public static function render_auto_update_field() {
        $value = (int) get_option( NATIONAL_GRID_OPTION_AUTO_UPDATE, 0 );
        printf(
            '<label><input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr( NATIONAL_GRID_OPTION_AUTO_UPDATE ),
            checked( 1, $value, false ),
            esc_html__( 'Enable automatic updates by cron', 'national-grid' )
        );
    }

    /**
     * Renders debug mode checkbox field.
     *
     * @return void
     */
    public static function render_debug_mode_field() {
        $value = (int) get_option( NATIONAL_GRID_OPTION_DEBUG_MODE, 0 );
        printf(
            '<label><input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr( NATIONAL_GRID_OPTION_DEBUG_MODE ),
            checked( 1, $value, false ),
            esc_html__( 'Enable debug logging to file for update calculations', 'national-grid' )
        );
    }

    /**
     * Renders update event logging checkbox field.
     *
     * @return void
     */
    public static function render_enable_log_field() {
        $value = (int) get_option( NATIONAL_GRID_OPTION_ENABLE_LOG, 1 );
        printf(
            '<label><input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr( NATIONAL_GRID_OPTION_ENABLE_LOG ),
            checked( 1, $value, false ),
            esc_html__( 'Store update success/error events in Log tab', 'national-grid' )
        );
    }

    /**
     * Renders automatic log cleanup checkbox field.
     *
     * @return void
     */
    public static function render_auto_clear_log_field() {
        $is_log_enabled = DatabaseStorage::isLogEnabled();
        $value = (int) get_option( NATIONAL_GRID_OPTION_AUTO_CLEAR_LOG, 0 );
        printf(
            '<label><input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr( NATIONAL_GRID_OPTION_AUTO_CLEAR_LOG ),
            checked( 1, $value, false ),
            esc_html__( 'Enable automatic plugin log cleanup by cron', 'national-grid' )
        );
        if ( ! $is_log_enabled ) {
            echo '<p class="description">' . esc_html__( 'Enable "Update event log" to use automatic log cleanup.', 'national-grid' ) . '</p>';
        }
    }

    /**
     * Renders log cleanup interval input field.
     *
     * @return void
     */
    public static function render_log_clear_interval_hours_field() {
        $is_log_enabled = DatabaseStorage::isLogEnabled();
        $value = (int) get_option( NATIONAL_GRID_OPTION_LOG_CLEAR_INTERVAL_HOURS, 336 );
        if ( $value <= 0 ) {
            $value = 336;
        }
        printf(
            '<input type="number" name="%1$s" id="%1$s" value="%2$d" class="small-text" min="1" /> <span>%3$s</span>',
            esc_attr( NATIONAL_GRID_OPTION_LOG_CLEAR_INTERVAL_HOURS ),
            $value,
            esc_html__( 'hours', 'national-grid' )
        );
        if ( ! $is_log_enabled ) {
            echo '<p class="description">' . esc_html__( 'Enable "Update event log" to use automatic log cleanup.', 'national-grid' ) . '</p>';
        }
    }

    /**
     * Renders module title input field.
     *
     * @return void
     */
    public static function render_module_title_field() {
        $value = (string) get_option( NATIONAL_GRID_OPTION_MODULE_TITLE, '' );
        printf(
            '<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" />',
            esc_attr( NATIONAL_GRID_OPTION_MODULE_TITLE ),
            esc_attr( $value )
        );
    }

    /**
     * Renders module description textarea field.
     *
     * @return void
     */
    public static function render_module_description_field() {
        $value = (string) get_option( NATIONAL_GRID_OPTION_MODULE_DESCRIPTION, '' );
        printf(
            '<textarea name="%1$s" id="%1$s" class="large-text" rows="5">%2$s</textarea>',
            esc_attr( NATIONAL_GRID_OPTION_MODULE_DESCRIPTION ),
            esc_textarea( $value )
        );
    }

    /**
     * Renders chart animation checkbox field.
     *
     * @return void
     */
    public static function render_chart_animation_field() {
        $value = (int) get_option( NATIONAL_GRID_OPTION_CHART_ANIMATION, 1 );
        printf(
            '<label><input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr( NATIONAL_GRID_OPTION_CHART_ANIMATION ),
            checked( 1, $value, false ),
            esc_html__( 'Enable Chart.js animation on frontend charts', 'national-grid' )
        );
    }

    /**
     * Adds custom cron schedule based on configured timeout.
     *
     * @param array<string, mixed> $schedules Existing cron schedules.
     * @return array<string, mixed>
     */
    public static function add_cron_schedule( $schedules ) {
        $minutes = max( 5, (int) get_option( NATIONAL_GRID_OPTION_TIMEOUT, 5 ) );
        $schedules[ self::CRON_SCHEDULE ] = [
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display' => sprintf( __( 'National Grid every %d minutes', 'national-grid' ), $minutes ),
        ];

        $log_clear_hours = max( 1, (int) get_option( NATIONAL_GRID_OPTION_LOG_CLEAR_INTERVAL_HOURS, 336 ) );
        $schedules[ self::LOG_CLEAR_CRON_SCHEDULE ] = [
            'interval' => $log_clear_hours * HOUR_IN_SECONDS,
            'display' => sprintf( __( 'National Grid log cleanup every %d hours', 'national-grid' ), $log_clear_hours ),
        ];

        return $schedules;
    }

    /**
     * Keeps scheduled cron event in sync with current settings.
     *
     * @return void
     */
    public static function maybe_sync_cron_event() {
        $enabled = 1 === (int) get_option( NATIONAL_GRID_OPTION_AUTO_UPDATE, 0 );
        $timestamp = wp_next_scheduled( self::CRON_HOOK );

        if ( ! $enabled ) {
            while ( false !== $timestamp ) {
                wp_unschedule_event( $timestamp, self::CRON_HOOK );
                $timestamp = wp_next_scheduled( self::CRON_HOOK );
            }
            return;
        }

        if ( false === $timestamp ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    /**
     * Keeps scheduled log cleanup cron event in sync with current settings.
     *
     * @return void
     */
    public static function maybe_sync_log_clear_cron_event() {
        $enabled = 1 === (int) get_option( NATIONAL_GRID_OPTION_AUTO_CLEAR_LOG, 0 )
            && DatabaseStorage::isLogEnabled();
        $timestamp = wp_next_scheduled( self::LOG_CLEAR_CRON_HOOK );

        if ( ! $enabled ) {
            while ( false !== $timestamp ) {
                wp_unschedule_event( $timestamp, self::LOG_CLEAR_CRON_HOOK );
                $timestamp = wp_next_scheduled( self::LOG_CLEAR_CRON_HOOK );
            }
            return;
        }

        if ( false === $timestamp ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::LOG_CLEAR_CRON_SCHEDULE, self::LOG_CLEAR_CRON_HOOK );
        }
    }

    /**
     * Unschedules all plugin cron events.
     *
     * @return void
     */
    public static function clear_scheduled_events(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        while ( false !== $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
        }

        $timestamp = wp_next_scheduled( self::INITIAL_CRON_HOOK );
        while ( false !== $timestamp ) {
            wp_unschedule_event( $timestamp, self::INITIAL_CRON_HOOK );
            $timestamp = wp_next_scheduled( self::INITIAL_CRON_HOOK );
        }

        $timestamp = wp_next_scheduled( self::LOG_CLEAR_CRON_HOOK );
        while ( false !== $timestamp ) {
            wp_unschedule_event( $timestamp, self::LOG_CLEAR_CRON_HOOK );
            $timestamp = wp_next_scheduled( self::LOG_CLEAR_CRON_HOOK );
        }
    }

    /**
     * Schedules one-time initial data update after plugin activation.
     *
     * @return void
     */
    public static function schedule_initial_update_event(): void {
        $has_recurring = false !== wp_next_scheduled( self::CRON_HOOK );
        $has_initial = false !== wp_next_scheduled( self::INITIAL_CRON_HOOK );
        if ( $has_recurring || $has_initial ) {
            return;
        }

        wp_schedule_single_event( time() + 30, self::INITIAL_CRON_HOOK );
    }

    /**
     * Runs scheduled data update.
     *
     * @return void
     */
    public static function handle_cron_update() {
        self::update_data( 'cron' );
    }

    /**
     * Runs one-time data update scheduled on plugin activation.
     *
     * @return void
     */
    public static function handle_initial_cron_update() {
        $started_at_utc = gmdate( 'Y-m-d H:i:s' );

        if ( DatabaseStorage::isLogEnabled() ) {
            DatabaseStorage::logSuccess(
                'activation',
                'Initial update started (scheduled on plugin activation).',
                [],
                $started_at_utc
            );
        }

        if ( DatabaseStorage::isDebugModeEnabled() ) {
            DatabaseStorage::appendDebugLog(
                'Initial update trigger',
                [
                    'Timestamp: ' . $started_at_utc . ' UTC',
                    'Source: activation',
                    'Reason: Scheduled one-time run after plugin activation.',
                ],
                $started_at_utc
            );
        }

        self::update_data( 'activation', $started_at_utc );
    }

    /**
     * Clears plugin logs on scheduled cron run.
     *
     * @return void
     */
    public static function handle_cron_clear_log() {
        if ( ! DatabaseStorage::isLogEnabled() ) {
            return;
        }

        $result = DatabaseStorage::clearLogs();
        $debug_result = DatabaseStorage::clearDebugLogFile();
        if ( false === $result ) {
            if ( DatabaseStorage::isLogEnabled() ) {
                DatabaseStorage::logError( 'cron', 'Automatic log cleanup failed.' );
            }
            return;
        }
        if ( ! $debug_result ) {
            if ( DatabaseStorage::isLogEnabled() ) {
                DatabaseStorage::logError( 'cron', 'Automatic debug log cleanup failed.' );
            }
            return;
        }

        if ( DatabaseStorage::isLogEnabled() ) {
            DatabaseStorage::logSuccess(
                'cron',
                'Automatic log cleanup completed.',
                [
                    'rows_deleted' => (int) $result,
                    'debug_log_cleared' => true,
                ]
            );
        }
    }

    /**
     * Executes generation and demand updates and writes logs.
     *
     * @param string $source Update source label.
     * @return array<string, mixed>
     */
    public static function update_data( $source = 'manual', $started_at_utc = '' ) {
        $source = in_array( $source, [ 'manual', 'cron', 'activation' ], true ) ? $source : 'manual';
        $started_at_utc = DatabaseStorage::normalizeUtcTimestamp( $started_at_utc );
        if ( '' === $started_at_utc ) {
            $started_at_utc = gmdate( 'Y-m-d H:i:s' );
        }

        update_option( NATIONAL_GRID_OPTION_LAST_UPDATE_STARTED_AT, $started_at_utc, false );
        DatabaseStorage::setDebugTimestampContext( $started_at_utc );

        try {
            $generation_update_result = Generation::update();
            if (
                ! is_array( $generation_update_result )
                || ! isset( $generation_update_result['rows_written'], $generation_update_result['rows_aggregated'], $generation_update_result['rows_deleted'] )
            ) {
                if ( DatabaseStorage::isLogEnabled() ) {
                    DatabaseStorage::logError( $source, 'Generation update failed.', [ 'generation_result' => $generation_update_result ], $started_at_utc );
                }
                return [
                    'success' => false,
                    'message' => __( 'Update failed. Check log for details.', 'national-grid' ),
                ];
            }

            $demand_update_result = Demand::update();
            if (
                ! is_array( $demand_update_result )
                || empty( $demand_update_result['success'] )
                || ! isset( $demand_update_result['rows_written'], $demand_update_result['rows_deleted'], $demand_update_result['read'], $demand_update_result['valid'], $demand_update_result['skipped'] )
            ) {
                if ( DatabaseStorage::isLogEnabled() ) {
                    DatabaseStorage::logError( $source, 'Demand update failed.', [ 'demand_result' => $demand_update_result ], $started_at_utc );
                }
                return [
                    'success' => false,
                    'message' => __( 'Update failed. Check log for details.', 'national-grid' ),
                ];
            }

            if ( DatabaseStorage::isLogEnabled() ) {
                DatabaseStorage::logSuccess(
                    $source,
                    'Data updated successfully.',
                    [
                        'generation' => $generation_update_result,
                        'demand' => $demand_update_result,
                    ],
                    $started_at_utc
                );
            }

            if ( DatabaseStorage::isDebugModeEnabled() ) {
                DatabaseStorage::appendDebugLog(
                    'Update cycle summary',
                    [
                        'Timestamp: ' . $started_at_utc . ' UTC',
                        'Source: ' . $source,
                        'Generation update result:',
                        wp_json_encode( $generation_update_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                        'Demand update result:',
                        wp_json_encode( $demand_update_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                    ],
                    $started_at_utc
                );
                DatabaseStorage::logChartComputationDebug();
            }

            return [
                'success' => true,
                'message' => __( 'Update completed. Check log for details.', 'national-grid' ),
            ];
        } catch ( DataException $e ) {
            if ( DatabaseStorage::isLogEnabled() ) {
                DatabaseStorage::logError(
                    $source,
                    $e->getMessage(),
                    [
                        'exception' => get_class( $e ),
                        'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : '',
                    ],
                    $started_at_utc
                );
            }

            return [
                'success' => false,
                'message' => __( 'Update failed. Check log for details.', 'national-grid' ),
            ];
        } catch ( Throwable $e ) {
            if ( DatabaseStorage::isLogEnabled() ) {
                DatabaseStorage::logError(
                    $source,
                    'Unexpected update error.',
                    [
                        'exception' => get_class( $e ),
                        'message' => $e->getMessage(),
                    ],
                    $started_at_utc
                );
            }

            return [
                'success' => false,
                'message' => __( 'Update failed. Check log for details.', 'national-grid' ),
            ];
        } finally {
            DatabaseStorage::setDebugTimestampContext();
        }
    }

    /**
     * Handles update request from standard admin form submit.
     *
     * @return void
     */
    public static function handle_update_data() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'national-grid' ) );
        }

        check_admin_referer( self::UPDATE_ACTION, 'national_grid_update_nonce' );

        $updated = self::update_data( 'manual' );
        $redirect_url = add_query_arg(
            'national_grid_update',
            ( ! empty( $updated['success'] ) ) ? 'success' : 'error',
            admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handles update request from admin AJAX action.
     *
     * @return void
     */
    public static function handle_update_data_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'You do not have permission to do that.', 'national-grid' ),
                ],
                403
            );
        }

        if ( ! check_ajax_referer( self::UPDATE_ACTION, 'nonce', false ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'Nonce mismatch. Please refresh the page and try again.', 'national-grid' ),
                ],
                403
            );
        }

        $updated = self::update_data( 'manual' );
        $log_html = self::get_logs_section_html();

        if ( ! empty( $updated['success'] ) ) {
            wp_send_json_success(
                [
                    'message' => $updated['message'],
                    'logHtml' => $log_html,
                ]
            );
        }

        wp_send_json_error(
            [
                'message' => ! empty( $updated['message'] ) ? $updated['message'] : __( 'Data update failed.', 'national-grid' ),
                'logHtml' => $log_html,
            ],
            500
        );
    }

    /**
     * Returns refreshed log section HTML via AJAX.
     *
     * @return void
     */
    public static function handle_fetch_log_section_ajax() {
        if ( ! DatabaseStorage::isLogEnabled() ) {
            wp_send_json_success(
                [
                    'html' => '',
                ]
            );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'You do not have permission to do that.', 'national-grid' ),
                ],
                403
            );
        }

        if ( ! check_ajax_referer( self::UPDATE_ACTION, 'nonce', false ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'Nonce mismatch. Please refresh the page and try again.', 'national-grid' ),
                ],
                403
            );
        }

        ob_start();
        self::render_logs_section();
        $html = ob_get_clean();

        wp_send_json_success(
            [
                'html' => $html,
            ]
        );
    }

    /**
     * Clears stored update logs.
     *
     * @return void
     */
    public static function handle_clear_log() {
        if ( ! DatabaseStorage::isLogEnabled() ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=settings' ) );
            exit;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'national-grid' ) );
        }

        check_admin_referer( self::CLEAR_LOG_ACTION, 'national_grid_clear_log_nonce' );

        DatabaseStorage::clearLogs();

        wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=logs&national_grid_log_cleared=1' ) );
        exit;
    }

    /**
     * Clears debug log file.
     *
     * @return void
     */
    public static function handle_clear_debug_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'national-grid' ) );
        }

        check_admin_referer( self::CLEAR_DEBUG_LOG_ACTION, 'national_grid_clear_debug_log_nonce' );
        DatabaseStorage::clearDebugLogFile();

        wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=debug&national_grid_debug_log_cleared=1' ) );
        exit;
    }

    /**
     * Enqueues admin assets on plugin settings page only.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public static function enqueue_assets( $hook ) {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }
        $asset_suffix = self::get_asset_suffix();

        wp_enqueue_style(
            'national-grid-admin',
            NATIONAL_GRID_PLUGIN_URL . 'assets/css/admin' . $asset_suffix . '.css',
            [],
            NATIONAL_GRID_VERSION
        );
        wp_enqueue_script(
            'national-grid-admin',
            NATIONAL_GRID_PLUGIN_URL . 'assets/js/admin' . $asset_suffix . '.js',
            [ 'jquery' ],
            NATIONAL_GRID_VERSION,
            true
        );
        wp_localize_script(
            'national-grid-admin',
            'nationalGridAdmin',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'action' => self::UPDATE_ACTION,
                'fetchLogAction' => self::FETCH_LOG_ACTION,
                'nonce' => wp_create_nonce( self::UPDATE_ACTION ),
                'unknownError' => __( 'Unexpected error. Please try again.', 'national-grid' ),
            ]
        );
    }

    /**
     * Returns admin asset suffix based on debug flags.
     *
     * @return string
     */
    private static function get_asset_suffix() {
        $is_debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG )
            || ( defined( 'WP_DEBUG' ) && WP_DEBUG );

        return $is_debug ? '' : '.min';
    }

    /**
     * Renders update log table section.
     *
     * @return void
     */
    private static function render_logs_section() {
        $per_page = 9;
        $current_page = isset( $_GET['log_page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? max( 1, (int) $_GET['log_page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : 1;
        $total_logs = DatabaseStorage::getLogsCount();
        $total_pages = max( 1, (int) ceil( $total_logs / $per_page ) );
        if ( $current_page > $total_pages ) {
            $current_page = $total_pages;
        }
        $offset = ( $current_page - 1 ) * $per_page;
        $logs = DatabaseStorage::getRecentLogs( $per_page, $offset );

        echo '<div id="national-grid-admin-log-section" class="national-grid-admin-log-section">';
        echo '<h2>' . esc_html__( 'Update Log', 'national-grid' ) . '</h2>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="national-grid-admin-log-clear-form">';
        wp_nonce_field( self::CLEAR_LOG_ACTION, 'national_grid_clear_log_nonce' );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::CLEAR_LOG_ACTION ) . '" />';
        submit_button( __( 'Clear log', 'national-grid' ), 'delete', 'submit', false );
        echo '</form>';

        if ( empty( $logs ) ) {
            echo '<p>' . esc_html__( 'Log is empty.', 'national-grid' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped national-grid-admin-log-table">';
        echo '<colgroup>';
        echo '<col class="national-grid-admin-log-col-time" />';
        echo '<col class="national-grid-admin-log-col-source" />';
        echo '<col class="national-grid-admin-log-col-status" />';
        echo '<col class="national-grid-admin-log-col-message" />';
        echo '</colgroup>';
        echo '<thead><tr>';
        echo '<th class="national-grid-admin-log-th-time">' . esc_html__( 'Time (UTC)', 'national-grid' ) . '</th>';
        echo '<th class="national-grid-admin-log-th-source">' . esc_html__( 'Source', 'national-grid' ) . '</th>';
        echo '<th class="national-grid-admin-log-th-status">' . esc_html__( 'Status', 'national-grid' ) . '</th>';
        echo '<th class="national-grid-admin-log-th-message">' . esc_html__( 'Message', 'national-grid' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $logs as $log ) {
            $status = isset( $log['status'] ) ? (string) $log['status'] : '';
            $row_class = 'error' === $status ? 'national-grid-log-row-error' : 'national-grid-log-row-success';

            echo '<tr class="' . esc_attr( $row_class ) . '">';
            echo '<td class="national-grid-admin-log-td-time">' . esc_html( isset( $log['created_at'] ) ? (string) $log['created_at'] : '' ) . '</td>';
            echo '<td class="national-grid-admin-log-td-source">' . esc_html( isset( $log['source'] ) ? (string) $log['source'] : '' ) . '</td>';
            echo '<td class="national-grid-admin-log-td-status">' . esc_html( $status ) . '</td>';

            echo '<td class="national-grid-admin-log-td-message">';
            echo esc_html( isset( $log['message'] ) ? (string) $log['message'] : '' );

            if ( ! empty( $log['context'] ) ) {
                $context = json_decode( (string) $log['context'], true );
                if ( is_array( $context ) ) {
                    echo '<details><summary>' . esc_html__( 'Context', 'national-grid' ) . '</summary><pre>' . esc_html( self::format_log_context_for_display( $context ) ) . '</pre></details>';
                }
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ( $total_pages > 1 ) {
            $pagination_links = paginate_links(
                [
                    'base' => add_query_arg(
                        [
                            'page' => self::PAGE_SLUG,
                            'tab' => 'logs',
                            'log_page' => '%#%',
                        ],
                        admin_url( 'options-general.php' )
                    ),
                    'format' => '',
                    'current' => $current_page,
                    'total' => $total_pages,
                    'prev_text' => __( '&laquo; Previous', 'national-grid' ),
                    'next_text' => __( 'Next &raquo;', 'national-grid' ),
                ]
            );

            if ( is_string( $pagination_links ) && '' !== $pagination_links ) {
                echo '<div class="national-grid-admin-log-pagination">';
                echo '<p class="national-grid-admin-log-pagination-summary">' . esc_html(
                    sprintf(
                        /* translators: 1: current page number, 2: total pages */
                        __( 'Page %1$d of %2$d', 'national-grid' ),
                        $current_page,
                        $total_pages
                    )
                ) . '</p>';
                echo '<div class="national-grid-admin-log-pagination-links">' . wp_kses_post( $pagination_links ) . '</div>';
                echo '</div>';
            }
        }

        echo '</div>';
    }

    /**
     * Formats structured log context into a readable text block.
     *
     * @param array<string, mixed> $context Raw context payload.
     * @return string
     */
    private static function format_log_context_for_display( array $context ): string {
        $lines = [];

        if ( isset( $context['generation'] ) && is_array( $context['generation'] ) ) {
            $generation = $context['generation'];
            $lines[] = sprintf(
                'Generation update: wrote %d rows, aggregated %d rows, deleted %d old rows.',
                isset( $generation['rows_written'] ) ? (int) $generation['rows_written'] : 0,
                isset( $generation['rows_aggregated'] ) ? (int) $generation['rows_aggregated'] : 0,
                isset( $generation['rows_deleted'] ) ? (int) $generation['rows_deleted'] : 0
            );
            unset( $context['generation'] );
        }

        if ( isset( $context['demand'] ) && is_array( $context['demand'] ) ) {
            $demand = $context['demand'];
            $lines[] = sprintf(
                'Demand update: read %d rows, accepted %d rows, skipped %d rows, wrote %d rows, deleted %d old rows. Success: %s.',
                isset( $demand['read'] ) ? (int) $demand['read'] : 0,
                isset( $demand['valid'] ) ? (int) $demand['valid'] : 0,
                isset( $demand['skipped'] ) ? (int) $demand['skipped'] : 0,
                isset( $demand['rows_written'] ) ? (int) $demand['rows_written'] : 0,
                isset( $demand['rows_deleted'] ) ? (int) $demand['rows_deleted'] : 0,
                ! empty( $demand['success'] ) ? 'yes' : 'no'
            );
            unset( $context['demand'] );
        }

        if ( isset( $context['generation_result'] ) ) {
            $lines[] = 'Generation result payload: ' . self::describe_context_value( $context['generation_result'] );
            unset( $context['generation_result'] );
        }

        if ( isset( $context['demand_result'] ) ) {
            $lines[] = 'Demand result payload: ' . self::describe_context_value( $context['demand_result'] );
            unset( $context['demand_result'] );
        }

        if ( isset( $context['exception'] ) ) {
            $lines[] = 'Exception type: ' . self::describe_context_value( $context['exception'] ) . '.';
            unset( $context['exception'] );
        }

        if ( isset( $context['message'] ) ) {
            $lines[] = 'Exception message: ' . self::describe_context_value( $context['message'] ) . '.';
            unset( $context['message'] );
        }

        if ( isset( $context['previous'] ) && '' !== trim( (string) $context['previous'] ) ) {
            $lines[] = 'Previous exception message: ' . trim( (string) $context['previous'] ) . '.';
            unset( $context['previous'] );
        }

        foreach ( $context as $key => $value ) {
            $label = ucwords( str_replace( '_', ' ', (string) $key ) );
            $lines[] = $label . ': ' . self::describe_context_value( $value ) . '.';
        }

        if ( empty( $lines ) ) {
            return __( 'No additional context available.', 'national-grid' );
        }

        return implode( "\n", $lines );
    }

    /**
     * Converts context values into concise human-readable text.
     *
     * @param mixed $value Context value.
     * @return string
     */
    private static function describe_context_value( $value ): string {
        if ( is_bool( $value ) ) {
            return $value ? 'yes' : 'no';
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (string) $value;
        }

        if ( is_string( $value ) ) {
            return trim( $value );
        }

        if ( null === $value ) {
            return 'null';
        }

        if ( is_array( $value ) ) {
            if ( empty( $value ) ) {
                return 'empty array';
            }

            $is_list = array_values( $value ) === $value;
            if ( $is_list ) {
                $preview = array_slice(
                    array_map(
                        static function ( $item ) {
                            return is_scalar( $item ) || null === $item
                                ? (string) ( null === $item ? 'null' : $item )
                                : '[complex value]';
                        },
                        $value
                    ),
                    0,
                    5
                );
                $suffix = count( $value ) > 5 ? ', ...' : '';
                return 'list (' . count( $value ) . ' items): ' . implode( ', ', $preview ) . $suffix;
            }

            $keys = array_slice( array_keys( $value ), 0, 8 );
            $suffix = count( $value ) > 8 ? ', ...' : '';
            return 'associative array with keys: ' . implode( ', ', array_map( 'strval', $keys ) ) . $suffix;
        }

        if ( is_object( $value ) ) {
            return 'object of class ' . get_class( $value );
        }

        return 'unrecognized value';
    }

    /**
     * Returns rendered update log section as HTML.
     *
     * @return string
     */
    private static function get_logs_section_html() {
        if ( ! DatabaseStorage::isLogEnabled() ) {
            return '';
        }

        ob_start();
        self::render_logs_section();
        return ob_get_clean();
    }

    /**
     * Renders plugin information section from a dedicated template.
     *
     * @return void
     */
    private static function render_info_section() {
        $template_path = NATIONAL_GRID_PLUGIN_DIR . 'templates/admin-info.php';
        if ( ! is_readable( $template_path ) ) {
            echo '<p>' . esc_html__( 'Info template is missing.', 'national-grid' ) . '</p>';
            return;
        }

        include $template_path;
    }

    /**
     * Renders debug log file content.
     *
     * @return void
     */
    private static function render_debug_section() {
        $path = DatabaseStorage::getDebugLogPathForAdmin();

        echo '<div class="national-grid-admin-debug-section">';
        echo '<h2>' . esc_html__( 'Debug log', 'national-grid' ) . '</h2>';
        echo '<p><strong>' . esc_html__( 'File:', 'national-grid' ) . '</strong> <code>' . esc_html( $path ) . '</code></p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="national-grid-admin-debug-clear-form">';
        wp_nonce_field( self::CLEAR_DEBUG_LOG_ACTION, 'national_grid_clear_debug_log_nonce' );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::CLEAR_DEBUG_LOG_ACTION ) . '" />';
        submit_button( __( 'Clear debug log', 'national-grid' ), 'delete', 'submit', false );
        echo '</form>';

        if ( ! file_exists( $path ) ) {
            echo '<p>' . esc_html__( 'Debug log file does not exist yet. Run an update with Debug mode enabled.', 'national-grid' ) . '</p>';
            echo '</div>';
            return;
        }

        if ( ! is_readable( $path ) ) {
            echo '<p>' . esc_html__( 'Debug log file is not readable.', 'national-grid' ) . '</p>';
            echo '</div>';
            return;
        }

        $content = (string) file_get_contents( $path );
        if ( '' === trim( $content ) ) {
            echo '<p>' . esc_html__( 'Debug log file is empty.', 'national-grid' ) . '</p>';
            echo '</div>';
            return;
        }

        $rendered_content = self::render_debug_log_with_api_links( $content );
        echo '<div class="national-grid-admin-debug-log">' . wp_kses(
            $rendered_content,
            [
                'br' => [],
                'strong' => [],
                'a' => [
                    'href' => [],
                    'target' => [],
                    'rel' => [],
                ],
            ]
        ) . '</div>';
        echo '</div>';
    }

    /**
     * Escapes debug log text and converts URLs to bold clickable links.
     *
     * @param string $content Raw debug log content.
     * @return string
     */
    private static function render_debug_log_with_api_links( string $content ): string {
        $escaped_content = esc_html( $content );
        $clickable_content = make_clickable( $escaped_content );
        $clickable_content = (string) preg_replace_callback(
            '/<a\s+([^>]+)>/i',
            static function ( $matches ) {
                $attributes = isset( $matches[1] ) ? (string) $matches[1] : '';
                $attributes = (string) preg_replace( '/\s+target=(["\']).*?\1/i', '', $attributes );
                $attributes = (string) preg_replace( '/\s+rel=(["\']).*?\1/i', '', $attributes );

                return '<a ' . trim( $attributes ) . ' target="_blank" rel="noopener noreferrer">';
            },
            $clickable_content
        );

        return (string) preg_replace(
            '/(<a [^>]*>https?:\/\/[^<]+<\/a>)/i',
            '<strong>$1</strong>',
            $clickable_content
        );
    }

    /**
     * Renders the plugin settings page.
     *
     * @return void
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $debug_mode_enabled = 1 === (int) get_option( NATIONAL_GRID_OPTION_DEBUG_MODE, 0 );
        $is_log_enabled = DatabaseStorage::isLogEnabled();
        $active_tab = isset( $_GET['tab'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : 'settings';
        if ( ! in_array( $active_tab, [ 'settings', 'update', 'logs', 'info', 'debug' ], true ) ) {
            $active_tab = 'settings';
        }
        if ( 'debug' === $active_tab && ! $debug_mode_enabled ) {
            $active_tab = 'settings';
        }
        if ( 'logs' === $active_tab && ! $is_log_enabled ) {
            $active_tab = 'settings';
        }

        $settings_tab_url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab' => 'settings',
            ],
            admin_url( 'options-general.php' )
        );
        $logs_tab_url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab' => 'logs',
            ],
            admin_url( 'options-general.php' )
        );
        $update_tab_url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab' => 'update',
            ],
            admin_url( 'options-general.php' )
        );
        $info_tab_url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab' => 'info',
            ],
            admin_url( 'options-general.php' )
        );
        $debug_tab_url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab' => 'debug',
            ],
            admin_url( 'options-general.php' )
        );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'National Grid', 'national-grid' ) . '</h1>';
        echo '<nav class="nav-tab-wrapper">';
        echo '<a href="' . esc_url( $settings_tab_url ) . '" class="nav-tab ' . esc_attr( 'settings' === $active_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Options', 'national-grid' ) . '</a>';
        echo '<a href="' . esc_url( $update_tab_url ) . '" class="nav-tab ' . esc_attr( 'update' === $active_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Update data', 'national-grid' ) . '</a>';
        if ( $is_log_enabled ) {
            echo '<a href="' . esc_url( $logs_tab_url ) . '" class="nav-tab ' . esc_attr( 'logs' === $active_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Log', 'national-grid' ) . '</a>';
        }
        echo '<a href="' . esc_url( $info_tab_url ) . '" class="nav-tab ' . esc_attr( 'info' === $active_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Info', 'national-grid' ) . '</a>';
        if ( $debug_mode_enabled ) {
            echo '<a href="' . esc_url( $debug_tab_url ) . '" class="nav-tab ' . esc_attr( 'debug' === $active_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Debug', 'national-grid' ) . '</a>';
        }
        echo '</nav>';

        if ( $is_log_enabled && isset( $_GET['national_grid_log_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Log cleared.', 'national-grid' ) . '</p></div>';
        }
        if ( isset( $_GET['national_grid_debug_log_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Debug log cleared.', 'national-grid' ) . '</p></div>';
        }

        if ( 'settings' === $active_tab ) {
            echo '<form method="post" action="options.php">';

            settings_fields( 'national_grid_settings' );
            do_settings_sections( self::PAGE_SLUG );

            echo '<div class="national-grid-admin-buttons">';
            submit_button( __( 'Save data', 'national-grid' ), 'primary', 'submit', false );
            echo '</div>';
            echo '</form>';
        }

        if ( 'update' === $active_tab ) {
            echo '<div class="national-grid-admin-buttons">';
            echo '<p class="description national-grid-admin-update-note" style="margin-top:12px;">' . esc_html__( 'By clicking this button, you will trigger a manual data update.', 'national-grid' ) . '</p>';
            echo '<div class="national-grid-admin-update-row">';
            echo '<button type="button" id="national-grid-update-button" class="button button-secondary">' . esc_html__( 'Update data', 'national-grid' ) . '</button>';
            echo '<span id="national-grid-update-loader" class="spinner national-grid-admin-loader"></span>';
            echo '</div>';
            echo '</div>';
            echo '<div id="national-grid-update-message" class="national-grid-admin-message" aria-live="polite"></div>';
        }

        if ( 'logs' === $active_tab && $is_log_enabled ) {
            self::render_logs_section();
        }

        if ( 'info' === $active_tab ) {
            self::render_info_section();
        }

        if ( 'debug' === $active_tab && $debug_mode_enabled ) {
            self::render_debug_section();
        }

        echo '</div>';
    }
}
