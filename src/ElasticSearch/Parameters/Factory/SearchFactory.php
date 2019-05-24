<?php

namespace Drupal\elasticsearch_connector\ElasticSearch\Parameters\Factory;

use Drupal\elasticsearch_connector\ElasticSearch\Parameters\Builder\SearchBuilder;
use Drupal\elasticsearch_connector\Utility\Utility;
use Drupal\search_api\Query\QueryInterface;

/**
 * Class SearchFactory.
 */
class SearchFactory {

  /**
   * Build search parameters from a query interface.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   Search API query object.
   *
   * @return array
   *   Array of parameters to send along to the Elasticsearch _search endpoint.
   */
  public static function search(QueryInterface $query) {
    $builder = new SearchBuilder($query);

    return $builder->build();
  }

  /**
   * Parse a Elasticsearch response into a ResultSetInterface.
   *
   * TODO: Add excerpt handling.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   Search API query.
   * @param array $response
   *   Raw response array back from Elasticsearch.
   *
   * @return \Drupal\search_api\Query\ResultSetInterface
   *   The results of the search.
   */
  public static function parseResult(QueryInterface $query, array $response) {
    $index = $query->getIndex();
    $fields = $index->getFields();

    // Set up the results array.
    $results = $query->getResults();
    $results->setExtraData('elasticsearch_response', $response);
    $results->setResultCount($response['hits']['total']);
    /** @var \Drupal\search_api\Utility\FieldsHelper $fields_helper */
    $fields_helper = \Drupal::getContainer()->get('search_api.fields_helper');

    // Add each search result to the results array.
    if (!empty($response['hits']['hits'])) {
      foreach ($response['hits']['hits'] as $result) {
        $result_item = $fields_helper->createItem($index, $result['_id']);
        $result_item->setScore($result['_score']);

        // Nested objects needs to be unwrapped before passing into fields.
        $flatten_result = Utility::dot($result['_source'], '', '__');
        foreach ($flatten_result as $result_key => $result_value) {
          if (isset($fields[$result_key])) {
            $field = clone $fields[$result_key];
          }
          else {
            $field = $fields_helper->createField($index, $result_key);
          }
          $field->setValues((array) $result_value);
          $result_item->setField($result_key, $field);
        }

        // Preserve complex fields defined in index as unwrapped.
        foreach ($result['_source'] as $result_key => $result_value) {
          if (
            isset($fields[$result_key]) &&
            in_array($fields[$result_key]->getType(), [
              'object',
              'nested_object',
            ])
          ) {
            $field = clone $fields[$result_key];
            $field->setValues((array) $result_value);
            $result_item->setField($result_key, $field);
          }
        }

        $results->addResultItem($result_item);
      }
    }

    return $results;
  }

}
