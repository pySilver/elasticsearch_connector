<?php

namespace Drupal\elasticsearch_connector\Event;

use Symfony\Component\EventDispatcher\Event;

/**
 * Class BuildSearchParamsEvent.
 *
 * @package Drupal\elasticsearch_connector\Event
 */
class BuildSearchParamsEvent extends Event {

  /**
   * Event name.
   */
  public const BUILD_QUERY = 'elasticsearch_connector.build_searchparams';

  /**
   * Search params.
   *
   * @var array
   */
  protected $params;

  /**
   * Index name.
   *
   * @var string
   */
  protected $indexName;

  /**
   * BuildSearchParamsEvent constructor.
   *
   * @param array $params
   *   Search params.
   * @param string $indexName
   *   Index name.
   */
  public function __construct(array $params, string $indexName) {
    $this->params = $params;
    $this->indexName = $indexName;
  }

  /**
   * Getter for the params config array.
   *
   * @return array
   *   Search params.
   */
  public function getElasticSearchParams(): array {
    return $this->params;
  }

  /**
   * Setter for the params config array.
   *
   * @param array $params
   *   Search params.
   */
  public function setElasticSearchParams(array $params): void {
    $this->params = $params;
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
