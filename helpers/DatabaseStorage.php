<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DatabaseStorage {
    /** Log status value for successful events. */
    private const STATUS_SUCCESS = 'success';
    /** Log status value for failed events. */
    private const STATUS_ERROR = 'error';
    /** Maps frontend pie labels to storage columns used for aggregation. */
    private const FRONTEND_PIE_MAPPING = array(
        'Gas' => array( 'ccgt', 'ocgt' ),
        'Wind' => array( 'wind', 'embedded_wind' ),
        'Solar' => array( 'embedded_solar' ),
        'Hydroelectric' => array( 'hydro' ),
        'Nuclear' => array( 'nuclear' ),
        'Biomass' => array( 'biomass' ),
        'Interconnectors' => array( 'ifa', 'moyle', 'britned', 'ewic', 'nemo', 'ifa2', 'nsl', 'eleclink', 'viking', 'greenlink' ),
        'Storage' => array( 'pumped', 'battery' ),
    );

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
    public static function logSuccess( $source, $message, array $context = array() ) {
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
    public static function logError( $source, $message, array $context = array() ) {
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
    public static function logEvent( $source, $status, $message, array $context = array() ) {
        global $wpdb;

        $table_name = self::getLogsTableName();
        $result = $wpdb->insert(
            $table_name,
            array(
                'created_at' => gmdate( 'Y-m-d H:i:s' ),
                'source' => sanitize_key( (string) $source ),
                'status' => sanitize_key( (string) $status ),
                'message' => sanitize_text_field( (string) $message ),
                'context' => wp_json_encode( $context ),
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );

        return false !== $result;
    }

    /**
     * Returns recent log rows sorted by newest first.
     *
     * @param int $limit Max rows to return.
     * @return array<int, array<string, mixed>>
     */
    public static function getRecentLogs( $limit = 200 ) {
        global $wpdb;

        $limit = max( 1, (int) $limit );
        $table_name = self::getLogsTableName();
        $sql = $wpdb->prepare(
            'SELECT `id`, `created_at`, `source`, `status`, `message`, `context` FROM `' . esc_sql( $table_name ) . '` ORDER BY `id` DESC LIMIT %d',
            $limit
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A );

        return is_array( $rows ) ? $rows : array();
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
     * Returns true when both generation tables use InnoDB and transactions are safe.
     *
     * @return bool
     */
    public static function canUseGenerationTransactions(): bool {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'national_grid_past_five_minutes',
            $wpdb->prefix . 'national_grid_past_half_hours',
        );

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
        $single_row_placeholders = '(' . implode( ', ', array_merge( array( '%s' ), array_fill( 0, count( $safe_columns ), '%f' ) ) ) . ')';
        $rows_placeholders = array();
        $query_values = array();

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
        $latest_from_table = $wpdb->get_var( $sql );
        $current_half_hour = gmdate( 'Y-m-d H:i:s', time() - ( time() % ( 30 * MINUTE_IN_SECONDS ) ) );
        $source = 'current_half_hour';

        if ( ! is_string( $latest_from_table ) || '' === $latest_from_table ) {
            $latest_half_hour = $current_half_hour;
        } else {
            if ( $latest_from_table > $current_half_hour ) {
                $latest_half_hour = $latest_from_table;
                $source = 'table_max';
            } else {
                $latest_half_hour = $current_half_hour;
            }
        }

//        error_log(
//            sprintf(
//                '[National Grid] getLatestHalfHour result=%s source=%s table_max=%s current_half_hour=%s',
//                $latest_half_hour,
//                $source,
//                is_string( $latest_from_table ) && '' !== $latest_from_table ? $latest_from_table : 'null',
//                $current_half_hour
//            )
//        );

        return $latest_half_hour;
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
            return array( 'time' => '0000-00-00 00:00:00' );
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

        $set_clauses = array();
        $set_values = array();
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
            return array();
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
            return array();
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
    private static function buildFrontendPieData( array $latest_five_minutes, array $latest_half_hour ) {
        $pie = array();

        foreach ( self::FRONTEND_PIE_MAPPING as $label => $sources ) {
            $value = 0.0;
            foreach ( $sources as $source_key ) {
                if ( in_array( $source_key, array( 'embedded_wind', 'embedded_solar' ), true ) ) {
                    $value += self::getNumericFromRow( $latest_half_hour, $source_key );
                    continue;
                }

                $source_value = self::getNumericFromRow( $latest_five_minutes, $source_key );
                if ( 'pumped' === $source_key && $source_value < 0 ) {
                    $source_value = 0.0;
                }

                $value += $source_value;
            }

            if ( 'Interconnectors' === $label && $value < 0 ) {
                $value = 0.0;
            }

            $pie[ $label ] = $value;
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
            return array();
        }

        return array_reverse( $rows );
    }

    /**
     * Builds chart payload for frontend rendering.
     *
     * @param int $limit Max half-hour points to include.
     * @return array<string, mixed>
     */
    public static function getFrontendChartData( $limit = 1 ) {
        $rows = self::getRecentHalfHours( $limit );
        $latest_five_minutes = self::getLatestFiveMinuteGeneration();
        $latest_half_hour = self::getLatestHalfHourRow();
        $pie = self::buildFrontendPieData( $latest_five_minutes, $latest_half_hour );

        if ( empty( $rows ) ) {
            return array(
                'labels' => array(),
                'series' => array(),
                'latest' => array(),
                'latest_five_minutes' => $latest_five_minutes,
                'latest_half_hour' => $latest_half_hour,
                'pie' => $pie,
                'pie_mapping' => self::FRONTEND_PIE_MAPPING,
            );
        }

        $columns = array_keys( $rows[0] );
        $columns = array_values(
            array_filter(
                $columns,
                static function ( $column ) {
                    return 'time' !== $column;
                }
            )
        );

        $labels = array();
        $series = array();
        foreach ( $columns as $column ) {
            $series[ $column ] = array();
        }

        foreach ( $rows as $row ) {
            $labels[] = isset( $row['time'] ) ? (string) $row['time'] : '';
            foreach ( $columns as $column ) {
                $series[ $column ][] = isset( $row[ $column ] ) ? (float) $row[ $column ] : 0.0;
            }
        }

        return array(
            'labels' => $labels,
            'series' => $series,
            'latest' => end( $rows ),
            'latest_five_minutes' => $latest_five_minutes,
            'latest_half_hour' => $latest_half_hour,
            'pie' => $pie,
            'pie_mapping' => self::FRONTEND_PIE_MAPPING,
        );
    }
}
