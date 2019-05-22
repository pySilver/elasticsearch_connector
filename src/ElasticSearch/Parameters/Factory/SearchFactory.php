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

        // Set each item in _source as a field in Search API.
        foreach ($result['_source'] as $elasticsearch_property_id => $elasticsearch_property) {
          $is_assoc_property = is_array($elasticsearch_property) && Utility::isArrayAssoc($elasticsearch_property);

          // Unwrap objects to flat fields.
          $index_field = $index->getField($elasticsearch_property_id);
          if ($is_assoc_property && $index_field !== NULL && $index_field->getType() === 'object') {
            $flatten_fields = Utility::flattenArray($elasticsearch_property, $elasticsearch_property_id, '.');
            foreach ($flatten_fields as $flatten_field_key => $flatten_field_value) {
              $flat_field = $fields_helper->createField($index, $flatten_field_key, ['property_path' => $flatten_field_key]);

              if (is_scalar($flatten_field_value) || (is_array($flatten_field_value) && Utility::isArrayAssoc($flatten_field_value))) {
                $flat_field->addValue($flatten_field_value);
              }
              else {
                $flat_field->setValues((array) $flatten_field_value);
              }

              $result_item->setField($flatten_field_key, $flat_field);
            }
          }

          $field = $fields_helper->createField($index, $elasticsearch_property_id, ['property_path' => $elasticsearch_property_id]);
          if ($is_assoc_property) {
            $field->addValue($elasticsearch_property);
          }
          else {
            $field->setValues((array) $elasticsearch_property);
          }

          $result_item->setField($elasticsearch_property_id, $field);
        }

        $results->addResultItem($result_item);
      }
    }

    return $results;
  }

}
