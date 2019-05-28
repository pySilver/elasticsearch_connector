<?php

namespace Drupal\elasticsearch_connector\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\elasticsearch_connector\Elasticsearch\ClientManager;
use Drupal\Core\Url;
use Elastica\Exception\NotFoundException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a confirmation form for deletion of a custom menu.
 */
class IndexDeleteForm extends EntityConfirmFormBase {

  /**
   * Client manager service.
   *
   * @var \Drupal\elasticsearch_connector\Elasticsearch\ClientManager
   */
  private $clientManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * ElasticsearchController constructor.
   *
   * @param \Drupal\elasticsearch_connector\Elasticsearch\ClientManager $client_manager
   *   The client manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   */
  public function __construct(ClientManager $client_manager, EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger) {
    $this->clientManager = $client_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->setMessenger($messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('elasticsearch_connector.client_manager'),
      $container->get('entity_type.manager'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the index %title?', array('%title' => $this->entity->label()));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\elasticsearch_connector\Entity\Cluster $cluster */
    $cluster = $this->entityTypeManager->getStorage('elasticsearch_cluster')->load($this->entity->server);
    $client = $this->clientManager->getClient($cluster);
    try {
      if ($client->getIndex($this->entity->index_id)->exists()) {
        $client->getIndex($this->entity->index_id)->delete();
      }
      $this->entity->delete();
      $this->messenger->addMessage($this->t('The index %title has been deleted.', array('%title' => $this->entity->label())));
      $form_state->setRedirect('elasticsearch_connector.config_entity.list');
    }
    catch (NotFoundException $e) {
      // The index was not found, so just remove it anyway.
      $this->messenger->addMessage($e->getMessage(), 'error');
    }
    catch (\Exception $e) {
      $this->messenger->addMessage($e->getMessage(), 'error');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('elasticsearch_connector.config_entity.list');
  }

}
