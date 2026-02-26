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
            $new_generation = Generation::update();
            $rows_written = self::write_generation_to_past_five_minutes( $new_generation );

            if ( false === $rows_written ) {
                return array(
                    'success' => false,
                    'rows' => 0,
                    'message' => __( 'Failed to write data to database.', 'national-grid' ),
                );
            }

            return array(
                'success' => true,
                'rows' => $rows_written,
                'message' => sprintf(
                    /* translators: %d: number of saved rows */
                    __( 'Data updated successfully. Saved rows: %d.<br><pre>%s</pre>', 'national-grid' ),
                    $rows_written,
                    print_r( $new_generation, true )
                ),
            );
        } catch ( Throwable $e ) {
            return array(
                'success' => false,
                'rows' => 0,
                'message' => $e->getMessage(),
            );
        }
    }

    /**
     * Writes generation rows into the past_five_minutes table.
     *
     * Maps Generation::COLUMNS (source indexes) to Generation::KEYS (table columns).
     *
     * @param array $new_generation Generation rows indexed by time.
     *
     * @return int|false Number of written rows or false on database error.
     */
    private static function write_generation_to_past_five_minutes( array $new_generation ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'national_grid_past_five_minutes';
        $column_to_key_map = array();

        foreach ( Generation::COLUMNS as $column_index ) {
            if ( isset( Generation::KEYS[ $column_index - 1 ] ) ) {
                $column_to_key_map[ $column_index ] = Generation::KEYS[ $column_index - 1 ];
            }
        }

        if ( empty( $column_to_key_map ) ) {
            return 0;
        }

        $columns = array_merge( array( 'time' ), array_values( $column_to_key_map ) );
        $column_sql = '`' . implode( '`, `', array_map( 'esc_sql', $columns ) ) . '`';
        $table_sql = '`' . esc_sql( $table_name ) . '`';

        $single_row_placeholders = '(' . implode( ', ', array_merge( array( '%s' ), array_fill( 0, count( $columns ) - 1, '%f' ) ) ) . ')';
        $rows_placeholders = array();
        $query_values = array();
        $valid_rows_count = 0;

        foreach ( $new_generation as $row ) {
            if ( ! is_array( $row ) || ! isset( $row[0] ) || ! is_string( $row[0] ) ) {
                continue;
            }

            $rows_placeholders[] = $single_row_placeholders;
            $query_values[] = $row[0];

            foreach ( $column_to_key_map as $column_index => $key ) {
                $query_values[] = isset( $row[ $column_index ] ) ? (float) $row[ $column_index ] : 0.0;
            }

            $valid_rows_count++;
        }

        if ( 0 === $valid_rows_count ) {
            return 0;
        }

        $update_parts = array();
        foreach ( array_slice( $columns, 1 ) as $column_name ) {
            $escaped_column = '`' . esc_sql( $column_name ) . '`';
            $update_parts[] = $escaped_column . ' = VALUES(' . $escaped_column . ')';
        }

        $sql = "INSERT INTO {$table_sql} ({$column_sql}) VALUES " . implode( ', ', $rows_placeholders ) . ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $update_parts );
        $prepared_sql = $wpdb->prepare( $sql, $query_values );
        $result = $wpdb->query( $prepared_sql );

        if ( false === $result ) {
            return false;
        }

        return $valid_rows_count;
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

        check_ajax_referer( self::UPDATE_ACTION, 'nonce' );

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
