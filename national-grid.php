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
define( 'NATIONAL_GRID_OPTION_MODULE_TITLE', 'national_grid_module_title' );
define( 'NATIONAL_GRID_OPTION_MODULE_DESCRIPTION', 'national_grid_module_description' );
define( 'NATIONAL_GRID_OPTION_AUTO_UPDATE', 'national_grid_auto_update' );

require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/DataException.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/Time.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/Csv.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/DatabaseStorage.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/Generation.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/Demand.php';

require_once NATIONAL_GRID_PLUGIN_DIR . 'includes/class-national-grid-admin.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'includes/class-national-grid-shortcodes.php';

function national_grid_create_tables() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix = $wpdb->prefix . 'national_grid_';

    $tables = [
        "CREATE TABLE {$prefix}past_five_minutes (\n" .
        "  time datetime NOT NULL,\n" .
        "  coal decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  ccgt decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  ocgt decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  nuclear decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  oil decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  wind decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  hydro decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  pumped decimal(4,2) NOT NULL DEFAULT 0.00,\n" .
        "  biomass decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  battery decimal(4,2) NOT NULL DEFAULT 0.00,\n" .
        "  other decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  ifa decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  moyle decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  britned decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  ewic decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  nemo decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  ifa2 decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  nsl decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  eleclink decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  viking decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  greenlink decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  PRIMARY KEY (time)\n" .
        ") $charset_collate;",

        "CREATE TABLE {$prefix}past_half_hours (\n" .
        "  time datetime NOT NULL,\n" .
        "  embedded_wind decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  embedded_solar decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  coal decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  ccgt decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  ocgt decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  nuclear decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  oil decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  wind decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  hydro decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  pumped decimal(4,2) NOT NULL DEFAULT 0.00,\n" .
        "  biomass decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  battery decimal(4,2) NOT NULL DEFAULT 0.00,\n" .
        "  other decimal(4,2) UNSIGNED NOT NULL DEFAULT 0.00,\n" .
        "  ifa decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  moyle decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  britned decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  ewic decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  nemo decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  ifa2 decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  nsl decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  eleclink decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  viking decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  greenlink decimal(3,2) NOT NULL DEFAULT 0.00,\n" .
        "  PRIMARY KEY (time)\n" .
        ") $charset_collate;",

        "CREATE TABLE {$prefix}logs (\n" .
        "  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  created_at datetime NOT NULL,\n" .
        "  source varchar(16) NOT NULL,\n" .
        "  status varchar(16) NOT NULL,\n" .
        "  message text NOT NULL,\n" .
        "  context longtext NULL,\n" .
        "  PRIMARY KEY (id),\n" .
        "  KEY created_at (created_at),\n" .
        "  KEY status (status),\n" .
        "  KEY source (source)\n" .
        ") $charset_collate;",
    ];

    foreach ($tables as $sql) {
        dbDelta($sql);
    }
}

function national_grid_activate() {
    if ( false === get_option( NATIONAL_GRID_OPTION_TIMEOUT, false ) ) {
        add_option( NATIONAL_GRID_OPTION_TIMEOUT, 5 );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_MODULE_TITLE, false ) ) {
        add_option( NATIONAL_GRID_OPTION_MODULE_TITLE, '' );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_MODULE_DESCRIPTION, false ) ) {
        add_option( NATIONAL_GRID_OPTION_MODULE_DESCRIPTION, '' );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_AUTO_UPDATE, false ) ) {
        add_option( NATIONAL_GRID_OPTION_AUTO_UPDATE, 0 );
    }

    national_grid_create_tables();
}
register_activation_hook( __FILE__, 'national_grid_activate' );

function national_grid_bootstrap() {
    National_Grid_Admin::init();
    National_Grid_Shortcodes::init();
}
add_action( 'plugins_loaded', 'national_grid_bootstrap' );
