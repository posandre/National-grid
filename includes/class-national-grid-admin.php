<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class National_Grid_Admin {
    private const PAGE_SLUG = 'national-grid-settings';
    private const UPDATE_ACTION = 'national_grid_update_data';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_' . self::UPDATE_ACTION, array( __CLASS__, 'handle_update_data' ) );
        add_action( 'wp_ajax_' . self::UPDATE_ACTION, array( __CLASS__, 'handle_update_data_ajax' ) );
    }

    public static function register_menu() {
        add_options_page(
            __( 'National Grid', 'national-grid' ),
            __( 'National Grid', 'national-grid' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register_settings() {
        register_setting(
            'national_grid_settings',
            NATIONAL_GRID_OPTION_TIMEOUT,
            array(
                'type' => 'integer',
                'sanitize_callback' => array( __CLASS__, 'sanitize_timeout' ),
                'default' => 30,
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

    public static function sanitize_timeout( $value ) {
        $value = absint( $value );

        if ( $value <= 0 ) {
            $value = 30;
        }

        return $value;
    }

    public static function render_timeout_field() {
        $value = (int) get_option( NATIONAL_GRID_OPTION_TIMEOUT, 30 );
        printf(
            '<input type="number" name="%1$s" id="%1$s" value="%2$d" class="small-text" min="1" />',
            esc_attr( NATIONAL_GRID_OPTION_TIMEOUT ),
            $value
        );
    }

    public static function render_module_title_field() {
        $value = (string) get_option( NATIONAL_GRID_OPTION_MODULE_TITLE, '' );
        printf(
            '<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" />',
            esc_attr( NATIONAL_GRID_OPTION_MODULE_TITLE ),
            esc_attr( $value )
        );
    }

    public static function render_module_description_field() {
        $value = (string) get_option( NATIONAL_GRID_OPTION_MODULE_DESCRIPTION, '' );
        printf(
            '<textarea name="%1$s" id="%1$s" class="large-text" rows="5">%2$s</textarea>',
            esc_attr( NATIONAL_GRID_OPTION_MODULE_DESCRIPTION ),
            esc_textarea( $value )
        );
    }

    public static function update_data() {
        try {
            $generation_update_result = Generation::update();
            $demand_update_result = Demand::update();

            if (
                ! is_array( $generation_update_result )
                || ! isset( $generation_update_result['rows_written'], $generation_update_result['rows_aggregated'], $generation_update_result['rows_deleted'] )
            ) {
                return array(
                    'success' => false,
                    'message' => __( 'Failed to write data to database.', 'national-grid' ),
                );
            }

            if (
                ! is_array( $demand_update_result )
                || empty( $demand_update_result['success'] )
                || ! isset( $demand_update_result['rows_written'], $demand_update_result['rows_deleted'], $demand_update_result['read'], $demand_update_result['valid'], $demand_update_result['skipped'] )
            ) {
                return array(
                    'success' => false,
                    'message' => __( 'Generation updated, but demand update failed.', 'national-grid' ),
                );
            }

            return array(
                'success' => true,
                'message' => sprintf(
                    /* translators: 1: generation written rows, 2: generation aggregated rows, 3: generation deleted rows, 4: demand written rows, 5: demand deleted rows, 6: demand read rows, 7: demand valid rows, 8: demand skipped rows */
                    __( 'Data updated successfully.<br><br><strong>1. Generation update:</strong> <br>— rows written: %1$d,<br>— rows aggregated: %2$d,<br>— rows deleted: %3$d.<br><br><strong>2. Demand update:</strong><br>— rows written: %4$d,<br>— rows deleted: %5$d<br>CSV file: read: %6$d, valid: %7$d, skipped: %8$d.', 'national-grid' ),
                    (int) $generation_update_result['rows_written'],
                    (int) $generation_update_result['rows_aggregated'],
                    (int) $generation_update_result['rows_deleted'],
                    (int) $demand_update_result['rows_written'],
                    (int) $demand_update_result['rows_deleted'],
                    (int) $demand_update_result['read'],
                    (int) $demand_update_result['valid'],
                    (int) $demand_update_result['skipped']
                )
            );
        } catch ( Throwable $e ) {
            return array(
                'success' => false,
                'rows_written' => 0,
                'rows_aggregated' => 0,
                'rows_deleted' => 0,
                'message' => $e->getMessage(),
            );
        }
    }

    public static function handle_update_data() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'national-grid' ) );
        }

        check_admin_referer( self::UPDATE_ACTION, 'national_grid_update_nonce' );

        $updated = self::update_data();
        $redirect_url = add_query_arg(
            'national_grid_update',
            ( ! empty( $updated['success'] ) ) ? 'success' : 'error',
            admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

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

        $updated = self::update_data();

        if ( ! empty( $updated['success'] ) ) {
            wp_send_json_success(
                array(
                    'message' => $updated['message'],
                )
            );
        }

        wp_send_json_error(
            array(
                'message' => ! empty( $updated['message'] ) ? $updated['message'] : __( 'Data update failed.', 'national-grid' ),
            ),
            500
        );
    }

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
                'nonce' => wp_create_nonce( self::UPDATE_ACTION ),
                'unknownError' => __( 'Unexpected error. Please try again.', 'national-grid' ),
            )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'National Grid', 'national-grid' ) . '</h1>';
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

        echo '</div>';
    }
}
