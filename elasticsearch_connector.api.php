<?php

/**
 * @file
 * Hooks provided by the Elasticsearch Connector module.
 */

use Drupal\elasticsearch_connector\Entity\Cluster;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;

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
