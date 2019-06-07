<?php

/**
 * @file
 * Hooks provided by the Elasticsearch Connector module.
 */

use Drupal\elasticsearch_connector\Entity\Cluster;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Elastica\Query;

/**
 * Modify the connector library options.
 *
 * @param array $options
 *   Library options.
 * @param \Drupal\elasticsearch_connector\Entity\Cluster $cluster
 *   Cluster entity.
 */
function hook_elasticsearch_connector_load_library_options_alter(array &$options, Cluster $cluster) {
}

/**
 * Modify Search API query.
 *
 * @param \Drupal\search_api\Query\QueryInterface $query
 *   Query object.
 */
function hook_elasticsearch_connector_search_api_query(QueryInterface $query) {
}

/**
 * Modify elasticsearch query.
 *
 * @param \Elastica\Query $elastic_query
 *   Elasticsearch query object.
 * @param \Drupal\search_api\Query\QueryInterface $query
 *   Query object.
 */
function hook_elasticsearch_connector_elastic_search_query(Query $elastic_query, QueryInterface $query) {
}

/**
 * Modify search results.
 *
 * @param \Drupal\search_api\Query\ResultSetInterface $results
 *   Parsed search results.
 * @param \Drupal\search_api\Query\QueryInterface $query
 *   Query.
 * @param object $response
 *   Response object.
 */
function hook_elasticsearch_connector_search_results(ResultSetInterface $results, QueryInterface $query, $response) {
}

/**
 * Modify random search params.
 *
 * @param array $random_sort_params
 *   Sorting params.
 */
function hook_elasticsearch_connector_search_api_random_sort(array $random_sort_params) {
}
