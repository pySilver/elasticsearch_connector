<?php

namespace Drupal\elasticsearch_connector\Event;

use Drupal\search_api\IndexInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class PrepareIndexMappingEvent.
 *
 * @package Drupal\elasticsearch_connector\Event
 */
class PrepareIndexMappingEvent extends Event {

  const PREPARE_INDEX_MAPPING = 'elasticsearch_connector.prepare_index_mapping';

  /**
   * Index Mapping.
   *
   * @var array
   */
  protected $indexMappingParams;

  /**
   * ElasticSearch Index Name.
   *
   * @var string
   */
  protected $indexName;

  /**
   * Search API Index.
   *
   * @var \Drupal\search_api\IndexInterface
   */
  protected $index;

  /**
   * PrepareIndexMappingEvent constructor.
   *
   * @param array $indexMappingParams
   * @param string $indexName
   * @param \Drupal\search_api\IndexInterface $index
   */
  public function __construct(array $indexMappingParams, $indexName, IndexInterface $index) {
    $this->indexMappingParams = $indexMappingParams;
    $this->indexName          = $indexName;
    $this->index              = $index;
  }

  /**
   * Getter for the index params array.
   *
   * @return array indexMappingParams
   */
  public function getIndexMappingParams() {
    return $this->indexMappingParams;
  }

  /**
   * Setter for the index params array.
   *
   * @param array $indexMappingParams
   */
  public function setIndexMappingParams(array $indexMappingParams): void {
    $this->indexMappingParams = $indexMappingParams;
  }

  /**
   * Getter for the index name.
   *
   * @return string indexName
   */
  public function getIndexName(): string {
    return $this->indexName;
  }

  /**
   * Getter for index.
   *
   * @return \Drupal\search_api\IndexInterface
   */
  public function getIndex(): IndexInterface {
    return $this->index;
  }

}
