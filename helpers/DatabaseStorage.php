<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DatabaseStorage {
    /** Log status value for successful events. */
    private const STATUS_SUCCESS = 'success';
    /** Log status value for failed events. */
    private const STATUS_ERROR = 'error';
    /** Debug log filename in uploads directory. */
    private const DEBUG_LOG_FILENAME = 'national-grid-debug.log';
    /** Maximum debug log file size in bytes (1 MB). */
    private const DEBUG_LOG_MAX_BYTES = 1048576;
    /** Transient key used for short-lived frontend chart payload cache. */
    private const FRONTEND_CHART_TRANSIENT_KEY = 'national_grid_frontend_chart_data';
    /** Frontend chart payload cache lifetime in seconds. */
    private const FRONTEND_CHART_TRANSIENT_TTL = 60;
    /** Maps frontend pie labels to storage columns used for aggregation. */
    private const FRONTEND_PIE_MAPPING = [
        'Gas' => [
            'ccgt',
            'ocgt'
        ],
        'Wind' => [
            'wind',
            'embedded_wind'
        ],
        'Solar' => [ 'embedded_solar' ],
        'Hydroelectric' => [ 'hydro' ],
        'Nuclear' => [ 'nuclear' ],
        'Biomass' => [ 'biomass' ],
        'Interconnectors' => [
            'ifa',
            'moyle',
            'britned',
            'ewic',
            'nemo',
            'ifa2',
            'nsl',
            'eleclink',
            'viking',
            'greenlink'
        ],
        'Storage' => [
            'pumped',
            'battery'
        ],
    ];

    /**
     * Returns fully-qualified logs table name.
     *
     * @return string
     */
    private static function getLogsTableName() {
        global $wpdb;

        return $wpdb->prefix . 'national_grid_logs';
    }

    /**
     * Stores a success event in logs.
     *
     * @param string $source Log source label.
     * @param string $message Log message.
     * @param array<string, mixed> $context Additional context payload.
     * @return bool
     */
    public static function logSuccess( $source, $message, array $context = [] ) {
        return self::logEvent( $source, self::STATUS_SUCCESS, $message, $context );
    }

    /**
     * Stores an error event in logs.
     *
     * @param string $source Log source label.
     * @param string $message Log message.
     * @param array<string, mixed> $context Additional context payload.
     * @return bool
     */
    public static function logError( $source, $message, array $context = [] ) {
        return self::logEvent( $source, self::STATUS_ERROR, $message, $context );
    }

    /**
     * Stores a log event row with source, status and context.
     *
     * @param string $source Log source label.
     * @param string $status Event status value.
     * @param string $message Log message.
     * @param array<string, mixed> $context Additional context payload.
     * @return bool
     */
    public static function logEvent( $source, $status, $message, array $context = [] ) {
        if ( ! self::isLogEnabled() ) {
            return true;
        }

        global $wpdb;

        $table_name = self::getLogsTableName();
        $result = $wpdb->insert(
            $table_name,
            [
                'created_at' => gmdate( 'Y-m-d H:i:s' ),
                'source' => sanitize_key( (string) $source ),
                'status' => sanitize_key( (string) $status ),
                'message' => sanitize_text_field( (string) $message ),
                'context' => wp_json_encode( $context ),
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        return false !== $result;
    }

    /**
     * Returns true when plugin event log storage is enabled.
     *
     * @return bool
     */
    public static function isLogEnabled(): bool {
        return 1 === (int) get_option( NATIONAL_GRID_OPTION_ENABLE_LOG, 1 );
    }

    /**
     * Returns recent log rows sorted by newest first.
     *
     * @param int $limit Max rows to return.
     * @param int $offset Rows to skip from the top.
     * @return array<int, array<string, mixed>>
     */
    public static function getRecentLogs( $limit = 200, $offset = 0 ) {
        global $wpdb;

        $limit = max( 1, (int) $limit );
        $offset = max( 0, (int) $offset );
        $table_name = self::getLogsTableName();
        $sql = $wpdb->prepare(
            'SELECT `id`, `created_at`, `source`, `status`, `message`, `context` FROM `' . esc_sql( $table_name ) . '` ORDER BY `id` DESC LIMIT %d OFFSET %d',
            $limit,
            $offset
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Returns total number of log rows.
     *
     * @return int
     */
    public static function getLogsCount(): int {
        global $wpdb;

        $table_name = self::getLogsTableName();
        $count = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table_name ) . '`' );

        return is_numeric( $count ) ? (int) $count : 0;
    }

    /**
     * Removes all rows from logs table.
     *
     * @return int|false
     */
    public static function clearLogs() {
        global $wpdb;

        $table_name = self::getLogsTableName();
        return $wpdb->query( 'DELETE FROM `' . esc_sql( $table_name ) . '`' );
    }

    /**
     * Returns true when debug mode is enabled in plugin settings.
     *
     * @return bool
     */
    public static function isDebugModeEnabled(): bool {
        return 1 === (int) get_option( NATIONAL_GRID_OPTION_DEBUG_MODE, 0 );
    }

    /**
     * Appends formatted debug entry to plugin debug log file.
     *
     * @param string $title Debug block title.
     * @param array<int, string> $lines Debug lines.
     * @return void
     */
    public static function appendDebugLog( string $title, array $lines ): void {
        if ( ! self::isDebugModeEnabled() ) {
            return;
        }

        $path = self::getDebugLogPath();
        $timestamp = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
        $safe_lines = array_map(
            static function ( $line ) {
                return (string) $line;
            },
            $lines
        );
        $safe_lines = self::normalizeDebugLines( $safe_lines );

        $content = "\n=== {$timestamp} | {$title} ===\n" . implode( "\n", $safe_lines ) . "\n";
        error_log( $content, 3, $path );
        self::enforceDebugLogLimits( $path );
    }

    /**
     * Enforces debug log retention limits.
     *
     * Keeps only the latest debug entries and caps total file size.
     *
     * @param string $path Debug log file path.
     * @return void
     */
    private static function enforceDebugLogLimits( string $path ): void {
        if ( ! file_exists( $path ) || ! is_readable( $path ) || ! is_writable( $path ) ) {
            return;
        }

        $content = (string) file_get_contents( $path );
        if ( '' === $content ) {
            return;
        }

        $content = ltrim( $content, "\n" );
        $entries = preg_split( '/(?=^=== .* ===$)/m', $content, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $entries ) || [] === $entries ) {
            return;
        }

        $entries = array_map(
            static function ( $entry ) {
                return rtrim( (string) $entry ) . "\n";
            },
            $entries
        );

        $entries = array_values( $entries );

        while ( count( $entries ) > 1 && strlen( implode( "\n", $entries ) ) > self::DEBUG_LOG_MAX_BYTES ) {
            array_shift( $entries );
        }

        $single_entry = $entries[0];
        if ( 1 === count( $entries ) && strlen( $single_entry ) > self::DEBUG_LOG_MAX_BYTES ) {
            $header_end = strpos( $single_entry, "\n" );
            if ( false === $header_end ) {
                $entries[0] = substr( $single_entry, -1 * self::DEBUG_LOG_MAX_BYTES );
            } else {
                $header = substr( $single_entry, 0, $header_end + 1 );
                $body = substr( $single_entry, $header_end + 1 );
                $truncation_marker = "[truncated]\n";
                $allowed_body_length = self::DEBUG_LOG_MAX_BYTES - strlen( $header ) - strlen( $truncation_marker );

                if ( $allowed_body_length <= 0 ) {
                    $entries[0] = substr( $single_entry, -1 * self::DEBUG_LOG_MAX_BYTES );
                } else {
                    $entries[0] = $header . $truncation_marker . substr( $body, -1 * $allowed_body_length );
                }
            }
        }

        $final_content = implode( "\n", $entries );
        file_put_contents( $path, ltrim( $final_content, "\n" ) );
    }

    /**
     * Returns filesystem path for plugin debug log file.
     *
     * @return string
     */
    private static function getDebugLogPath(): string {
        $upload = wp_upload_dir();
        if ( isset( $upload['basedir'] ) && is_string( $upload['basedir'] ) && '' !== $upload['basedir'] ) {
            return trailingslashit( $upload['basedir'] ) . self::DEBUG_LOG_FILENAME;
        }

        return trailingslashit( NATIONAL_GRID_PLUGIN_DIR ) . self::DEBUG_LOG_FILENAME;
    }

    /**
     * Returns filesystem path for plugin debug log file (for admin UI).
     *
     * @return string
     */
    public static function getDebugLogPathForAdmin(): string {
        return self::getDebugLogPath();
    }

    /**
     * Clears plugin debug log file.
     *
     * @return bool
     */
    public static function clearDebugLogFile(): bool {
        $path = self::getDebugLogPath();
        if ( ! file_exists( $path ) ) {
            return true;
        }

        return false !== file_put_contents( $path, '' );
    }

    /**
     * Formats debug lines to improve readability in file output.
     *
     * @param array<int, string> $lines Raw lines.
     * @return array<int, string>
     */
    private static function normalizeDebugLines( array $lines ): array {
        $flattened = [];
        foreach ( $lines as $line ) {
            $chunks = explode( "\n", (string) $line );
            foreach ( $chunks as $chunk ) {
                $flattened[] = rtrim( (string) $chunk );
            }
        }

        $normalized = [];
        foreach ( $flattened as $chunk ) {
            $is_empty = '' === trim( $chunk );
            $is_section_heading = (bool) preg_match( '/:\s*$/', $chunk );

            if ( $is_empty ) {
                if ( [] === $normalized || '' === end( $normalized ) ) {
                    continue;
                }
                $normalized[] = '';
                continue;
            }

            if ( $is_section_heading && [] !== $normalized && '' !== end( $normalized ) ) {
                $normalized[] = '';
            }

            $normalized[] = $chunk;
        }

        while ( [] !== $normalized && '' === end( $normalized ) ) {
            array_pop( $normalized );
        }

        return $normalized;
    }

    /**
     * Returns true when both generation tables use InnoDB and transactions are safe.
     *
     * @return bool
     */
    public static function canUseGenerationTransactions(): bool {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'national_grid_past_five_minutes',
            $wpdb->prefix . 'national_grid_past_half_hours',
        ];

        foreach ( $tables as $table_name ) {
            $sql = $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table_name );
            $status = $wpdb->get_row( $sql, ARRAY_A );

            if ( ! is_array( $status ) || empty( $status['Engine'] ) ) {
                return false;
            }

            if ( 'InnoDB' !== (string) $status['Engine'] ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Updates generation storage tables from five-minute input rows.
     *
     * @param array<int|string, mixed> $data Raw generation rows indexed by time.
     * @return array<string, int>|false
     * @throws DataException
     */
    public static function updateGeneration($data) {
        global $wpdb;

        $use_transaction = self::canUseGenerationTransactions();
        if ( $use_transaction ) {
            $wpdb->query( 'START TRANSACTION' );
        }

        try {
            $rows_written = self::updateGenerationData( $data );
            if ( false === $rows_written ) {
                if ( $use_transaction ) {
                    $wpdb->query( 'ROLLBACK' );
                }
                return false;
            }

            $aggregated = self::aggregateGeneration();
            if ( false === $aggregated ) {
                if ( $use_transaction ) {
                    $wpdb->query( 'ROLLBACK' );
                }
                return false;
            }

            $deleted = self::deleteOldGeneration();
            if ( false === $deleted ) {
                if ( $use_transaction ) {
                    $wpdb->query( 'ROLLBACK' );
                }
                return false;
            }

            if ( $use_transaction ) {
                $wpdb->query( 'COMMIT' );
            }
        } catch (Throwable $e) {
            if ( $use_transaction ) {
                $wpdb->query( 'ROLLBACK' );
            }
            throw new DataException( 'Failed to update generation data in storage.', 0, $e );
        }

        self::invalidateFrontendChartCache();

        return [
            'rows_written' =>  $rows_written,
            'rows_aggregated' =>  $aggregated,
            'rows_deleted' =>  $deleted,
        ];
    }

    /**
     * Writes generation rows into the past_five_minutes table.
     *
     * Maps Generation::COLUMNS (source indexes) to Generation::KEYS (table columns).
     *
     * @param array $data Generation rows indexed by time.
     *
     * @return int|false Number of written rows or false on database error.
     */
    private static function updateGenerationData( array $data ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'national_grid_past_five_minutes';
        $column_to_key_map = [];

        foreach ( Generation::COLUMNS as $column_index ) {
            if ( isset( Generation::KEYS[ $column_index - 1 ] ) ) {
                $column_to_key_map[ $column_index ] = Generation::KEYS[ $column_index - 1 ];
            }
        }

        if ( empty( $column_to_key_map ) ) {
            return 0;
        }

        $columns = array_merge( [ 'time' ], array_values( $column_to_key_map ) );
        $column_sql = '`' . implode( '`, `', array_map( 'esc_sql', $columns ) ) . '`';
        $table_sql = '`' . esc_sql( $table_name ) . '`';

        $single_row_placeholders = '(' . implode( ', ', array_merge( [ '%s' ], array_fill( 0, count( $columns ) - 1, '%f' ) ) ) . ')';
        $rows_placeholders = [];
        $query_values = [];
        $valid_rows_count = 0;

        foreach ( $data as $row ) {
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

        $sql = "INSERT INTO {$table_sql} ({$column_sql}) VALUES "
            . implode( ', ', $rows_placeholders )
            . self::getOnDuplicateKeyUpdateClause( array_slice( $columns, 1 ) );
        $prepared_sql = $wpdb->prepare( $sql, $query_values );
        $result = $wpdb->query( $prepared_sql );

        if ( false === $result ) {
            return false;
        }

        return $valid_rows_count;
    }

    /**
     * Deletes generation rows older than 24 hours.
     *
     * @return int|false Number of deleted rows or false on database error.
     */
    private static function deleteOldGeneration() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'national_grid_past_five_minutes';
        $cutoff_time = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

        $sql = $wpdb->prepare(
            'DELETE FROM `' . esc_sql( $table_name ) . '` WHERE `time` < %s',
            $cutoff_time
        );

        return $wpdb->query( $sql );
    }

    /**
     * Updates half-hour demand storage table from parsed demand rows.
     *
     * @param array<int, array<int, mixed>> $data Parsed demand rows.
     * @return array<string, int>|false
     * @throws DataException
     */
    public static function updateDemand(array $data) {
        global $wpdb;

        $use_transaction = self::canUseGenerationTransactions();
        if ( $use_transaction ) {
            $wpdb->query( 'START TRANSACTION' );
        }

        try {
            $rows_written = self::updateDemandData( $data );
            if ( false === $rows_written ) {
                if ( $use_transaction ) {
                    $wpdb->query( 'ROLLBACK' );
                }
                return false;
            }

            $deleted = self::deleteOldHalfHours();
            if ( false === $deleted ) {
                if ( $use_transaction ) {
                    $wpdb->query( 'ROLLBACK' );
                }
                return false;
            }

            if ( $use_transaction ) {
                $wpdb->query( 'COMMIT' );
            }
        } catch (Throwable $e) {
            if ( $use_transaction ) {
                $wpdb->query( 'ROLLBACK' );
            }
            throw new DataException( 'Failed to update demand data in storage.', 0, $e );
        }

        self::invalidateFrontendChartCache();

        return [
            'rows_written' =>  $rows_written,
            'rows_deleted' =>  $deleted,
        ];
    }

    /**
     * Updates data, ignoring data prior to the earliest half-hour or past the
     * latest half-hour.
     *
     * @param array $data    The data
     * @return int|false
     */
    private static function updateDemandData(array $data) {
        $earliest = self::getEarliestHalfHour();
        $latest = self::getLatestHalfHour();

        $filtered_data = array_values(
            array_filter(
                $data,
                static function ( $datum ) use ( $earliest, $latest ) {
                    if ( ! is_array( $datum ) || ! isset( $datum[0] ) ) {
                        return false;
                    }

                    $time = (string) $datum[0];

                    return $time >= $earliest && $time <= $latest;
                }
            )
        );

        $written = self::updatePastTimeSeries( 'past_half_hours', Demand::KEYS, $filtered_data );
        if ( false === $written ) {
            return false;
        }

        return $written;
    }

    /**
     * Updates a past time series.
     *
     * @param string $table   The table
     * @param array  $columns The columns to update
     * @param array  $data    The data
     * @return int|false
     */
    private static function updatePastTimeSeries(
        string $table,
        array $columns,
        array $data
    ) {
        global $wpdb;

        if ( 0 === count( $data ) || 0 === count( $columns ) ) {
            return 0;
        }

        $safe_columns = array_values(
            array_filter(
                array_map(
                    static function ( $column ) {
                        return preg_replace( '/[^a-z0-9_]/i', '', (string) $column );
                    },
                    $columns
                )
            )
        );

        if ( 0 === count( $safe_columns ) ) {
            return 0;
        }

        $table_name = $wpdb->prefix . 'national_grid_' . $table;
        $column_sql = '`time`, `' . implode( '`, `', $safe_columns ) . '`';
        $single_row_placeholders = '(' . implode( ', ', array_merge( [ '%s' ], array_fill( 0, count( $safe_columns ), '%f' ) ) ) . ')';
        $rows_placeholders = [];
        $query_values = [];

        foreach ( $data as $datum ) {
            if ( ! is_array( $datum ) || count( $datum ) < count( $safe_columns ) + 1 || ! isset( $datum[0] ) ) {
                continue;
            }

            $time = (string) $datum[0];
            $rows_placeholders[] = $single_row_placeholders;
            $query_values[] = $time;

            for ( $i = 0; $i < count( $safe_columns ); $i++ ) {
                $query_values[] = isset( $datum[ $i + 1 ] ) ? (float) $datum[ $i + 1 ] : 0.0;
            }
        }

        if ( 0 === count( $rows_placeholders ) ) {
            return 0;
        }

        $sql = 'INSERT INTO `'
            . esc_sql( $table_name )
            . '` ('
            . $column_sql
            . ') VALUES '
            . implode( ', ', $rows_placeholders )
            . self::getOnDuplicateKeyUpdateClause( $safe_columns );

        $prepared_sql = $wpdb->prepare( $sql, $query_values );
        $result = $wpdb->query( $prepared_sql );

        if ( false === $result ) {
            return false;
        }

        return count( $rows_placeholders );
    }

    /**
     * Returns an ON DUPLICATE KEY UPDATE clause.
     *
     * @param array $columns The columns
     * @return string
     */
    private static function getOnDuplicateKeyUpdateClause( array $columns ): string {
        $parts = array_map(
            static function ( $column ) {
                $safe_column = preg_replace( '/[^a-z0-9_]/i', '', (string) $column );

                return '`' . $safe_column . '` = VALUES(`' . $safe_column . '`)';
            },
            $columns
        );

        return ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $parts );
    }

    /**
     * Returns the latest half-hour, as a YYYY-MM-DD HH:MM:SS string.
     *
     * @return string
     */
    private static function getLatestHalfHour(): string {
        global $wpdb;

        $table_name = $wpdb->prefix . 'national_grid_past_half_hours';
        $sql = 'SELECT MAX(`time`) FROM `' . esc_sql( $table_name ) . '`';
        $latest_half_hour = $wpdb->get_var( $sql );
        if ( is_string( $latest_half_hour ) && '' !== $latest_half_hour ) {
            return $latest_half_hour;
        }

        return gmdate( 'Y-m-d H:i:s', time() - ( time() % ( 30 * MINUTE_IN_SECONDS ) ) );
    }

    /**
     * Returns the earliest half hour, as a YYYY-MM-DD HH:MM:SS string. The return
     * value represents the latest midnight more than four weeks ago; this ensures
     * that the half-hourly data represents complete days for aggregation.
     *
     * @return string
     */
    private static function getEarliestHalfHour(): string {
        $now = time();
        $four_weeks_ago_midnight = gmmktime(
            0,
            0,
            0,
            (int) gmdate( 'n', $now ),
            (int) gmdate( 'j', $now ) - 1,
            (int) gmdate( 'Y', $now )
        );

        return gmdate( 'Y-m-d H:i:s', $four_weeks_ago_midnight );
    }

    /**
     * Deletes old half-hourly data to reduce the size of the database.
     *
     * @return int|false
     */
    private static function deleteOldHalfHours() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'national_grid_past_half_hours';
        $cutoff_time = self::getEarliestHalfHour();

        $sql = $wpdb->prepare(
            'DELETE FROM `' . esc_sql( $table_name ) . '` WHERE `time` < %s',
            $cutoff_time
        );

        return $wpdb->query( $sql );
    }

    /**
     * Returns the latest row from a table as an associative map.
     *
     * @param string $table Table suffix or full table name.
     * @return array<string, mixed>
     */
    private static function getLatestMap( string $table ): array {
        global $wpdb;

        $safe_table = preg_replace( '/[^a-z0-9_]/i', '', $table );
        if ( '' === $safe_table ) {
            return [ 'time' => '0000-00-00 00:00:00' ];
        }

        $table_name = ( 0 === strpos( $safe_table, $wpdb->prefix ) ) ? $safe_table : $wpdb->prefix . 'national_grid_' . $safe_table;
        $map = $wpdb->get_row(
            'SELECT * FROM `' . esc_sql( $table_name ) . '` ORDER BY `time` DESC LIMIT 1',
            ARRAY_A
        );

        if ( is_array( $map ) ) {
            return $map;
        }

        // Default zero values for new instances with an empty database.
        $columns = array_unique( array_merge( Generation::KEYS, Demand::KEYS ) );
        $default_map = array_fill_keys( $columns, '0' );
        $default_map['time'] = '0000-00-00 00:00:00';

        return $default_map;
    }

    /**
     * Builds SQL AVG(...) projection for a set of numeric columns.
     *
     * @param array<int, string> $columns Column names.
     * @return string
     */
    private static function getAveragesExpression( array $columns ): string {
        return implode(
            ', ',
            array_map(
                static function ( $column ) {
                    $safe_column = '`' . preg_replace( '/[^a-z0-9_]/i', '', (string) $column ) . '`';
                    return 'AVG(' . $safe_column . ') AS ' . $safe_column;
                },
                $columns
            )
        );
    }

    /**
     * Aggregates generation data from the five-minute time series into the
     * half-hour time series, propagating forward the most recent half-hour
     * non-generation values.
     *
     * @return bool|int
     */
    private static function aggregateGeneration() {
        global $wpdb;

        $past_half_hours = $wpdb->prefix . 'national_grid_past_half_hours';
        $past_five_minutes = $wpdb->prefix . 'national_grid_past_five_minutes';

        // store the most recent half-hour values so we can propagate them forwards
        $previous_half_hour = self::getLatestMap( 'past_half_hours' );

        // To determine the latest complete half-hour, we subtract 25 minutes from
        // the most recent time and then round down to a multiple of 30 minutes.
        // This works because a half-hour is complete once the five-minute period
        // starting at 25 or 55 minutes past the hour is available.
        $latest_half_hour = $wpdb->get_var(
            'SELECT DATE_SUB(`time`, INTERVAL MOD(MINUTE(`time`), 30) MINUTE)
             FROM (
                SELECT DATE_SUB(MAX(`time`), INTERVAL 25 MINUTE) AS `time`
                FROM `' . esc_sql( $past_five_minutes ) . '`
             ) AS t'
        );

        if ( ! is_string( $latest_half_hour ) || '' === $latest_half_hour ) {
            return 0;
        }

        // aggregate the five-minute data for complete half-hours
        $generation_columns_sql = '`' . implode(
            '`, `',
            array_map(
                static function ( $column ) {
                    return preg_replace( '/[^a-z0-9_]/i', '', (string) $column );
                },
                Generation::KEYS
            )
        ) . '`';

        $aggregate_sql = 'INSERT INTO `'
            . esc_sql( $past_half_hours )
            . '` (`time`, '
            . $generation_columns_sql
            . ') SELECT DATE_SUB(`time`, INTERVAL MOD(MINUTE(`time`), 30) MINUTE) AS aggregated_time, '
            . self::getAveragesExpression( Generation::KEYS )
            . ' FROM `'
            . esc_sql( $past_five_minutes )
            . '` GROUP BY aggregated_time HAVING aggregated_time <= %s'
            . self::getOnDuplicateKeyUpdateClause( Generation::KEYS );

        $prepared_aggregate_sql = $wpdb->prepare( $aggregate_sql, $latest_half_hour );
        if ( false === $wpdb->query( $prepared_aggregate_sql ) ) {
            return false;
        }

        // propagate forwards the non-generation data for newly inserted half-hours
        if ( empty( Demand::KEYS ) ) {
            return true;
        }

        $set_clauses = [];
        $set_values = [];
        foreach ( Demand::KEYS as $column ) {
            $safe_column = preg_replace( '/[^a-z0-9_]/i', '', (string) $column );
            if ( '' === $safe_column ) {
                continue;
            }

            $set_clauses[] = '`' . $safe_column . '` = %f';
            $set_values[] = isset( $previous_half_hour[ $safe_column ] ) ? (float) $previous_half_hour[ $safe_column ] : 0.0;
        }

        if ( empty( $set_clauses ) ) {
            return true;
        }

        $update_sql = 'UPDATE `'
            . esc_sql( $past_half_hours )
            . '` SET '
            . implode( ', ', $set_clauses )
            . ' WHERE `time` > %s';
        $set_values[] = isset( $previous_half_hour['time'] ) ? (string) $previous_half_hour['time'] : '0000-00-00 00:00:00';

        $prepared_update_sql = $wpdb->prepare( $update_sql, $set_values );
        $result = $wpdb->query( $prepared_update_sql );

        if ( false === $result ) {
            return false;
        }

        return true;
    }

    /**
     * Returns latest five-minute generation row.
     *
     * @return array<string, mixed>
     */
    public static function getLatestFiveMinuteGeneration() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'national_grid_past_five_minutes';
        $sql = 'SELECT * FROM `' . esc_sql( $table_name ) . '` ORDER BY `time` DESC LIMIT 1';
        $row = $wpdb->get_row( $sql, ARRAY_A );

        if ( ! is_array( $row ) ) {
            return [];
        }

        return $row;
    }

    /**
     * Returns latest half-hour row or empty array when not available.
     *
     * @return array<string, mixed>
     */
    public static function getLatestHalfHourRow() {
        $rows = self::getRecentHalfHours( 1 );
        if ( empty( $rows ) ) {
            return [];
        }

        return $rows[0];
    }

    /**
     * Safely extracts numeric value from associative row.
     *
     * @param array<string, mixed> $row Source row.
     * @param string $key Column key.
     * @return float
     */
    private static function getNumericFromRow( array $row, $key ) {
        if ( ! isset( $row[ $key ] ) ) {
            return 0.0;
        }

        return (float) $row[ $key ];
    }

    /**
     * Builds frontend pie values from latest generation and demand rows.
     *
     * @param array<string, mixed> $latest_five_minutes Latest five-minute row.
     * @param array<string, mixed> $latest_half_hour Latest half-hour row.
     * @return array<string, float>
     */
    private static function buildFrontendPieData( array $latest_five_minutes, array $latest_half_hour, bool $log_debug = false ) {
        $pie = [];
        $formula_lines = [];

        foreach ( self::FRONTEND_PIE_MAPPING as $label => $sources ) {
            $value = 0.0;
            $parts = [];
            foreach ( $sources as $source_key ) {
                if ( in_array( $source_key, [ 'embedded_wind', 'embedded_solar' ], true ) ) {
                    $source_value = self::getNumericFromRow( $latest_half_hour, $source_key );
                    $value += $source_value;
                    $parts[] = $source_key . '(' . number_format( $source_value, 2, '.', '' ) . ' GW)';
                    continue;
                }

                $source_value = self::getNumericFromRow( $latest_five_minutes, $source_key );
                if ( 'pumped' === $source_key && $source_value < 0 ) {
                    $source_value = 0.0;
                }

                $value += $source_value;
                $parts[] = $source_key . '(' . number_format( $source_value, 2, '.', '' ) . ' GW)';
            }

            if ( 'Interconnectors' === $label && $value < 0 ) {
                $value = 0.0;
            }

            $pie[ $label ] = $value;
            $formula_lines[] = $label . ' = ' . implode( ' + ', $parts ) . ' = ' . number_format( $value, 2, '.', '' ) . ' GW';
        }

        $total_generation = array_sum( $pie );
        $clean_power = 0.0;
        foreach ( [ 'Wind', 'Solar', 'Hydroelectric', 'Biomass', 'Nuclear' ] as $clean_label ) {
            $clean_power += isset( $pie[ $clean_label ] ) ? (float) $pie[ $clean_label ] : 0.0;
        }
        $clean_power_percent = $total_generation > 0 ? ( $clean_power / $total_generation ) * 100 : 0.0;
        $clean_power_formula = sprintf(
            'Wind(%1$s GW) + Solar(%2$s GW) + Hydroelectric(%3$s GW) + Biomass(%4$s GW) + Nuclear(%5$s GW)',
            number_format( isset( $pie['Wind'] ) ? (float) $pie['Wind'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Solar'] ) ? (float) $pie['Solar'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Hydroelectric'] ) ? (float) $pie['Hydroelectric'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Biomass'] ) ? (float) $pie['Biomass'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Nuclear'] ) ? (float) $pie['Nuclear'] : 0.0, 2, '.', '' )
        );
        $get_generation_value = static function ( string $key ) use ( $latest_five_minutes ): float {
            if ( ! isset( $latest_five_minutes[ $key ] ) || ! is_numeric( $latest_five_minutes[ $key ] ) ) {
                return 0.0;
            }

            return (float) $latest_five_minutes[ $key ];
        };
        $interconnector_by_country = [
            'France' => [
                'ifa' => $get_generation_value( 'ifa' ),
                'ifa2' => $get_generation_value( 'ifa2' ),
                'eleclink' => $get_generation_value( 'eleclink' ),
            ],
            'Ireland' => [
                'moyle' => $get_generation_value( 'moyle' ),
                'ewic' => $get_generation_value( 'ewic' ),
                'greenlink' => $get_generation_value( 'greenlink' ),
            ],
            'Netherlands' => [
                'britned' => $get_generation_value( 'britned' ),
            ],
            'Belgium' => [
                'nemo' => $get_generation_value( 'nemo' ),
            ],
            'Norway' => [
                'nsl' => $get_generation_value( 'nsl' ),
            ],
            'Denmark' => [
                'viking' => $get_generation_value( 'viking' ),
            ],
        ];
        $interconnector_country_lines = [];
        foreach ( $interconnector_by_country as $country => $components ) {
            $parts = [];
            $country_total = 0.0;
            foreach ( $components as $component_key => $component_value ) {
                $country_total += (float) $component_value;
                $parts[] = $component_key . '(' . number_format( (float) $component_value, 2, '.', '' ) . ' GW)';
            }

            $interconnector_country_lines[] = $country . ' = ' . implode( ' + ', $parts ) . ' = ' . number_format( $country_total, 2, '.', '' ) . ' GW';
        }
        $total_generation_formula = sprintf(
            'Storage(%1$s GW) + Interconnectors(%2$s GW) + Biomass(%3$s GW) + Nuclear(%4$s GW) + Hydroelectric(%5$s GW) + Solar(%6$s GW) + Wind(%7$s GW) + Gas(%8$s GW)',
            number_format( isset( $pie['Storage'] ) ? (float) $pie['Storage'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Interconnectors'] ) ? (float) $pie['Interconnectors'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Biomass'] ) ? (float) $pie['Biomass'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Nuclear'] ) ? (float) $pie['Nuclear'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Hydroelectric'] ) ? (float) $pie['Hydroelectric'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Solar'] ) ? (float) $pie['Solar'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Wind'] ) ? (float) $pie['Wind'] : 0.0, 2, '.', '' ),
            number_format( isset( $pie['Gas'] ) ? (float) $pie['Gas'] : 0.0, 2, '.', '' )
        );

        if ( $log_debug && self::isDebugModeEnabled() ) {
            self::appendDebugLog(
                'Chart calculations',
                [
                    'DB selection (latest five minutes):',
                    wp_json_encode( $latest_five_minutes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                    'DB selection (latest half hour):',
                    wp_json_encode( $latest_half_hour, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                    'Computed pie values:',
                    ...$formula_lines,
                    'Total generation = ' . $total_generation_formula . ' = ' . number_format( $total_generation, 2, '.', '' ) . ' GW',
                    'Clean Power = ' . $clean_power_formula . ' = ' . number_format( $clean_power, 2, '.', '' ) . ' GW',
                    sprintf(
                        'Clean Power share = (Clean Power(%1$s GW) / Total generation(%2$s GW)) * 100 = %3$s%%',
                        number_format( $clean_power, 2, '.', '' ),
                        number_format( $total_generation, 2, '.', '' ),
                        number_format( $clean_power_percent, 1, '.', '' )
                    ),
                    'Interconnectors by country:',
                    ...$interconnector_country_lines,
                    str_repeat( '-', 120 ),
                ]
            );
        }

        return $pie;
    }

    /**
     * Returns recent half-hour rows in chronological order.
     *
     * @param int $limit Max rows to return.
     * @return array<int, array<string, mixed>>
     */
    public static function getRecentHalfHours( $limit = 1 ) {
        global $wpdb;

        $limit = max( 1, min( 1000, (int) $limit ) );
        $table_name = $wpdb->prefix . 'national_grid_past_half_hours';
        $sql = $wpdb->prepare(
            'SELECT * FROM `' . esc_sql( $table_name ) . '` ORDER BY `time` DESC LIMIT %d',
            $limit
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_reverse( $rows );
    }

    /**
     * Builds chart payload for frontend rendering.
     *
     * @return array<string, mixed>
     */
    public static function getFrontendChartData() {
        $cached = get_transient( self::FRONTEND_CHART_TRANSIENT_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $latest_five_minutes = self::getLatestFiveMinuteGeneration();
        $latest_half_hour = self::getLatestHalfHourRow();
        $pie = self::buildFrontendPieData( $latest_five_minutes, $latest_half_hour );

        $payload = [
            'latest' => $latest_half_hour,
            'latest_five_minutes' => $latest_five_minutes,
            'latest_half_hour' => $latest_half_hour,
            'pie' => $pie
        ];

        set_transient( self::FRONTEND_CHART_TRANSIENT_KEY, $payload, self::FRONTEND_CHART_TRANSIENT_TTL );

        return $payload;
    }

    /**
     * Clears cached frontend chart payload.
     *
     * @return void
     */
    public static function invalidateFrontendChartCache(): void {
        delete_transient( self::FRONTEND_CHART_TRANSIENT_KEY );
    }

    /**
     * Logs chart input and formula output for debugging.
     *
     * @return void
     */
    public static function logChartComputationDebug(): void {
        if ( ! self::isDebugModeEnabled() ) {
            return;
        }

        $latest_five_minutes = self::getLatestFiveMinuteGeneration();
        $latest_half_hour = self::getLatestHalfHourRow();
        self::buildFrontendPieData( $latest_five_minutes, $latest_half_hour, true );
    }
}
