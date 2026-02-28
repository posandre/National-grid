<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DatabaseStorage {
    /**
     * Writes generation rows into the past_five_minutes table.
     *
     * Maps Generation::COLUMNS (source indexes) to Generation::KEYS (table columns).
     *
     * @param array $new_generation Generation rows indexed by time.
     *
     * @return int|false Number of written rows or false on database error.
     */
    public static function updateGeneration( array $new_generation ):int {
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
     * @return void Number of deleted rows or false on database error.
     */
    public static function deleteOldGeneratin():void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'national_grid_past_five_minutes';
        $cutoff_time = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

        $sql = $wpdb->prepare(
            'DELETE FROM `' . esc_sql( $table_name ) . '` WHERE `time` < %s',
            $cutoff_time
        );

        $wpdb->query( $sql );
    }

    /**
     * Updates data, ignoring data prior to the earliest half hour or past the
     * latest half hour.
     *
     * @param array $columns The columns to update
     * @param array $data    The data
     */
    public static function updateDemand(array $columns, array $data) {
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

        error_log(
            sprintf(
                '[National Grid] updateDemand $earliest=%s $latest=%s',
                $earliest,
                $latest
            )
        );
//
//        error_log(
//            sprintf(
//                '[National Grid] updateDemand $filtered_data=%s',
//                print_r($filtered_data, true)
//            )
//        );

        return self::updatePastTimeSeries( 'past_half_hours', $columns, $filtered_data );
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

        error_log(
            sprintf(
                '[National Grid] updatePastTimeSeries $filtered_data=%s',
                print_r($data, true)
            )
        );

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

    /** Returns the latest half hour, as a YYYY-MM-DD HH:MM:SS string. */
    public static function getLatestHalfHour(): string {
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

        error_log(
            sprintf(
                '[National Grid] getLatestHalfHour result=%s source=%s table_max=%s current_half_hour=%s',
                $latest_half_hour,
                $source,
                is_string( $latest_from_table ) && '' !== $latest_from_table ? $latest_from_table : 'null',
                $current_half_hour
            )
        );

        return $latest_half_hour;
    }

    /**
     * Returns the earliest half hour, as a YYYY-MM-DD HH:MM:SS string. The return
     * value represents the latest midnight more than four weeks ago; this ensures
     * that the half-hourly data represents complete days for aggregation.
     */
    public static function getEarliestHalfHour(): string {
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
}
