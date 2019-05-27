<?php

/**
 * @file
 * Views hook implementations for the Search API module.
 */

/**
 * Implements hook_search_api_views_field_handler_mapping_alter().
 *
 * Allow complex fields to be rendered.
 */
function elasticsearch_connector_search_api_views_field_handler_mapping_alter(array &$plain_mapping) {
  $plain_mapping['nested_object'] = $plain_mapping['object'] = [
    'id'     => 'elasticsearch_connector_markup',
    'format' => 'full_html',
  ];
}
