<?php

namespace Drupal\elasticsearch_connector\Plugin\search_api\processor;

use Drupal\elasticsearch_connector\Event\DummyFieldValueEvent;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds dummy field to the index.
 *
 * @SearchApiProcessor(
 *   id = "dummy_field",
 *   label = @Translation("Dummy field"),
 *   description = @Translation("Add dummy field that receive its value from event callback."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class DummyField extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label'        => $this->t('Dummy field'),
        'description'  => $this->t('A dummy field that receive its value from event callback.'),
        'type'         => 'string',
        'processor_id' => $this->getPluginId(),
      ];

      $properties['dummy_field'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $dispatcher = \Drupal::service('event_dispatcher');
    $fields     = $this->getFieldsHelper()->filterForPropertyPath(
      $item->getFields(),
      NULL,
      'dummy_field'
    );
    foreach ($fields as $field) {
      $event = new DummyFieldValueEvent(
        $this->getIndex(),
        $item,
        $field
      );

      /** @var \Drupal\elasticsearch_connector\Event\DummyFieldValueEvent $event */
      $event = $dispatcher->dispatch(DummyFieldValueEvent::GET_FIELD_VALUE, $event);
      $value = $event->getValue();
      if (is_array($value)) {
        foreach ($value as $val) {
          $field->addValue($val);
        }
      }
      else {
        $field->addValue($value);
      }
    }
  }

}
