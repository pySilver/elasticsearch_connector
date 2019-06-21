<?php

namespace Drupal\elasticsearch_connector\Plugin\views\field;

use Drupal\elasticsearch_connector\Plugin\views\ElasticsearchObjectHandlerTrait;
use Drupal\search_api\Plugin\views\SearchApiHandlerTrait;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler for elasticsearch objects fields.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("elasticsearch_object")
 */
class ElasticsearchObject extends FieldPluginBase {

  use SearchApiHandlerTrait;
  use ElasticsearchObjectHandlerTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->viewsHandlerManager = \Drupal::service('plugin.manager.views.field');
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    if ($this->realHandler !== NULL) {
      return $this->realHandler->render($values);
    }
    return parent::render($values);
  }

  /**
   * {@inheritdoc}
   */
  public function advancedRender(ResultRow $values) {
    if ($this->realHandler !== NULL) {
      $property_path = $this->realHandler->getCombinedPropertyPath();
      $field = $this->options['field'];
      $property = $this->options['property_select']['property'];

      // Field value passed via undocumented property `extraData`.
      // @see: \Drupal\elasticsearch_connector\Plugin\search_api\backend\SearchApiElasticsearchBackend::parseResult
      $values->$property_path = $values->_item->getFields()[$field]->extraData['value'][$property];

      return $this->realHandler->advancedRender($values);
    }
    return parent::advancedRender($values);
  }

}
