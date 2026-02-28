<?php

/** Updates data from the National Energy System Operator Demand Data Update. */
class Demand {
  public const KEYS = [
    'embedded_wind',
    'embedded_solar'
  ];

  /**
   * Updates the demand data.
   *
   * @return array{
   *   read:int,
   *   valid:int,
   *   skipped:int,
   *   rows_written:int,
   *   rows_deleted:int,
   *   success:bool
   * }
   *
   * @throws DataException If the source data was invalid
   */
  public static function update(): array {
    $rows = Csv::parse(
      'https://api.neso.energy/dataset/7a12172a-939c-404c-b581-a6128b74f588/resource/177f6fa4-ae49-4182-81ea-0c6b35f26ca6/download/demanddataupdate.csv',
      [
        'SETTLEMENT_DATE',
        'SETTLEMENT_PERIOD',
        'EMBEDDED_WIND_GENERATION',
        'EMBEDDED_SOLAR_GENERATION'
      ],
      [
        'ND',
        'FORECAST_ACTUAL_INDICATOR',
        'TSD',
        'ENGLAND_WALES_DEMAND',
        'EMBEDDED_WIND_CAPACITY',
        'EMBEDDED_SOLAR_CAPACITY',
        'NON_BM_STOR',
        'PUMP_STORAGE_PUMPING',
        'SCOTTISH_TRANSFER',
        'IFA_FLOW',
        'IFA2_FLOW',
        'BRITNED_FLOW',
        'MOYLE_FLOW',
        'EAST_WEST_FLOW',
        'NEMO_FLOW',
        'NSL_FLOW',
        'ELECLINK_FLOW',
        'VIKING_FLOW',
        'GREENLINK_FLOW'
      ]
    );

    $validData = [];
    $skipped = 0;

    foreach ($rows as $item) {
      try {
        $validData[] = self::getDatum($item);
      } catch (DataException $e) {
        $skipped++;
      }
    }

    $update_result = DatabaseStorage::updateDemand($validData);
    $is_success = is_array($update_result) && isset($update_result['rows_written'], $update_result['rows_deleted']);

    return [
      'read' => count($rows),
      'valid' => count($validData),
      'skipped' => $skipped,
      'rows_written' => $is_success ? (int) $update_result['rows_written'] : 0,
      'rows_deleted' => $is_success ? (int) $update_result['rows_deleted'] : 0,
      'success' => $is_success,
    ];
  }

  /**
   * Returns the datum for an item.
   *
   * @param array $item The item
   *
   * @throws DataException If the data was invalid
   */
  private static function getDatum(array $item): array {
    for ($i = 2; $i <= 3; $i ++) {
      if (!ctype_digit($item[$i])) {
        throw new DataException('Non-integer value: ' . $item[$i]);
      }
    }

    return [
      Time::getSettlementTime($item[0], $item[1]),
      (int)$item[2] / 1000,
      (int)$item[3] / 1000
    ];
  }
}
