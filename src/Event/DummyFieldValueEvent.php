<?php

namespace Drupal\elasticsearch_connector\Event;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Item\ItemInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class DummyFieldValueEvent.
 *
 * @package Drupal\elasticsearch_connector\Event
 */
class DummyFieldValueEvent extends Event {

  /**
   * Event name.
   */
  public const GET_FIELD_VALUE = 'elasticsearch_connector.dummy_field_value';

  /**
   * Search API Index.
   *
   * @var \Drupal\search_api\Entity\Index
   */
  protected $index;

  /**
   * Indexable document.
   *
   * @var \Drupal\search_api\Item\ItemInterface
   */
  protected $document;

  /**
   * Field.
   *
   * @var \Drupal\search_api\Item\FieldInterface
   */
  protected $field;

  /**
   * Field value.
   *
   * @var array
   */
  protected $value;

  /**
   * DummyFieldValue constructor.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Search API Index object.
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   Indexable document.
   * @param \Drupal\search_api\Item\FieldInterface $field
   *   Indexable field.
   */
  public function __construct(IndexInterface $index, ItemInterface $item, FieldInterface $field) {
    $this->index    = $index;
    $this->document = $item;
    $this->field    = $field;
    $this->value    = NULL;
  }

  /**
   * Value setter.
   *
   * @param mixed $val
   *   Field value.
   */
  public function setValue($val): void {
    $this->value = $val;
  }

  /**
   * Returns field value.
   *
   * @return mixed
   *   Field value.
   */
  public function getValue() {
    return $this->value;
  }

  /**
   * Returns SearchAPI Index.
   *
   * @return \Drupal\search_api\Entity\Index
   *   Search API Index.
   */
  public function getIndex(): Index {
    return $this->index;
  }

  /**
   * Returns field.
   *
   * @return \Drupal\search_api\Item\FieldInterface
   *   Field.
   */
  public function getField(): FieldInterface {
    return $this->field;
  }

  /**
   * Returns document.
   *
   * @return \Drupal\search_api\Item\ItemInterface
   *   Document being indexed..
   */
  public function getDocument(): ItemInterface {
    return $this->document;
  }

}
