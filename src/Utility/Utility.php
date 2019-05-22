<?php

namespace Drupal\elasticsearch_connector\Utility;

/**
 * Class Utility.
 *
 * @package Drupal\elasticsearch_connector\Utility
 */
class Utility {

  /**
   * Flatten any key-value array.
   *
   * @param array $input
   *   Input array.
   * @param string $prefix
   *   Prefix/separator.
   * @param string $separator
   *   Path separator.
   * @param bool $skip_sequential
   *   Defines whether sequential arrays should be processed.
   *
   * @return array
   *   Result flat array.
   */
  public static function flattenArray(
    array $input,
    string $prefix = '',
    string $separator = '.',
    bool $skip_sequential = TRUE
  ): array {
    $result = [];

    if ($skip_sequential && !self::isArrayAssoc($input)) {
      return $input;
    }

    foreach ($input as $key => $value) {
      if (is_array($value) && (!$skip_sequential || self::isArrayAssoc($value))) {
        $result += self::flattenArray($value, $prefix . $separator . $key . $separator);
      }
      else {
        $result[$prefix . $separator . $key] = $value;
      }
    }

    return $result;
  }

  /**
   * Checks if input is associative array.
   *
   * @param array $input
   *   Input array.
   *
   * @return bool
   *   Check result
   */
  public static function isArrayAssoc(array $input): bool {
    return array_keys($input) !== range(0, count($input) - 1);
  }

}
