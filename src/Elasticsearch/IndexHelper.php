<?php

namespace Drupal\elasticsearch_connector\Elasticsearch;

use Drupal\elasticsearch_connector\Event\PrepareDocumentIndexEvent;
use Drupal\elasticsearch_connector\Event\PrepareMappingEvent;
use Drupal\field\FieldConfigInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\elasticsearch_connector\Event\PrepareIndexEvent;
use Drupal\elasticsearch_connector\Event\PrepareIndexMappingEvent;
use Elastica\Document;

/**
 * Create Elasticsearch Indices.
 */
class IndexHelper {

  /**
   * Build parameters required to index.
   *
   * TODO: We need to handle the following params as well:
   * ['consistency'] = (enum) Explicit write consistency setting for the
   * operation
   * ['refresh']     = (boolean) Refresh the index after performing the
   * operation
   * ['replication'] = (enum) Explicitly set the replication type
   * ['fields']      = (list) Default comma-separated list of fields to return
   * in the response for updates.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index to create.
   *
   * @return array
   *   Associative array with the following keys:
   *   - index: The name of the index on the Elasticsearch server.
   *   - type: The name of the type to use for the given index.
   */
  public static function index(IndexInterface $index) {
    return [
      'index' => self::getIndexName($index),
      'type'  => $index->id(),
    ];
  }

  /**
   * Build parameters required to create an index
   * TODO: Add the timeout option.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *
   * @return array
   */
  public static function create(IndexInterface $index) {
    $indexConfig = [
      'number_of_shards'   => $index->getOption('number_of_shards', 5),
      'number_of_replicas' => $index->getOption('number_of_replicas', 1),
    ];

    // Allow other modules to alter index config before we create it.
    $dispatcher = \Drupal::service('event_dispatcher');
    $prepareIndexEvent = new PrepareIndexEvent($indexConfig, $index);
    $event = $dispatcher->dispatch(PrepareIndexEvent::PREPARE_INDEX, $prepareIndexEvent);
    $indexConfig = $event->getIndexConfig();

    return $indexConfig;
  }

  /**
   * Build parameters to bulk delete indexes.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index object.
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   An array of items to be indexed, keyed by their item IDs.
   *
   * @return \Elastica\Document[]
   *   Array of parameters to send along to Elasticsearch to perform the bulk
   *   index.
   */
  public static function bulkIndex(IndexInterface $index, array $items) {
    $dispatcher = \Drupal::service('event_dispatcher');
    $documents = [];

    foreach ($items as $id => $item) {
      $data = [
        'search_api_language' => $item->getLanguage(),
      ];

      /** @var \Drupal\search_api\Item\FieldInterface $field */
      foreach ($item as $name => $field) {
        $field_type = $field->getType();
        $values = [];
        foreach ($field->getValues() as $value) {
          $values[] = self::getFieldValue($field_type, $value);
        }
        $data[$field->getFieldIdentifier()] = $values;
      }

      // Allow other modules to alter document before we create it.
      $documentIndexEvent = new PrepareDocumentIndexEvent(
        $data,
        $index
      );

      /** @var \Drupal\elasticsearch_connector\Event\PrepareDocumentIndexEvent $event */
      $event = $dispatcher->dispatch(
        PrepareDocumentIndexEvent::PREPARE_DOCUMENT_INDEX,
        $documentIndexEvent
      );

      $documents[] = new Document($id, $event->getDocument());
    }

    return $documents;
  }

  /**
   * Build parameters required to create an index mapping.
   *
   * TODO: We need also:
   * $params['index'] - (Required)
   * ['type'] - The name of the document type
   * ['timeout'] - (time) Explicit operation timeout.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index object.
   *
   * @return array
   *   Parameters required to create an index mapping.
   */
  public static function mapping(IndexInterface $index) {
    $properties = [
      'id' => [
        'type' => 'keyword',
      ],
    ];

    $properties['search_api_language'] = [
      'type' => 'keyword',
    ];

    $mappingParams = [
      'properties'        => $properties,
      'dynamic_templates' => [],
    ];

    // Allow other modules to alter index mapping before we create it.
    $dispatcher = \Drupal::service('event_dispatcher');
    $prepareIndexMappingEvent = new PrepareIndexMappingEvent($mappingParams, $index);
    $event = $dispatcher->dispatch(PrepareIndexMappingEvent::PREPARE_INDEX_MAPPING, $prepareIndexMappingEvent);
    $mappingParams = $event->getIndexMappingParams();

    return $mappingParams;
  }

  /**
   * Helper function. Get the elasticsearch mapping for a field.
   *
   * @param \Drupal\search_api\Item\FieldInterface $field
   *   Field.
   *
   * @return array|null
   *   Array of settings when a known field type is provided. Null otherwise.
   */
  public static function mappingFromField(FieldInterface $field) {
    $type          = $field->getType();
    $mappingConfig = NULL;

    switch ($type) {
      case 'text':
        $mappingConfig = [
          'type'   => 'text',
          'boost'  => $field->getBoost(),
          'fields' => [
            'keyword' => [
              'type'         => 'keyword',
              'ignore_above' => 256,
            ],
          ],
        ];
        break;

      case 'uri':
      case 'string':
      case 'token':
        $mappingConfig = [
          'type' => 'keyword',
        ];
        break;

      case 'integer':
      case 'duration':
        $mappingConfig = [
          'type' => 'integer',
        ];
        break;

      case 'boolean':
        $mappingConfig = [
          'type' => 'boolean',
        ];
        break;

      case 'decimal':
        $mappingConfig = [
          'type' => 'float',
        ];
        break;

      case 'date':
        $mappingConfig = [
          'type'   => 'date',
          'format' => 'strict_date_optional_time||epoch_second',
        ];
        break;

      case 'attachment':
        $mappingConfig = [
          'type' => 'attachment',
        ];
        break;

      case 'object':
        $mappingConfig = [
          'type' => 'object',
        ];
        break;

      case 'nested_object':
        $mappingConfig = [
          'type' => 'nested',
        ];
        break;
    }

    // Allow other modules to alter mapping config before we create it.
    $dispatcher          = \Drupal::service('event_dispatcher');
    $prepareMappingEvent = new PrepareMappingEvent($mappingConfig, $type, $field);
    $event               = $dispatcher->dispatch(PrepareMappingEvent::PREPARE_MAPPING, $prepareMappingEvent);
    $mappingConfig       = $event->getMappingConfig();

    return $mappingConfig;
  }

  /**
   * Helper function. Returns the Elasticsearch name of an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index object.
   *
   * @return string
   *   The name of the index on the Elasticsearch server. Includes a prefix for
   *   uniqueness, the database name, and index machine name.
   */
  public static function getIndexName(IndexInterface $index) {

    $options = \Drupal::database()->getConnectionOptions();
    $site_database = $options['database'];

    return strtolower(preg_replace(
      '/[^A-Za-z0-9_]+/',
      '',
      'elasticsearch_index_' . $site_database . '_' . $index->id()
    ));
  }

  /**
   * Helper function. Returns the elasticsearch value for a given field.
   *
   * @param string $field_type
   *   Field data type.
   * @param mixed $raw
   *   Field value.
   *
   * @return mixed
   *   Field value optionally casted to specific type.
   */
  protected static function getFieldValue($field_type, $raw) {
    $value = $raw;

    switch ($field_type) {
      case 'string':
        if (!is_array($raw)) {
          $value = (string) $raw;
        }
        break;

      case 'text':
        $value = $raw->toText();
        break;

      case 'boolean':
        $value = (boolean) $raw;
        break;

      case 'integer':
        $value = (integer) $raw;
        break;

      case 'decimal':
        $value = (float) $raw;
        break;
    }

    return $value;
  }

}
