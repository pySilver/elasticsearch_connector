<?php

namespace Drupal\elasticsearch_connector\Plugin\views\sort;

use Drupal\elasticsearch_connector\Plugin\views\ElasticsearchObjectHandlerTrait;
use Drupal\search_api\Plugin\views\SearchApiHandlerTrait;
use Drupal\views\Plugin\views\sort\SortPluginBase;

/**
 * Sort handler for elasticsearch objects fields.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsSort("elasticsearch_object")
 */
class ElasticsearchObject extends SortPluginBase {

  use SearchApiHandlerTrait;
  use ElasticsearchObjectHandlerTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->viewsHandlerManager = \Drupal::service('plugin.manager.views.sort');
  }

}
