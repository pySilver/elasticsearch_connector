<?php

namespace Drupal\elasticsearch_connector\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Provides a string data type.
 *
 * @SearchApiDataType(
 *   id = "nested_object",
 *   label = @Translation("Nested Object"),
 *   description = @Translation("Structured Nested Object support"),
 *   default = "true"
 * )
 */
class NestedObjectDataType extends DataTypePluginBase {
}
