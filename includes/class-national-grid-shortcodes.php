<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class National_Grid_Shortcodes {
    public static function init() {
        add_shortcode( 'show-national-grid', array( __CLASS__, 'render_shortcode' ) );
    }

    public static function render_shortcode( $atts = array() ) {
        $timeout = (int) get_option( NATIONAL_GRID_OPTION_TIMEOUT, 30 );

        $content  = '<div class="national-grid">';
        $content .= '<p>' . esc_html__( 'National grid data placeholder.', 'national-grid' ) . '</p>';
        $content .= '<p>' . sprintf( esc_html__( 'Timeout: %d seconds', 'national-grid' ), $timeout ) . '</p>';
        $content .= '</div>';

        return $content;
    }
}
