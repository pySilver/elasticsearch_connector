<?php

namespace Drupal\elasticsearch_connector\Event;

use Symfony\Component\EventDispatcher\Event;

/**
 * Class PrepareSearchQueryEvent.
 *
 * @package Drupal\elasticsearch_connector\Event
 */
class PrepareSearchQueryEvent extends Event {

  /**
   * Event name.
   */
  public const PREPARE_QUERY = 'elasticsearch_connector.prepare_searchquery';

  /**
   * Search query.
   *
   * @var array
   */
  protected $elasticSearchQuery;

  /**
   * Index name.
   *
   * @var string
   */
  protected $indexName;

  /**
   * PrepareSearchQueryEvent constructor.
   *
   * @param array $elasticSearchQuery
   *   Search query.
   * @param string $indexName
   *   Index name.
   */
  public function __construct(array $elasticSearchQuery, string $indexName) {
    $this->elasticSearchQuery = $elasticSearchQuery;
    $this->indexName = $indexName;
  }

  /**
   * Getter for the elasticSearchQuery config array.
   *
   * @return array
   *   Search query
   */
  public function getElasticSearchQuery(): array {
    return $this->elasticSearchQuery;
  }

  /**
   * Setter for the elasticSearchQuery config array.
   *
   * @param array $elasticSearchQuery
   *   Search query.
   */
  public function setElasticSearchQuery(array $elasticSearchQuery): void {
    $this->elasticSearchQuery = $elasticSearchQuery;
  }

  /**
   * Getter for the index name.
   *
   * @return string
   *   Index name.
   */
  public function getIndexName(): string {
    return $this->indexName;
  }

}
