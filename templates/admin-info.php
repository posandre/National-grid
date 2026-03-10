<?php
/**
 * National Grid admin info tab template.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$table_prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
?>
<div class="national-grid-admin-info">
    <h2><?php esc_html_e( 'Plugin information', 'national-grid' ); ?></h2>
    <p><?php esc_html_e( 'This tab documents external APIs, mapped fields, shortcode usage, and the formulas used to build chart values.', 'national-grid' ); ?></p>
    <p><strong><?php esc_html_e( 'Unit note:', 'national-grid' ); ?></strong> <?php esc_html_e( 'GW means gigawatts (1 GW = 1,000 MW).', 'national-grid' ); ?></p>

    <h3><?php esc_html_e( 'Plugin metadata', 'national-grid' ); ?></h3>
    <table class="widefat striped">
        <tbody>
        <tr>
            <th><?php esc_html_e( 'Version', 'national-grid' ); ?></th>
            <td><code><?php echo esc_html( (string) NATIONAL_GRID_VERSION ); ?></code></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Author', 'national-grid' ); ?></th>
            <td><?php esc_html_e( 'Andrii Postoliuk', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Company', 'national-grid' ); ?></th>
            <td><?php esc_html_e( 'Inoxoft', 'national-grid' ); ?></td>
        </tr>
        </tbody>
    </table>

    <h3><?php esc_html_e( 'External APIs', 'national-grid' ); ?></h3>

    <h4><?php esc_html_e( '1) Elexon BMRS FUELINST stream (generation)', 'national-grid' ); ?></h4>
    <p><code>https://data.elexon.co.uk/bmrs/api/v1/datasets/FUELINST/stream?publishDateTimeFrom=...&publishDateTimeTo=...</code></p>
    <table class="widefat striped">
        <thead>
        <tr>
            <th><?php esc_html_e( 'Field', 'national-grid' ); ?></th>
            <th><?php esc_html_e( 'Description', 'national-grid' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><code>startTime</code></td>
            <td><?php esc_html_e( 'UTC timestamp for a 5-minute generation point. Normalized via Time::normalise(..., 5).', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><code>fuelType</code></td>
            <td><?php esc_html_e( 'Fuel/interconnector code (for example CCGT, WIND, NUCLEAR, INTFR) mapped to internal storage column index.', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><code>generation</code></td>
            <td><?php esc_html_e( 'Integer value converted to GW (division by 1000, rounded to 2 decimals).', 'national-grid' ); ?></td>
        </tr>
        </tbody>
    </table>

    <h4><?php esc_html_e( '2) NESO demand data CSV (embedded wind/solar)', 'national-grid' ); ?></h4>
    <p><code>https://api.neso.energy/dataset/7a12172a-939c-404c-b581-a6128b74f588/resource/177f6fa4-ae49-4182-81ea-0c6b35f26ca6/download/demanddataupdate.csv</code></p>
    <table class="widefat striped">
        <thead>
        <tr>
            <th><?php esc_html_e( 'Field', 'national-grid' ); ?></th>
            <th><?php esc_html_e( 'Description', 'national-grid' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><code>SETTLEMENT_DATE</code></td>
            <td><?php esc_html_e( 'Settlement date used with settlement period to calculate half-hour UTC timestamp.', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><code>SETTLEMENT_PERIOD</code></td>
            <td><?php esc_html_e( 'Half-hour period index used by Time::getSettlementTime(...).', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><code>EMBEDDED_WIND_GENERATION</code></td>
            <td><?php esc_html_e( 'Embedded wind generation converted to GW and stored as embedded_wind.', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><code>EMBEDDED_SOLAR_GENERATION</code></td>
            <td><?php esc_html_e( 'Embedded solar generation converted to GW and stored as embedded_solar.', 'national-grid' ); ?></td>
        </tr>
        </tbody>
    </table>

    <h3><?php esc_html_e( 'Database tables created by plugin', 'national-grid' ); ?></h3>

    <h4><code><?php echo esc_html( $table_prefix . 'national_grid_past_five_minutes' ); ?></code></h4>
    <p><?php esc_html_e( 'Stores 5-minute generation points. Primary key: time.', 'national-grid' ); ?></p>
    <table class="widefat striped">
        <thead>
        <tr>
            <th><?php esc_html_e( 'Column', 'national-grid' ); ?></th>
            <th><?php esc_html_e( 'Description', 'national-grid' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <tr><td><code>time</code></td><td><?php esc_html_e( 'UTC datetime for 5-minute point.', 'national-grid' ); ?></td></tr>
        <tr><td><code>coal, ccgt, ocgt, nuclear, oil, wind, hydro, pumped, biomass, battery, other</code></td><td><?php esc_html_e( 'Generation values by source in GW.', 'national-grid' ); ?></td></tr>
        <tr><td><code>ifa, moyle, britned, ewic, nemo, ifa2, nsl, eleclink, viking, greenlink</code></td><td><?php esc_html_e( 'Interconnector flows in GW.', 'national-grid' ); ?></td></tr>
        </tbody>
    </table>

    <h4><code><?php echo esc_html( $table_prefix . 'national_grid_past_half_hours' ); ?></code></h4>
    <p><?php esc_html_e( 'Stores aggregated half-hour rows used for frontend calculations. Primary key: time.', 'national-grid' ); ?></p>
    <table class="widefat striped">
        <thead>
        <tr>
            <th><?php esc_html_e( 'Column', 'national-grid' ); ?></th>
            <th><?php esc_html_e( 'Description', 'national-grid' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <tr><td><code>time</code></td><td><?php esc_html_e( 'UTC datetime for half-hour settlement/aggregation point.', 'national-grid' ); ?></td></tr>
        <tr><td><code>embedded_wind, embedded_solar</code></td><td><?php esc_html_e( 'Demand dataset values in GW (embedded generation).', 'national-grid' ); ?></td></tr>
        <tr><td><code>coal, ccgt, ocgt, nuclear, oil, wind, hydro, pumped, biomass, battery, other</code></td><td><?php esc_html_e( 'Aggregated generation values in GW.', 'national-grid' ); ?></td></tr>
        <tr><td><code>ifa, moyle, britned, ewic, nemo, ifa2, nsl, eleclink, viking, greenlink</code></td><td><?php esc_html_e( 'Interconnector values in GW.', 'national-grid' ); ?></td></tr>
        </tbody>
    </table>

    <h4><code><?php echo esc_html( $table_prefix . 'national_grid_logs' ); ?></code></h4>
    <p><?php esc_html_e( 'Stores operation logs for manual/cron updates and errors.', 'national-grid' ); ?></p>
    <table class="widefat striped">
        <thead>
        <tr>
            <th><?php esc_html_e( 'Column', 'national-grid' ); ?></th>
            <th><?php esc_html_e( 'Description', 'national-grid' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <tr><td><code>id</code></td><td><?php esc_html_e( 'Auto-increment primary key.', 'national-grid' ); ?></td></tr>
        <tr><td><code>created_at</code></td><td><?php esc_html_e( 'UTC datetime when log entry was written.', 'national-grid' ); ?></td></tr>
        <tr><td><code>source</code></td><td><?php esc_html_e( 'Operation source: manual or cron.', 'national-grid' ); ?></td></tr>
        <tr><td><code>status</code></td><td><?php esc_html_e( 'Log status value: success or error.', 'national-grid' ); ?></td></tr>
        <tr><td><code>message</code></td><td><?php esc_html_e( 'Short log message.', 'national-grid' ); ?></td></tr>
        <tr><td><code>context</code></td><td><?php esc_html_e( 'JSON payload with additional details.', 'national-grid' ); ?></td></tr>
        </tbody>
    </table>

    <h3><?php esc_html_e( 'Shortcode', 'national-grid' ); ?></h3>
    <p><code>[show-national-grid]</code></p>
    <table class="widefat striped">
        <thead>
        <tr>
            <th><?php esc_html_e( 'Attribute', 'national-grid' ); ?></th>
            <th><?php esc_html_e( 'Description', 'national-grid' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><code>title</code></td>
            <td><?php esc_html_e( 'Optional custom module title. Empty string hides title section.', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><code>description</code></td>
            <td><?php esc_html_e( 'Optional custom module description. Empty string hides description section.', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><code>additional_class</code></td>
            <td><?php esc_html_e( 'Optional extra CSS class(es) added to the root widget container.', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><code>hide_title</code></td>
            <td><?php esc_html_e( 'Set to 1/true/yes/on to hide the entire header block (.national-grid-frontend-header).', 'national-grid' ); ?></td>
        </tr>
        </tbody>
    </table>

    <h4><?php esc_html_e( 'Usage examples', 'national-grid' ); ?></h4>
    <pre>[show-national-grid]</pre>
    <pre>[show-national-grid title="National Grid - Live" description="National grid: Today-Generation Mix and Type"]</pre>
    <pre>[show-national-grid title="" description="" additional_class="my-grid my-grid--compact"]</pre>
    <pre>[show-national-grid hide_title="1"]</pre>

    <h3><?php esc_html_e( 'Chart calculation logic', 'national-grid' ); ?></h3>
    <h4><?php esc_html_e( 'Pie chart source mapping', 'national-grid' ); ?></h4>
    <table class="widefat striped">
        <thead>
        <tr>
            <th><?php esc_html_e( 'Pie label', 'national-grid' ); ?></th>
            <th><?php esc_html_e( 'Formula', 'national-grid' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <tr><td>Gas</td><td><code>ccgt + ocgt</code></td></tr>
        <tr><td>Wind</td><td><code>wind + embedded_wind</code></td></tr>
        <tr><td>Solar</td><td><code>embedded_solar</code></td></tr>
        <tr><td>Hydroelectric</td><td><code>hydro</code></td></tr>
        <tr><td>Nuclear</td><td><code>nuclear</code></td></tr>
        <tr><td>Biomass</td><td><code>biomass</code></td></tr>
        <tr><td>Interconnectors</td><td><code>ifa + moyle + britned + ewic + nemo + ifa2 + nsl + eleclink + viking + greenlink</code>, min 0 clamp</td></tr>
        <tr><td>Storage</td><td><code>pumped + battery</code> (negative <code>pumped</code> is clamped to 0 before sum)</td></tr>
        </tbody>
    </table>

    <h4><?php esc_html_e( 'Clean power percentage', 'national-grid' ); ?></h4>
    <p><code>Live Percentage Clean Power = (Wind + Solar + Hydroelectric + Biomass + Nuclear) / Total * 100</code></p>
    <p><?php esc_html_e( 'Total is the sum of all displayed pie components (non-negative values).', 'national-grid' ); ?></p>

    <h4><?php esc_html_e( 'Bar chart grouping', 'national-grid' ); ?></h4>
    <table class="widefat striped">
        <thead>
        <tr>
            <th><?php esc_html_e( 'Group', 'national-grid' ); ?></th>
            <th><?php esc_html_e( 'Components', 'national-grid' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <tr><td>Renewable</td><td><code>Wind + Solar + Hydroelectric</code></td></tr>
        <tr><td>Other Low Carbon</td><td><code>Biomass + Nuclear</code></td></tr>
        <tr><td>Fossil Fuels</td><td><code>Gas</code></td></tr>
        <tr><td>Other</td><td><code>Interconnectors + Storage</code></td></tr>
        </tbody>
    </table>

    <h4><?php esc_html_e( 'Label rendering rules', 'national-grid' ); ?></h4>
    <ul>
        <li><?php esc_html_e( 'Pie segment labels are shown only for slices >= 4.6% of pie total.', 'national-grid' ); ?></li>
        <li><?php esc_html_e( 'Bar segment inline labels are shown only when segment height is >= 18px (desktop) or >= 14px (mobile).', 'national-grid' ); ?></li>
        <li><?php esc_html_e( 'Tooltip still shows exact values for all segments, including small ones.', 'national-grid' ); ?></li>
    </ul>

    <h3><?php esc_html_e( 'Automatic updates (WP-Cron)', 'national-grid' ); ?></h3>
    <p><?php esc_html_e( 'The plugin supports automatic data refresh via WordPress Cron.', 'national-grid' ); ?></p>
    <p><?php esc_html_e( 'The plugin also has a separate cron task for automatic log cleanup. This cleanup event is scheduled only when "Automatic log cleanup" is enabled in settings (disabled by default).', 'national-grid' ); ?></p>
    <table class="widefat striped">
        <thead>
        <tr>
            <th><?php esc_html_e( 'Item', 'national-grid' ); ?></th>
            <th><?php esc_html_e( 'Description', 'national-grid' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><?php esc_html_e( 'Enable switch', 'national-grid' ); ?></td>
            <td><?php esc_html_e( 'Option: "Automatic cron update". When enabled, plugin schedules periodic updates; when disabled, scheduled events are unscheduled.', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Interval', 'national-grid' ); ?></td>
            <td><?php esc_html_e( 'Built from "National grid timeout" (minutes) via custom schedule key national_grid_custom_interval.', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Cron hook', 'national-grid' ); ?></td>
            <td><code>national_grid_cron_update_data</code></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Execution flow', 'national-grid' ); ?></td>
            <td><?php esc_html_e( 'Cron hook runs update_data("cron"), which updates generation and demand datasets and writes results to the log.', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Log cleanup cron hook', 'national-grid' ); ?></td>
            <td><code>national_grid_cron_clear_log</code></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Log cleanup schedule', 'national-grid' ); ?></td>
            <td><?php esc_html_e( 'Uses custom interval schedule key national_grid_log_clear_interval. Interval is configured in hours via "Log cleanup interval".', 'national-grid' ); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Log cleanup activation rule', 'national-grid' ); ?></td>
            <td><?php esc_html_e( 'Scheduled only when "Automatic log cleanup" is enabled. If disabled, existing scheduled cleanup events are unscheduled.', 'national-grid' ); ?></td>
        </tr>
        </tbody>
    </table>
</div>
