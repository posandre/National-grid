<?php
/**
 * Plugin Name: National Grid
 * Description: National Grid plugin with options page and shortcode.
 * Version: 1.0.0
 * Author: Andrii Postoliuk (Inoxoft)
 * Text Domain: national-grid
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin release version used for cache-busting assets.
define( 'NATIONAL_GRID_VERSION', '1.0.0' );
// Absolute path to this plugin directory.
define( 'NATIONAL_GRID_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
// Public URL to this plugin directory.
define( 'NATIONAL_GRID_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Option name for update interval (minutes).
define( 'NATIONAL_GRID_OPTION_TIMEOUT', 'national_grid_timeout' );
// Option name for frontend module title override.
define( 'NATIONAL_GRID_OPTION_MODULE_TITLE', 'national_grid_module_title' );
// Option name for frontend module description override.
define( 'NATIONAL_GRID_OPTION_MODULE_DESCRIPTION', 'national_grid_module_description' );
// Option name for automatic cron updates toggle.
define( 'NATIONAL_GRID_OPTION_AUTO_UPDATE', 'national_grid_auto_update' );
// Option name for automatic plugin log cleanup toggle.
define( 'NATIONAL_GRID_OPTION_AUTO_CLEAR_LOG', 'national_grid_auto_clear_log' );
// Option name for automatic plugin log cleanup interval (in hours).
define( 'NATIONAL_GRID_OPTION_LOG_CLEAR_INTERVAL_HOURS', 'national_grid_log_clear_interval_hours' );
// Option name for enabling/disabling plugin event logs table writes.
define( 'NATIONAL_GRID_OPTION_ENABLE_LOG', 'national_grid_enable_log' );
// Option name for enabling/disabling frontend chart animations.
define( 'NATIONAL_GRID_OPTION_CHART_ANIMATION', 'national_grid_chart_animation' );
// Option name for debug mode toggle.
define( 'NATIONAL_GRID_OPTION_DEBUG_MODE', 'national_grid_debug_mode' );
// Option name for UTC timestamp when the latest update run started.
define( 'NATIONAL_GRID_OPTION_LAST_UPDATE_STARTED_AT', 'national_grid_last_update_started_at' );
// Option name for UTC timestamp when the latest successful update run finished.
define( 'NATIONAL_GRID_OPTION_LAST_UPDATE_FINISHED_AT', 'national_grid_last_update_finished_at' );
// Option name for UTC timestamp used as recurring cron synchronization anchor.
define( 'NATIONAL_GRID_OPTION_CRON_ANCHOR_UTC', 'national_grid_cron_anchor_utc' );

require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/DataException.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/Time.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/Csv.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/DatabaseStorage.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/Generation.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'helpers/Demand.php';

require_once NATIONAL_GRID_PLUGIN_DIR . 'includes/class-national-grid-admin.php';
require_once NATIONAL_GRID_PLUGIN_DIR . 'includes/class-national-grid-frontend.php';

/**
 * Creates plugin database tables on activation.
 *
 * @return void
 */
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

/**
 * Initializes default options and creates storage tables.
 *
 * @return void
 */
function national_grid_activate() {
    if ( false === get_option( NATIONAL_GRID_OPTION_TIMEOUT, false ) ) {
        add_option( NATIONAL_GRID_OPTION_TIMEOUT, 5 );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_MODULE_TITLE, false ) ) {
        add_option( NATIONAL_GRID_OPTION_MODULE_TITLE, 'National Grid - Live' );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_MODULE_DESCRIPTION, false ) ) {
        add_option( NATIONAL_GRID_OPTION_MODULE_DESCRIPTION, '' );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_AUTO_UPDATE, false ) ) {
        add_option( NATIONAL_GRID_OPTION_AUTO_UPDATE, 0 );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_AUTO_CLEAR_LOG, false ) ) {
        add_option( NATIONAL_GRID_OPTION_AUTO_CLEAR_LOG, 0 );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_LOG_CLEAR_INTERVAL_HOURS, false ) ) {
        add_option( NATIONAL_GRID_OPTION_LOG_CLEAR_INTERVAL_HOURS, 336 );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_ENABLE_LOG, false ) ) {
        add_option( NATIONAL_GRID_OPTION_ENABLE_LOG, 1 );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_CHART_ANIMATION, false ) ) {
        add_option( NATIONAL_GRID_OPTION_CHART_ANIMATION, 1 );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_DEBUG_MODE, false ) ) {
        add_option( NATIONAL_GRID_OPTION_DEBUG_MODE, 0 );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_LAST_UPDATE_STARTED_AT, false ) ) {
        add_option( NATIONAL_GRID_OPTION_LAST_UPDATE_STARTED_AT, '' );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_LAST_UPDATE_FINISHED_AT, false ) ) {
        add_option( NATIONAL_GRID_OPTION_LAST_UPDATE_FINISHED_AT, '' );
    }

    if ( false === get_option( NATIONAL_GRID_OPTION_CRON_ANCHOR_UTC, false ) ) {
        add_option( NATIONAL_GRID_OPTION_CRON_ANCHOR_UTC, '' );
    }

    national_grid_create_tables();
    National_Grid_Admin::schedule_initial_update_event();
}
register_activation_hook( __FILE__, 'national_grid_activate' );

/**
 * Boots plugin modules after WordPress is loaded.
 *
 * @return void
 */
function national_grid_bootstrap() {
    load_plugin_textdomain( 'national-grid', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    National_Grid_Admin::init();
    National_Grid_Frontend::init();
}
add_action( 'plugins_loaded', 'national_grid_bootstrap' );

/**
 * Registers plugin-provided page template for WordPress Pages.
 *
 * @param array<string, string> $templates Existing page templates.
 * @param WP_Theme|null         $theme     Active theme instance.
 * @param WP_Post|null          $post      Current post when editing.
 * @param string|null           $post_type Current post type.
 * @return array<string, string>
 */
function national_grid_add_page_template( array $templates, $theme = null, $post = null, $post_type = null ) {
    if ( null !== $post_type && 'page' !== $post_type ) {
        return $templates;
    }

    $templates['national-grid-page-template.php'] = __( 'National Grid Full Page', 'national-grid' );
    return $templates;
}
add_filter( 'theme_page_templates', 'national_grid_add_page_template', 10, 4 );

/**
 * Loads plugin page template when selected on a Page.
 *
 * @param string $template Resolved template path.
 * @return string
 */
function national_grid_include_page_template( $template ) {
    if ( ! is_singular( 'page' ) ) {
        return $template;
    }

    $post = get_queried_object();
    if ( ! $post instanceof WP_Post ) {
        return $template;
    }

    $selected_template = (string) get_post_meta( $post->ID, '_wp_page_template', true );
    if ( 'national-grid-page-template.php' !== $selected_template ) {
        return $template;
    }

    $plugin_template = NATIONAL_GRID_PLUGIN_DIR . 'templates/page-template-national-grid.php';
    if ( is_readable( $plugin_template ) ) {
        return $plugin_template;
    }

    return $template;
}
add_filter( 'template_include', 'national_grid_include_page_template' );

/**
 * Adds template-specific class to <body> when plugin page template is active.
 *
 * @param array<int, string> $classes Existing body classes.
 * @return array<int, string>
 */
function national_grid_add_template_body_class( array $classes ) {
    if ( ! is_singular( 'page' ) ) {
        return $classes;
    }

    $post = get_queried_object();
    if ( ! $post instanceof WP_Post ) {
        return $classes;
    }

    $selected_template = (string) get_post_meta( $post->ID, '_wp_page_template', true );
    if ( 'national-grid-page-template.php' !== $selected_template ) {
        return $classes;
    }

    $classes[] = 'page-template-template-toggle-builder';
    $classes[] = 'national-grid-page-template';
    return $classes;
}
add_filter( 'body_class', 'national_grid_add_template_body_class' );

/**
 * Unschedules plugin cron events on deactivation.
 *
 * @return void
 */
function national_grid_deactivate() {
    National_Grid_Admin::clear_scheduled_events();
}
register_deactivation_hook( __FILE__, 'national_grid_deactivate' );

/**
 * Removes plugin data from database and filesystem on uninstall.
 *
 * @return void
 */
function national_grid_uninstall() {
    if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        return;
    }

    global $wpdb;

    National_Grid_Admin::clear_scheduled_events();

    $options = [
        NATIONAL_GRID_OPTION_TIMEOUT,
        NATIONAL_GRID_OPTION_MODULE_TITLE,
        NATIONAL_GRID_OPTION_MODULE_DESCRIPTION,
        NATIONAL_GRID_OPTION_AUTO_UPDATE,
        NATIONAL_GRID_OPTION_AUTO_CLEAR_LOG,
        NATIONAL_GRID_OPTION_LOG_CLEAR_INTERVAL_HOURS,
        NATIONAL_GRID_OPTION_ENABLE_LOG,
        NATIONAL_GRID_OPTION_CHART_ANIMATION,
        NATIONAL_GRID_OPTION_DEBUG_MODE,
        NATIONAL_GRID_OPTION_LAST_UPDATE_STARTED_AT,
        NATIONAL_GRID_OPTION_LAST_UPDATE_FINISHED_AT,
        NATIONAL_GRID_OPTION_CRON_ANCHOR_UTC,
    ];
    foreach ( $options as $option_name ) {
        delete_option( $option_name );
    }

    DatabaseStorage::invalidateFrontendChartCache();

    $tables = [
        $wpdb->prefix . 'national_grid_past_five_minutes',
        $wpdb->prefix . 'national_grid_past_half_hours',
        $wpdb->prefix . 'national_grid_logs',
    ];
    foreach ( $tables as $table_name ) {
        $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table_name ) . '`' );
    }

    $debug_path = DatabaseStorage::getDebugLogPathForAdmin();
    if ( is_string( $debug_path ) && '' !== $debug_path && file_exists( $debug_path ) ) {
        wp_delete_file( $debug_path );
    }
}
register_uninstall_hook( __FILE__, 'national_grid_uninstall' );

/**
 * Adds quick "Info" link on the Plugins page for this plugin.
 *
 * @param array<int, string> $links Existing action links.
 * @return array<int, string>
 */
function national_grid_plugin_action_links( $links ) {
    $base_url = admin_url( 'options-general.php?page=national-grid-settings' );
    $is_log_enabled = 1 === (int) get_option( NATIONAL_GRID_OPTION_ENABLE_LOG, 1 );
    $settings_link = '<a href="' . esc_url( add_query_arg( 'tab', 'settings', $base_url ) ) . '">' . esc_html__( 'Settings', 'national-grid' ) . '</a>';
    $update_link = '<a href="' . esc_url( add_query_arg( 'tab', 'update', $base_url ) ) . '">' . esc_html__( 'Update data', 'national-grid' ) . '</a>';
    $info_link = '<a href="' . esc_url( add_query_arg( 'tab', 'info', $base_url ) ) . '">' . esc_html__( 'Info', 'national-grid' ) . '</a>';

    $links[] = $settings_link;
    $links[] = $update_link;
    if ( $is_log_enabled ) {
        $log_link = '<a href="' . esc_url( add_query_arg( 'tab', 'logs', $base_url ) ) . '">' . esc_html__( 'Log', 'national-grid' ) . '</a>';
        $links[] = $log_link;
    }
    $links[] = $info_link;

    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'national_grid_plugin_action_links' );
