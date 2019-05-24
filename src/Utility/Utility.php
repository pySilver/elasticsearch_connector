<?php

namespace Drupal\elasticsearch_connector\Utility;

use Illuminate\Support\Arr;

/**
 * Class Utility.
 *
 * @package Drupal\elasticsearch_connector\Utility
 */
class Utility {

  /**
   * Flatten a multi-dimensional associative array with dots.
   *
   * @param array $array
   *   Input array.
   * @param string $prepend
   *   Optional prefix.
   * @param string $separator
   *   Path separator.
   * @param bool $skip_sequential
   *   Keep traversing sequential descendants.
   *
   * @return array
   *   Flat array.
   */
  public static function dot(array $array, $prepend = '', $separator = '.', $skip_sequential = TRUE): array {
    $results = [];

    if ($skip_sequential && !Arr::isAssoc($array)) {
      return $results;
    }

    foreach ($array as $key => $value) {
      if (is_array($value) && !empty($value) && (!$skip_sequential || Arr::isAssoc($value))) {
        $results = array_merge($results, static::dot($value, $prepend . $key . $separator, $separator, $skip_sequential));
      }
      else {
        $results[$prepend . $key] = $value;
      }
    }

    return $results;
  }

}
