<?php

/**
 * @file
 * Views hook implementations for the Search API module.
 */

use Drupal\Component\Render\FormattableMarkup;
use Drupal\elasticsearch_connector\ElasticSearch\Parameters\Factory\IndexFactory;
use Drupal\search_api\Entity\Index;

/**
 * Implements hook_views_data().
 */
function elasticsearch_connector_views_views_data() {
  $data = array();
  $clientManager = \Drupal::service('elasticsearch_connector.client_manager');
  /** @var \Drupal\elasticsearch_connector\ClusterManager $cluster_manager */
  $cluster_manager = \Drupal::service('elasticsearch_connector.cluster_manager');

  foreach ($cluster_manager->loadAllClusters(FALSE) as $cluster) {
    $elasticsearchClient = $clientManager->getClientForCluster($cluster);
    if ($elasticsearchClient->isClusterOk()) {
      $indices = $elasticsearchClient->indices()->stats();
      // TODO: Handle aliases also, not only indices.
      if (!empty($indices['indices'])) {
        foreach ($indices['indices'] as $index_name => $index_info) {
          // In elasticsearch the table is actually the document type.
          // So get all types and build data array.
          $mapping = $elasticsearchClient->indices()->getMapping(
            array('index' => $index_name)
          );
          if (!empty($mapping[$index_name]['mappings'])) {
            foreach ($mapping[$index_name]['mappings'] as $type_name => $type_settings) {
              $name = new FormattableMarkup(
                '@cluster (@index_name - @type)', array(
                  '@cluster' => $cluster->name,
                  '@index_name' => $index_name,
                  '@type' => $type_name,
                )
              );
              $base_table = 'elsv__' . $cluster->cluster_id . '__' . $index_name . '__' . $type_name;

              $data[$base_table]['table']['group'] = t('Elasticsearch');
              $data[$base_table]['table']['base'] = array(
                'index' => $index_name,
                'cluster_id' => $cluster->cluster_id,
                'type' => $type_name,
                'title' => t('Cluster :name', array(':name' => $name)),
                'help' => t('Searches the site with the Elasticsearch search engine for !name', array('!name' => $name)),
                'query_id' => 'elasticsearch_connector_views_query',
              );

              // Get the list of the fields in index directly from Elasticsearch.
              if (!empty($type_settings['properties'])) {
                _elasticsearch_connector_views_handle_fields($base_table, $data, $type_settings['properties']);
              }

              // Keyword field.
              $data[$base_table]['keyword'] = array(
                'title' => t('Search'),
                'help' => t('Fulltext search'),
                'filter' => array(
                  'id' => 'elasticsearch_connector_views_fulltext_filter',
                ),
              );

              // Snippet field.
              $data[$base_table]['snippet'] = array(
                'title' => t('Snippet'),
                'help' => t('Search snippet'),
                'field' => array(
                  'handler' => 'elasticsearch_connector_views_snippet_handler_field',
                  'click sortable' => TRUE,
                ),
              );

              // Score field.
              $data[$base_table]['score'] = array(
                'title' => t('Score'),
                'help' => t('Score'),
                'field' => array(
                  'id' => 'elasticsearch_connector_views_standard',
                  'click sortable' => TRUE,
                ),
              );
            }
          }
        }
      }
    }
  }

  return $data;
}

/**
 * Handle the fields mapping and handle nested data types.
 *
 * @param string $base_table
 *   The base table value.
 * @param array $data
 *   Data array.
 * @param array $fields
 *   Fields array.
 * @param string $base_field_name
 *   Base field name.
 */
function _elasticsearch_connector_views_handle_fields($base_table, &$data, $fields, $base_field_name = '') {
  if (!empty($fields)) {
    foreach ($fields as $field_name => $field) {
      // TODO: Restrict some fields if needed.
      // TODO: Handle boolean.
      // TODO: Handle the cases with analyzed and not analyzed.
      if (empty($field['type']) && isset($field['properties'])) {
        $field_type = 'object';
      }
      else {
        $field_type = $field['type'];
      }

      $filter_handler = 'elasticsearch_connector_views_standard';
      $field_handler = 'elasticsearch_connector_views_standard';
      $set = TRUE;
      switch ($field_type) {
        case 'object':
          if (!empty($field['properties'])) {
            _elasticsearch_connector_views_handle_fields($base_table, $data, $field['properties'], $base_field_name . $field_name . '.');
          }
          $set = FALSE;
          break;

        case 'date':
          $filter_handler = 'elasticsearch_connector_views_date';
          $field_handler = 'elasticsearch_connector_views_date';
          break;

        case 'boolean':
          $filter_handler = 'elasticsearch_connector_views_boolean';
          $field_handler = 'elasticsearch_connector_views_boolean';
          break;

        case 'text':
        case 'string':
          // TODO: Handle the analyser and non_analyzed fields.
          // TODO: For analysed fields we need to do fulltext search.
          if (\Drupal::moduleHandler()
                     ->moduleExists('views_autocomplete_filters')
          ) {
            // TODO: Handle autocomplete.
            //$filter_handler = 'elasticsearch_connector_views_handler_filter_string_autocomplete';
          }
          else {
            $field_handler = 'elasticsearch_connector_views_markup';
            $filter_handler = 'elasticsearch_connector_views_string';
          }
          break;

        // Handle numeric filter type.
        case 'integer':
        case 'long':
        case 'float':
        case 'double':
          $filter_handler = 'elasticsearch_connector_views_numeric';
          $field_handler = 'elasticsearch_connector_views_numeric';
          break;
      }

      if ($set) {
        $key = str_replace('.', '__', $base_field_name . $field_name);
        $data[$base_table][$key] = array(
          'title' => $base_field_name . $field_name,
          'help' => $base_field_name . $field_name,
          'real field' => $base_field_name . $field_name,
          'field' => array(
            'id' => $field_handler,
            'click sortable' => TRUE,
          ),
          'filter' => array(
            'id' => $filter_handler,
          ),
          'sort' => array(
            'id' => 'standard',
          ),
          // TODO: Handle the argument class.
          'argument' => array(
            'id' => 'standard',
          ),
        );
        // N: "format" required by elasticsearch_connector_views_markup field handler. Make configurable?
        if ($field_handler === 'elasticsearch_connector_views_markup') {
          $data[$base_table][$key]['field']['format'] = 'full_html';
        }
      }
    }
  }
}


/**
 * Implements hook_search_api_views_field_handler_mapping_alter().
 *
 * Allow complex fields to be rendered.
 */
function elasticsearch_connector_search_api_views_field_handler_mapping_alter(array &$plain_mapping) {
  $plain_mapping['array'] = $plain_mapping['object'] = [
    'id' => 'elasticsearch_connector_views_markup',
    'format' => 'full_html',
  ];
}



/**
 * Implements hook_views_data_alter().
 *
 * Pushes flattened object fields into Search API Index.
 */
function elasticsearch_connector_views_views_data_alter(array &$data) {
  $search_api_mapping = _search_api_views_handler_mapping();

  /** @var \Drupal\search_api\IndexInterface $index */
  foreach (Index::loadMultiple() as $index) {
    $dependencies = $index->getDependencies()['module'];
    if (!in_array('elasticsearch_connector', $dependencies, TRUE)) {
      continue;
    }

    $index_name = 'search_api_index_' . $index->id();
    if (!isset($data[$index_name])) {
      continue;
    }

    $es_index       = IndexFactory::index($index, TRUE);
    $es_index_regex = sprintf('/elsv__[\w]+__%s__%s/', $es_index['index'], $es_index['type']);

    // Replace field handler for object fields.
    foreach ($data as $table_name => $table_fields) {
      if (!preg_match($es_index_regex, $table_name)) {
        continue;
      }

      foreach ($table_fields as $field_name => $field) {
        // Does not seem to be part of the object field.
        if ($field_name === 'table' ||
            !isset($field['real field']) ||
            strpos($field_name, '__') === FALSE ||
            strpos($field['real field'], '.') === FALSE
        ) {
          continue;
        }

        $object_name = explode('__', $field_name)[0];

        $type         = NULL;
        $new_field_id = 'search_api';
        switch ($field['filter']['id']) {
          case 'elasticsearch_connector_views_string':
            $type = 'string';
            break;

          case 'elasticsearch_connector_views_numeric':
            $type         = 'decimal';
            $new_field_id = 'search_api_numeric';
            break;

          case 'elasticsearch_connector_views_fulltext_filter':
            $type = 'text';
            break;

          case 'elasticsearch_connector_views_date':
            $type         = 'date';
            $new_field_id = 'search_api_date';
            break;

          case 'elasticsearch_connector_views_boolean':
            $type         = 'boolean';
            $new_field_id = 'search_api_boolean';
            break;
        }

        $new_field          = [];
        $new_field['field'] = [];
        $new_field['title'] = $field['title'];
        $new_field['help']  = t(
          'Property of @obj object field.', ['@obj' => $object_name]
        );

        $new_field['real field'] = $field['real field'];
        $new_field['group']      = t(
          'Index @name', ['@name' => $index->label()]
        );

        $new_field['field']['id']               = $new_field_id;
        $new_field['field']['title']            = $new_field['title'];
        $new_field['field']['real field']       = $new_field['real field'];
        $new_field['field']['search_api field'] = $field_name;

        if (isset($search_api_mapping[$type])) {
          $new_field += $search_api_mapping[$type];
          $new_field['filter']['allow empty'] = TRUE;
        }

        // Inject field into search_api table:
        $data[$index_name][$field_name] = $new_field;

        // Update description for root-object field:
        $data[$index_name][$object_name]['help'] = t(
          'Complex @obj field.', ['@obj' => $object_name]
        );
      }
    }
  }
}
