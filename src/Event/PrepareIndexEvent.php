<?php

namespace Drupal\elasticsearch_connector\Event;

use Drupal\search_api\IndexInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class PrepareIndexEvent.
 *
 * @package Drupal\elasticsearch_connector\Event
 */
class PrepareIndexEvent extends Event {

  const PREPARE_INDEX = 'elasticsearch_connector.prepare_index';

  /**
   * Index Config.
   *
   * @var array
   */
  protected $indexConfig;

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
   * PrepareIndexEvent constructor.
   *
   * @param array $indexConfig
   *   Index Config.
   * @param string $indexName
   *   ElasticSearch Index Name.
   * @param \Drupal\search_api\IndexInterface $index
   *   Search API index.
   */
  public function __construct(array $indexConfig, $indexName, IndexInterface $index) {
    $this->indexConfig = $indexConfig;
    $this->indexName = $indexName;
    $this->index = $index;
  }

  /**
   * Getter for the index config array.
   *
   * @return array
   *   indexConfig
   */
  public function getIndexConfig() {
    return $this->indexConfig;
  }

  /**
   * Setter for the index config array.
   *
   * @param array $indexConfig
   *   Config.
   */
  public function setIndexConfig(array $indexConfig): void {
    $this->indexConfig = $indexConfig;
  }

  /**
   * Getter for the index name.
   *
   * @return string
   *   indexName
   */
  public function getIndexName(): string {
    return $this->indexName;
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
