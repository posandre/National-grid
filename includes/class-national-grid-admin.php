<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class National_Grid_Admin {
    private const PAGE_SLUG = 'national-grid-settings';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
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

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'National Grid', 'national-grid' ) . '</h1>';
        echo '<form method="post" action="options.php">';

        settings_fields( 'national_grid_settings' );
        do_settings_sections( self::PAGE_SLUG );
        submit_button( __( 'Save data', 'national-grid' ) );

        echo '</form>';
        echo '</div>';
    }
}
