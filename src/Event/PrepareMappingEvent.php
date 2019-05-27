<?php

namespace Drupal\elasticsearch_connector\Event;

use Drupal\search_api\Item\FieldInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class PrepareMappingEvent.
 *
 * @package Drupal\elasticsearch_connector\Event
 */
class PrepareMappingEvent extends Event {

  /**
   * Event name.
   */
  public const PREPARE_MAPPING = 'elasticsearch_connector.prepare_mapping';

  /**
   * Field mapping.
   *
   * @var array
   */
  protected $mappingConfig;

  /**
   * Field type.
   *
   * @var string
   */
  protected $type;

  /**
   * Field.
   *
   * @var \Drupal\search_api\Item\FieldInterface
   */
  protected $field;

  /**
   * PrepareMappingEvent constructor.
   *
   * @param array $mappingConfig
   *   Mapping.
   * @param string $type
   *   Field type.
   * @param \Drupal\search_api\Item\FieldInterface $field
   *   Field instance.
   */
  public function __construct(array $mappingConfig, string $type, FieldInterface $field) {
    $this->mappingConfig = $mappingConfig;
    $this->type = $type;
    $this->field = $field;
  }

  /**
   * Getter for the mapping config array.
   *
   * @return array
   *   Mapping.
   */
  public function getMappingConfig(): array {
    return $this->mappingConfig;
  }

  /**
   * Setter for the mapping config array.
   *
   * @param array $mappingConfig
   *   New mapping.
   */
  public function setMappingConfig(array $mappingConfig): void {
    $this->mappingConfig = $mappingConfig;
  }

  /**
   * Getter for the field type.
   *
   * @return string
   *   Field type.
   */
  public function getFieldType(): string {
    return $this->type;
  }

  /**
   * Getter for the field.
   *
   * @return \Drupal\search_api\Item\FieldInterface
   *   Field.
   */
  public function getMappingField(): FieldInterface {
    return $this->field;
  }

}
