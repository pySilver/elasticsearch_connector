<?php

namespace Drupal\elasticsearch_connector\Plugin\search_api\processor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;

/**
 * Adds Nested entities as Objects in elasticsearch document
 *
 * The entity reference fields must be configured to be of type Object.
 *
 * @SearchApiProcessor(
 *   id = "nested_entity",
 *   label = @Translation("Nested Entity processor"),
 *   description = @Translation("For related entities linked as Object type, will nest the loaded entity properties on the related field."),
 *   stages = {
 *     "alter_items" = 0,
 *   }
 * )
 */
class NestedEntity extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    // Support all content entities for now. We may need to limit this later.
    $interface = ContentEntityInterface::class;
    foreach ($index->getDatasources() as $datasource) {
      $entity_type_id = $datasource->getEntityTypeId();
      if (!$entity_type_id) {
        continue;
      }
      // We support users and any entities that implement
      // \Drupal\Core\Entity\ContentEntityInterface.
      $entity_type = \Drupal::entityTypeManager()
        ->getDefinition($entity_type_id);
      if ($entity_type && $entity_type->entityClassImplements($interface)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterIndexedItems(array &$items) {

    /** @var \Drupal\search_api\Item\ItemInterface $item */
    foreach ($items as $item_id => $item) {
      $entity = $item->getOriginalObject()->getValue();

      // Get the entity reference fields, and loop over them.
      $entity_fields = $this->getRelatedEntitiesProperties($entity->getEntityTypeId());
      $fields = $item->getFields();
      foreach ($entity_fields as $entity_field) {
        $nested_values = [];

        // Make sure it exists.
        if (empty($fields[$entity_field['property']])) {
         continue;
        }
        $field = $fields[$entity_field['property']];

        // Handle nested entity lists as single entity relationships.
        $nested_entities = \Drupal::entityTypeManager()
          ->getStorage($entity_field['type'])
          ->loadMultiple($field->getValues());

        foreach ($nested_entities as $entity) {
          $nested_values[] = $this->exportEntity($entity);
        }

        $field->setValues($nested_values);
      }
    }
  }

  /**
   * Returns return entity references for the entity type.
   *
   * @param string $entity_type_id
   *   The entity type id.
   *
   * @return
   *    An array of entity references on the supplied entity including:
   *    - property
   *    - type
   */
  private function getRelatedEntitiesProperties($entity_type_id) {
    $related_entity_properties = [];

    // Get the Entity Field and Bundle managers.
    $field_manager = \Drupal::service('entity_field.manager');
    $entity_type_bundle_info = \Drupal::service('entity_type.bundle.info');

    // Loop through all bundles to ensure we have all properties.
    $properties = $field_manager->getBaseFieldDefinitions($entity_type_id);
    $bundles = $entity_type_bundle_info->getBundleInfo($entity_type_id);
    foreach ($bundles as $bundle => $info) {
      $properties += $field_manager->getFieldDefinitions($entity_type_id, $bundle);
    }

    // Determine which properties are entity references.
    foreach ($properties as $name => $property) {
      if ($property->getType() !== 'entity_reference') {
        continue;
      }

      $related_entity_properties[] = [
        'property' => $name,
        'type' => $property->getSetting('target_type'),
      ];
    }

    return $related_entity_properties;
  }

  /**
   * Returns the entity as an array suitable for Elasticsearch.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *
   * @return array
   */
  protected function exportEntity($entity) {
    $values = [];

    if (empty($entity) || !method_exists($entity, 'getFields')) {
      return $values;
    }

    foreach ($entity->getFields() as $name => $property) {
      $field_type = $property->getFieldDefinition()->getType();
      $raw = $entity->get($name)->value;
      switch ($field_type) {
        case 'integer':
        case 'timestamp':
        case 'changed':
        case 'created':
          $value = (int) $raw;
          break;

        case 'float':
        case 'decimal':
          $value = (float) $raw;
          break;

        case 'boolean':
          $value = (boolean) $raw;
          break;

        default:
          $value = $raw;
      }
      $values[$name] = $value;
    }
    return $values;
  }
}
