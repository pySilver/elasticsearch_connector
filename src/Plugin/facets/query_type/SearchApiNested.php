<?php

namespace Drupal\elasticsearch_connector\Plugin\facets\query_type;

use Drupal\facets\QueryType\QueryTypePluginBase;
use Drupal\facets\Result\Result;
use Drupal\search_api\Query\QueryInterface;

/**
 * Provides support for nested facets within the Search API scope.
 *
 * This is the implementation that works with Elasticsearch backend.
 *
 * @FacetsQueryType(
 *   id = "search_api_nested",
 *   label = @Translation("Nested"),
 * )
 */
class SearchApiNested extends QueryTypePluginBase {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $query = $this->query;

    // Only alter the query when there's an actual query object to alter.
    if ($query !== NULL) {
      $faced_id = $this->getFacetId();

      if ($query->getProcessingLevel() === QueryInterface::PROCESSING_FULL) {
        // Set the options for the actual query.
        $options = &$query->getOptions();
        $options['search_api_facets'][$faced_id] = array_merge(
          $this->getFacetOptions(),
          ['facet' => $this->facet]
        );
      }

      // Filtering by active values happens in search backend.
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $query_operator = $this->facet->getQueryOperator();
    $results = $this->query->getResults();
    $facet_results = $results->getExtraData('search_api_facets');
    $this->results = $facet_results[$this->getFacetId()] ?? [];

    if (!empty($this->results)) {
      $facet_results = [];
      foreach ($this->results as $result) {
        if ($query_operator === 'or' || $result['count']) {
          $result_filter = $result['filter'];
          if ($result_filter[0] === '"') {
            $result_filter = substr($result_filter, 1);
          }
          if ($result_filter[strlen($result_filter) - 1] === '"') {
            $result_filter = substr($result_filter, 0, -1);
          }
          $count = $result['count'];
          $result = new Result($this->facet, $result_filter, $result_filter, $count);
          $facet_results[] = $result;
        }
      }
      $this->facet->setResults($facet_results);
    }
    return $this->facet;
  }

  /**
   * Generate nested facet id.
   *
   * @return string
   *   Facet ID.
   */
  protected function getFacetId(): string {
    $facet_options = $this->getFacetOptions();
    return sprintf(
      '%s.%s:%s',
      $facet_options['nested_path'],
      $facet_options['group_field_name'],
      $facet_options['group_field_value']
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getFacetOptions(): array {
    return array_merge(
      $this->facet->getProcessors()['nested_item']->getConfiguration(),
      parent::getFacetOptions()
    );
  }

}
