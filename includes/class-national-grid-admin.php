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
    /** Cron hook name for scheduled data updates. */
    private const CRON_HOOK = 'national_grid_cron_update_data';
    /** Custom cron schedule key based on configured timeout. */
    private const CRON_SCHEDULE = 'national_grid_custom_interval';

    /**
     * Registers admin page, settings, actions and cron hooks.
     *
     * @return void
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        add_action( 'admin_post_' . self::UPDATE_ACTION, array( __CLASS__, 'handle_update_data' ) );
        add_action( 'wp_ajax_' . self::UPDATE_ACTION, array( __CLASS__, 'handle_update_data_ajax' ) );
        add_action( 'wp_ajax_' . self::FETCH_LOG_ACTION, array( __CLASS__, 'handle_fetch_log_section_ajax' ) );

        add_action( 'admin_post_' . self::CLEAR_LOG_ACTION, array( __CLASS__, 'handle_clear_log' ) );

        add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
        add_action( 'init', array( __CLASS__, 'maybe_sync_cron_event' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'handle_cron_update' ) );
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
            array( __CLASS__, 'render_page' )
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
            array(
                'type' => 'integer',
                'sanitize_callback' => array( __CLASS__, 'sanitize_timeout' ),
                'default' => 5,
            )
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_MODULE_TITLE,
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            )
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_MODULE_DESCRIPTION,
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default' => '',
            )
        );

        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_AUTO_UPDATE,
            array(
                'type' => 'boolean',
                'sanitize_callback' => array( __CLASS__, 'sanitize_auto_update' ),
                'default' => 0,
            )
        );

        add_settings_section(
            'national_grid_main',
            __( 'National Grid Settings', 'national-grid' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_TIMEOUT,
            __( 'National grid timeout', 'national-grid' ),
            array( __CLASS__, 'render_timeout_field' ),
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_AUTO_UPDATE,
            __( 'Automatic cron update', 'national-grid' ),
            array( __CLASS__, 'render_auto_update_field' ),
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_MODULE_TITLE,
            __( 'Module title', 'national-grid' ),
            array( __CLASS__, 'render_module_title_field' ),
            self::PAGE_SLUG,
            'national_grid_main'
        );

        add_settings_field(
            NATIONAL_GRID_OPTION_MODULE_DESCRIPTION,
            __( 'Module description', 'national-grid' ),
            array( __CLASS__, 'render_module_description_field' ),
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

        if ( $value <= 0 ) {
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
     * Renders timeout input field.
     *
     * @return void
     */
    public static function render_timeout_field() {
        $value = (int) get_option( NATIONAL_GRID_OPTION_TIMEOUT, 5 );
        printf(
            '<input type="number" name="%1$s" id="%1$s" value="%2$d" class="small-text" min="1" /> <span>%3$s</span>',
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
     * Adds custom cron schedule based on configured timeout.
     *
     * @param array<string, mixed> $schedules Existing cron schedules.
     * @return array<string, mixed>
     */
    public static function add_cron_schedule( $schedules ) {
        $minutes = max( 1, (int) get_option( NATIONAL_GRID_OPTION_TIMEOUT, 5 ) );
        $schedules[ self::CRON_SCHEDULE ] = array(
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display' => sprintf( __( 'National Grid every %d minutes', 'national-grid' ), $minutes ),
        );

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
     * Runs scheduled data update.
     *
     * @return void
     */
    public static function handle_cron_update() {
        self::update_data( 'cron' );
    }

    /**
     * Executes generation and demand updates and writes logs.
     *
     * @param string $source Update source label.
     * @return array<string, mixed>
     */
    public static function update_data( $source = 'manual' ) {
        $source = in_array( $source, array( 'manual', 'cron' ), true ) ? $source : 'manual';

        try {
            $generation_update_result = Generation::update();
            if (
                ! is_array( $generation_update_result )
                || ! isset( $generation_update_result['rows_written'], $generation_update_result['rows_aggregated'], $generation_update_result['rows_deleted'] )
            ) {
                DatabaseStorage::logError( $source, 'Generation update failed.', array( 'generation_result' => $generation_update_result ) );
                return array(
                    'success' => false,
                    'message' => __( 'Update failed. Check log for details.', 'national-grid' ),
                );
            }

            $demand_update_result = Demand::update();
            if (
                ! is_array( $demand_update_result )
                || empty( $demand_update_result['success'] )
                || ! isset( $demand_update_result['rows_written'], $demand_update_result['rows_deleted'], $demand_update_result['read'], $demand_update_result['valid'], $demand_update_result['skipped'] )
            ) {
                DatabaseStorage::logError( $source, 'Demand update failed.', array( 'demand_result' => $demand_update_result ) );
                return array(
                    'success' => false,
                    'message' => __( 'Update failed. Check log for details.', 'national-grid' ),
                );
            }

            DatabaseStorage::logSuccess(
                $source,
                'Data updated successfully.',
                array(
                    'generation' => $generation_update_result,
                    'demand' => $demand_update_result,
                )
            );

            return array(
                'success' => true,
                'message' => __( 'Update completed. Check log for details.', 'national-grid' ),
            );
        } catch ( DataException $e ) {
            DatabaseStorage::logError(
                $source,
                $e->getMessage(),
                array(
                    'exception' => get_class( $e ),
                    'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : '',
                )
            );

            return array(
                'success' => false,
                'message' => __( 'Update failed. Check log for details.', 'national-grid' ),
            );
        } catch ( Throwable $e ) {
            DatabaseStorage::logError(
                $source,
                'Unexpected update error.',
                array(
                    'exception' => get_class( $e ),
                    'message' => $e->getMessage(),
                )
            );

            return array(
                'success' => false,
                'message' => __( 'Update failed. Check log for details.', 'national-grid' ),
            );
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
                array(
                    'message' => __( 'You do not have permission to do that.', 'national-grid' ),
                ),
                403
            );
        }

        if ( ! check_ajax_referer( self::UPDATE_ACTION, 'nonce', false ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Nonce mismatch. Please refresh the page and try again.', 'national-grid' ),
                ),
                403
            );
        }

        $updated = self::update_data( 'manual' );
        $log_html = self::get_logs_section_html();

        if ( ! empty( $updated['success'] ) ) {
            wp_send_json_success(
                array(
                    'message' => $updated['message'],
                    'logHtml' => $log_html,
                )
            );
        }

        wp_send_json_error(
            array(
                'message' => ! empty( $updated['message'] ) ? $updated['message'] : __( 'Data update failed.', 'national-grid' ),
                'logHtml' => $log_html,
            ),
            500
        );
    }

    /**
     * Returns refreshed log section HTML via AJAX.
     *
     * @return void
     */
    public static function handle_fetch_log_section_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'You do not have permission to do that.', 'national-grid' ),
                ),
                403
            );
        }

        if ( ! check_ajax_referer( self::UPDATE_ACTION, 'nonce', false ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Nonce mismatch. Please refresh the page and try again.', 'national-grid' ),
                ),
                403
            );
        }

        ob_start();
        self::render_logs_section();
        $html = ob_get_clean();

        wp_send_json_success(
            array(
                'html' => $html,
            )
        );
    }

    /**
     * Clears stored update logs.
     *
     * @return void
     */
    public static function handle_clear_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'national-grid' ) );
        }

        check_admin_referer( self::CLEAR_LOG_ACTION, 'national_grid_clear_log_nonce' );

        DatabaseStorage::clearLogs();

        wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&national_grid_log_cleared=1' ) );
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

        wp_enqueue_style(
            'national-grid-admin',
            NATIONAL_GRID_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            NATIONAL_GRID_VERSION
        );
        wp_enqueue_script(
            'national-grid-admin',
            NATIONAL_GRID_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            NATIONAL_GRID_VERSION,
            true
        );
        wp_localize_script(
            'national-grid-admin',
            'nationalGridAdmin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'action' => self::UPDATE_ACTION,
                'fetchLogAction' => self::FETCH_LOG_ACTION,
                'nonce' => wp_create_nonce( self::UPDATE_ACTION ),
                'unknownError' => __( 'Unexpected error. Please try again.', 'national-grid' ),
            )
        );
    }

    /**
     * Renders update log table section.
     *
     * @return void
     */
    private static function render_logs_section() {
        $logs = DatabaseStorage::getRecentLogs( 200 );

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
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Time (UTC)', 'national-grid' ) . '</th>';
        echo '<th>' . esc_html__( 'Source', 'national-grid' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'national-grid' ) . '</th>';
        echo '<th>' . esc_html__( 'Message', 'national-grid' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $logs as $log ) {
            $status = isset( $log['status'] ) ? (string) $log['status'] : '';
            $row_class = 'error' === $status ? 'national-grid-log-row-error' : 'national-grid-log-row-success';

            echo '<tr class="' . esc_attr( $row_class ) . '">';
            echo '<td>' . esc_html( isset( $log['created_at'] ) ? (string) $log['created_at'] : '' ) . '</td>';
            echo '<td>' . esc_html( isset( $log['source'] ) ? (string) $log['source'] : '' ) . '</td>';
            echo '<td>' . esc_html( $status ) . '</td>';

            echo '<td>';
            echo esc_html( isset( $log['message'] ) ? (string) $log['message'] : '' );

            if ( ! empty( $log['context'] ) ) {
                $context = json_decode( (string) $log['context'], true );
                if ( is_array( $context ) ) {
                    echo '<details><summary>' . esc_html__( 'Context', 'national-grid' ) . '</summary><pre>' . esc_html( print_r( $context, true ) ) . '</pre></details>';
                }
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Returns rendered update log section as HTML.
     *
     * @return string
     */
    private static function get_logs_section_html() {
        ob_start();
        self::render_logs_section();
        return ob_get_clean();
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

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'National Grid', 'national-grid' ) . '</h1>';

        if ( isset( $_GET['national_grid_log_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Log cleared.', 'national-grid' ) . '</p></div>';
        }

        echo '<form method="post" action="options.php">';

        settings_fields( 'national_grid_settings' );
        do_settings_sections( self::PAGE_SLUG );

        echo '<div class="national-grid-admin-buttons">';
        submit_button( __( 'Save data', 'national-grid' ), 'primary', 'submit', false );
        echo '<hr class="national-grid-admin-divider" />';
        echo '<div class="national-grid-admin-update-row">';
        echo '<button type="button" id="national-grid-update-button" class="button button-secondary">' . esc_html__( 'Update data', 'national-grid' ) . '</button>';
        echo '<span id="national-grid-update-loader" class="spinner national-grid-admin-loader"></span>';
        echo '</div>';
        echo '</div>';
        echo '<div id="national-grid-update-message" class="national-grid-admin-message" aria-live="polite"></div>';
        echo '</form>';

        self::render_logs_section();

        echo '</div>';
    }
}
