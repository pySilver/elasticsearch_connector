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

  /**
   * Event name.
   */
  public const PREPARE_INDEX_MAPPING = 'elasticsearch_connector.prepare_index_mapping';

  /**
   * Index Mapping.
   *
   * @var array
   */
  protected $indexMappingParams;

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
   *   Index mapping params.
   * @param \Drupal\search_api\IndexInterface $index
   *   Index.
   */
  public function __construct(array $indexMappingParams, IndexInterface $index) {
    $this->indexMappingParams = $indexMappingParams;
    $this->index = $index;
  }

  /**
   * Getter for the index params array.
   *
   * @return array
   *   Index Mapping Params.
   */
  public function getIndexMappingParams(): array {
    return $this->indexMappingParams;
  }

  /**
   * Setter for the index params array.
   *
   * @param array $indexMappingParams
   *   Index mapping params.
   */
  public function setIndexMappingParams(array $indexMappingParams): void {
    $this->indexMappingParams = $indexMappingParams;
  }

  /**
   * Getter for index.
   *
   * @return \Drupal\search_api\IndexInterface
   *   Index.
   */
  public function getIndex(): IndexInterface {
    return $this->index;
  }

}
