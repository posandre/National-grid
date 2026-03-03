<?php
/** Updates generation data. */
class Generation {
    /** Ordered storage keys for generation and interconnector values. */
    public const KEYS = [
        'coal',       // Coal-fired generation (вугільні електростанції)
        'ccgt',       // Combined Cycle Gas Turbine (газові ТЕС комбінованого циклу)
        'ocgt',       // Open Cycle Gas Turbine (газові турбіни відкритого циклу)
        'nuclear',    // Nuclear generation (атомна енергетика)
        'oil',        // Oil-fired generation (мазут / нафтова генерація)
        'wind',       // Onshore + Offshore wind generation (вітрова генерація)
        'hydro',      // Conventional hydro generation (гідроелектростанції)
        'pumped',     // Pumped storage generation (ГАЕС у режимі генерації)
        'biomass',    // Biomass generation (біоенергетика)
        'battery',    // Battery storage discharge (акумуляторні системи у режимі генерації)
        'other',      // Other generation sources (інші джерела)

        // Interconnectors (flows між GB та іншими країнами)
        'ifa',        // IFA1 – Interconnector France (GB ↔ France)
        'moyle',      // Moyle – GB ↔ Northern Ireland
        'britned',    // BritNed – GB ↔ Netherlands
        'ewic',       // East West Interconnector – GB ↔ Ireland
        'nemo',       // Nemo Link – GB ↔ Belgium
        'ifa2',       // IFA2 – second GB ↔ France interconnector
        'nsl',        // North Sea Link – GB ↔ Norway
        'eleclink',   // ElecLink – GB ↔ France (Channel Tunnel)
        'viking',     // Viking Link – GB ↔ Denmark
        'greenlink'   // Greenlink – GB ↔ Ireland
    ];

    /** Source fuel type to output column index mapping. */
    public const COLUMNS = [
        'COAL'    => 1,
        'CCGT'    => 2,
        'OCGT'    => 3,
        'NUCLEAR' => 4,
        'OIL'     => 5,
        'WIND'    => 6,
        'NPSHYD'  => 7,
        'PS'      => 8,
        'BIOMASS' => 9,
        'BESS'    => 10,
        'OTHER'   => 11,
        'INTFR'   => 12,
        'INTIRL'  => 13,
        'INTNED'  => 14,
        'INTEW'   => 15,
        'INTNEM'  => 16,
        'INTIFA2' => 17,
        'INTNSL'  => 18,
        'INTELEC' => 19,
        'INTVKL'  => 20,
        'INTGRNL' => 21
    ];

    /**
     * Updates the generation data.
     *
     * @return array<string, int>|false
     *
     * @throws DataException If the data was invalid
     * @throws Throwable
     */
  public static function update() {
    $rawData = @file_get_contents(
      sprintf(
        'https://data.elexon.co.uk/bmrs/api/v1/datasets/FUELINST/stream?publishDateTimeFrom=%s&publishDateTimeTo=%s',
        gmdate('Y-m-d\\TH:i:s\\Z', time() - 24 * 60 * 60),
        gmdate('Y-m-d\\TH:i:s\\Z')
      )
    );

    if ($rawData === false) {
        throw new DataException( 'Generation data: Failed to read generatiob data from data.elexon.co.uk.' );
    }

    $jsonData = json_decode($rawData, true);

    if (!is_array($jsonData)) {
      throw new DataException('Generation data: Missing data');
    }

    $data = [];

    foreach ($jsonData as $item) {
      if (!is_array($item)) {
        throw new DataException('Generation data: Invalid item');
      }

      $time = self::getTime($item);

      if (!isset($data[$time])) {
        $data[$time] = array_fill(0, count(self::COLUMNS) + 1, 0);
        $data[$time][0] = $time;
      }

      $data[$time][self::getColumn($item)] = self::getGeneration($item);
    }

      if ( DatabaseStorage::isDebugModeEnabled() ) {
          $generation_lines = [];
          $field_order = array_merge( [ 'time' ], self::KEYS );
          foreach ( $data as $point_time => $point_row ) {
              $generation_lines[] = (string) $point_time . ': ' . wp_json_encode( array_values( (array) $point_row ), JSON_UNESCAPED_SLASHES );
          }

          DatabaseStorage::appendDebugLog(
              'Data written to DB (generation)',
              [
                  'Prepared generation rows count: ' . count( $data ),
                  'Field order: ' . wp_json_encode( $field_order, JSON_UNESCAPED_SLASHES ),
                  'Generation rows payload:',
                  ...$generation_lines,
              ]
          );
      }

      return DatabaseStorage::updateGeneration( $data );
  }

  /**
   * Returns the time for an item.
   *
   * @param array $item The item
   * @return string
   *
   * @throws DataException If the time was invalid
   */
  private static function getTime(array $item): string {
    if (!isset($item['startTime'])) {
      throw new DataException('Missing start time');
    }

    $time = $item['startTime'];

    if (!is_string($time)) {
      throw new DataException('Generation data: Invalid start time: ' . $time);
    }

    return Time::normalise($item['startTime'], 5);
  }

  /**
   * Returns the column for an item.
   *
   * @param array $item The item
   * @return int
   *
   * @throws DataException If the fuel type was invalid
   */
  private static function getColumn(array $item): int {
    if (!isset($item['fuelType'])) {
      throw new DataException('Generation data: Missing fuel type');
    }

    $fuelType = $item['fuelType'];

    if (!is_string($fuelType) || !isset(self::COLUMNS[$fuelType])) {
      throw new DataException('Generation data: Invalid fuel type: ' . $fuelType);
    }

    return self::COLUMNS[$fuelType];
  }

  /**
   * Returns the generation for an item.
   *
   * @param array $item The item
   * @return float
   *
   * @throws DataException If the generation was invalid
   */
  private static function getGeneration(array $item): float {
    if (!isset($item['generation'])) {
      throw new DataException('Missing generation');
    }

    $generation = $item['generation'];

    if (!is_int($generation)) {
      throw new DataException('Invalid generation value: ' . $generation);
    }

    return round($generation / 1000, 2);
  }
}
