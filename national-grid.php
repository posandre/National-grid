<?php
/**
 * Plugin Name: National Grid
 * Description: National Grid plugin with options page and shortcode.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: national-grid
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NATIONAL_GRID_VERSION', '1.0.0' );
define( 'NATIONAL_GRID_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NATIONAL_GRID_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'NATIONAL_GRID_OPTION_TIMEOUT', 'national_grid_timeout' );

require_once NATIONAL_GRID_PLUGIN_DIR . 'includes/class-national-grid-admin.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'includes/class-national-grid-shortcodes.php';

function national_grid_bootstrap() {
    National_Grid_Admin::init();
    National_Grid_Shortcodes::init();
}
add_action( 'plugins_loaded', 'national_grid_bootstrap' );
