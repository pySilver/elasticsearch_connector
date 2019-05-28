<?php

namespace Drupal\elasticsearch_connector\Event;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Elastica\Query;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class BuildSearchQueryEvent.
 *
 * @package Drupal\elasticsearch_connector\Event
 */
class BuildSearchQueryEvent extends Event {

  /**
   * Event name.
   */
  public const BUILD_QUERY = 'elasticsearch_connector.build_search_query';

  /**
   * Elastica search query.
   *
   * @var \Elastica\Query
   */
  protected $elasticQuery;

  /**
   * Search API query.
   *
   * @var \Drupal\search_api\Query\QueryInterface
   */
  protected $query;

  /**
   * Search API Index.
   *
   * @var \Drupal\search_api\IndexInterface
   */
  protected $index;

  /**
   * BuildSearchQueryEvent constructor.
   *
   * @param \Elastica\Query $elastic_query
   *   Elastica search query.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   Search API Query.
   * @param \Drupal\search_api\IndexInterface $index
   *   Index.
   */
  public function __construct(Query $elastic_query, QueryInterface $query, IndexInterface $index) {
    $this->elasticQuery = $elastic_query;
    $this->index = $index;
    $this->query = $query;
  }

  /**
   * Get index.
   *
   * @return \Drupal\search_api\IndexInterface
   *   Index.
   */
  public function getIndex(): IndexInterface {
    return $this->index;
  }

  /**
   * Get search query.
   *
   * @return \Elastica\Query
   *   Search query.
   */
  public function getElasticQuery(): Query {
    return $this->elasticQuery;
  }

  /**
   * Set search query.
   *
   * @param \Elastica\Query $elastic_query
   *   Search query.
   */
  public function setElasticQuery(Query $elastic_query): void {
    $this->elasticQuery = $elastic_query;
  }

  /**
   * Set search api query.
   *
   * @return \Drupal\search_api\Query\QueryInterface
   *   Search API Query.
   */
  public function getQuery(): QueryInterface {
    return $this->query;
  }

  /**
   * Get search api query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   Search API Query.
   */
  public function setQuery(QueryInterface $query): void {
    $this->query = $query;
  }

}
