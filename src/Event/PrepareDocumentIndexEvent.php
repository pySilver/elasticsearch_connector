<?php

namespace Drupal\elasticsearch_connector\Event;

use Drupal\search_api\IndexInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class PrepareDocumentIndexEvent.
 *
 * @package Drupal\elasticsearch_connector\Event
 */
class PrepareDocumentIndexEvent extends Event {

  const PREPARE_DOCUMENT_INDEX = 'elasticsearch_connector.prepare_document_index';

  /**
   * Document to index.
   *
   * @var array
   */
  protected $document;

  /**
   * ElasticSearch Index Name.
   *
   * @var string
   */
  protected $indexName;

  /**
   * Search API Index.
   *
   * @var \Drupal\search_api\IndexInterface
   */
  protected $index;

  /**
   * PrepareDocumentIndexEvent constructor.
   *
   * @param array $document
   *   Documents to index.
   * @param string $indexName
   *   ElasticSearch Index Name.
   * @param \Drupal\search_api\IndexInterface $index
   *   Search API index.
   */
  public function __construct(array $document, $indexName, IndexInterface $index) {
    $this->document  = $document;
    $this->indexName = $indexName;
    $this->index     = $index;
  }

  /**
   * Getter for the document array.
   *
   * @return array
   *   document
   */
  public function getDocument() {
    return $this->document;
  }

  /**
   * Setter for the index config array.
   *
   * @param array $document
   *   Config.
   */
  public function setDocument(array $document): void {
    $this->document = $document;
  }

  /**
   * Getter for the index name.
   *
   * @return string
   *   indexName
   */
  public function getIndexName(): string {
    return $this->indexName;
  }

  /**
   * Getter for index.
   *
   * @return \Drupal\search_api\IndexInterface
   *   Index.
   */
  public function getIndex(): IndexInterface {
    return $this->index;
  }

}
