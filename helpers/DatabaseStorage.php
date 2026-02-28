<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DatabaseStorage {
    /**
     * Returns true when both generation tables use InnoDB and transactions are safe.
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
     * @throws Throwable
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
            throw $e;
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
            throw $e;
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

    /** Returns the latest half-hour, as a YYYY-MM-DD HH:MM:SS string. */
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

    /** Deletes old half-hourly data to reduce the size of the database. */
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
}
