<?php

namespace Drupal\elasticsearch_connector\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\elasticsearch_connector\Elasticsearch\ClusterManager;
use Drupal\elasticsearch_connector\Elasticsearch\ClientManager;
use Drupal\elasticsearch_connector\Entity\Cluster;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityStorageException;

/**
 * Provides a form for the Cluster entity.
 */
class ClusterForm extends EntityForm {

  /**
   * Client manager.
   *
   * @var \Drupal\elasticsearch_connector\Elasticsearch\ClientManager
   */
  private $clientManager;

  /**
   * The cluster manager service.
   *
   * @var \Drupal\elasticsearch_connector\Elasticsearch\ClusterManager
   */
  protected $clusterManager;

  /**
   * ElasticsearchController constructor.
   *
   * @param \Drupal\elasticsearch_connector\Elasticsearch\ClientManager $client_manager
   *   The client manager.
   * @param \Drupal\elasticsearch_connector\Elasticsearch\ClusterManager $cluster_manager
   *   The cluster manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   */
  public function __construct(ClientManager $client_manager, ClusterManager $cluster_manager, MessengerInterface $messenger) {
    $this->clientManager = $client_manager;
    $this->clusterManager = $cluster_manager;
    $this->setMessenger($messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('elasticsearch_connector.client_manager'),
      $container->get('elasticsearch_connector.cluster_manager'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    if ($form_state->isRebuilding()) {
      $this->entity = $this->buildEntity($form, $form_state);
    }
    $form = parent::form($form, $form_state);
    if ($this->entity->isNew()) {
      $form['#title'] = $this->t('Add Elasticsearch Cluster');
    }
    else {
      $form['#title'] = $this->t(
        'Edit Elasticsearch Cluster @label',
        ['@label' => $this->entity->label()]
      );
    }

    $this->buildEntityForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntityForm(array &$form, FormStateInterface $form_state) {
    $form['cluster'] = [
      '#type'  => 'value',
      '#value' => $this->entity,
    ];

    $form['name'] = [
      '#type'          => 'textfield',
      '#title'         => t('Administrative cluster name'),
      '#default_value' => empty($this->entity->name) ? '' : $this->entity->name,
      '#description'   => t(
        'Enter the administrative cluster name that will be your Elasticsearch cluster unique identifier.'
      ),
      '#required'      => TRUE,
      '#weight'        => 1,
    ];

    $form['cluster_id'] = [
      '#type'          => 'machine_name',
      '#title'         => t('Cluster id'),
      '#default_value' => !empty($this->entity->cluster_id) ? $this->entity->cluster_id : '',
      '#maxlength'     => 125,
      '#description'   => t(
        'A unique machine-readable name for this Elasticsearch cluster.'
      ),
      '#machine_name'  => [
        'exists' => ['Drupal\elasticsearch_connector\Entity\Cluster', 'load'],
        'source' => ['name'],
      ],
      '#required'      => TRUE,
      '#disabled'      => !empty($this->entity->cluster_id),
      '#weight'        => 2,
    ];

    $form['url'] = [
      '#type'          => 'url',
      '#title'         => t('Server URL'),
      '#default_value' => !empty($this->entity->url) ? $this->entity->url : '',
      '#description'   => t(
        'URL and port of a server (node) in the cluster. ' .
        'Please, always enter the port even if it is default one. ' .
        'Nodes will be automatically discovered. ' .
        'Examples: http://localhost:9200 or https://localhost:443.'
      ),
      '#required'      => TRUE,
      '#weight'        => 3,
    ];

    $form['status_info'] = $this->clusterFormInfo();

    $default = $this->clusterManager->getDefaultCluster();
    $form['default'] = [
      '#type'          => 'checkbox',
      '#title'         => t('Make this cluster default connection'),
      '#description'   => t(
        'If the cluster connection is not specified the API will use the default connection.'
      ),
      '#default_value' => (empty($default) || (!empty($this->entity->cluster_id) && $this->entity->cluster_id == $default)) ? '1' : '0',
      '#weight'        => 4,
    ];

    $form['options'] = [
      '#tree'   => TRUE,
      '#weight' => 5,
    ];

    $form['options']['multiple_nodes_connection'] = [
      '#type'          => 'checkbox',
      '#title'         => t('Use multiple nodes connection'),
      '#description'   => t(
        'Automatically discover all nodes and use them in the cluster connection. ' .
        'Then the Elasticsearch client can distribute the query execution on random base between nodes.'
      ),
      '#default_value' => !empty($this->entity->options['multiple_nodes_connection']) ? 1 : 0,
      '#weight'        => 5.1,
    ];

    $form['status'] = [
      '#type'          => 'radios',
      '#title'         => t('Status'),
      '#default_value' => $this->entity->status ?? Cluster::ELASTICSEARCH_CONNECTOR_STATUS_ACTIVE,
      '#options'       => [
        Cluster::ELASTICSEARCH_CONNECTOR_STATUS_ACTIVE   => t('Active'),
        Cluster::ELASTICSEARCH_CONNECTOR_STATUS_INACTIVE => t('Inactive'),
      ],
      '#required'      => TRUE,
      '#weight'        => 6,
    ];

    $form['options']['use_authentication'] = [
      '#type'          => 'checkbox',
      '#title'         => t('Use authentication'),
      '#description'   => t(
        'Use HTTP authentication method to connect to Elasticsearch.'
      ),
      '#default_value' => !empty($this->entity->options['use_authentication']) ? 1 : 0,
      '#suffix'        => '<div id="hosting-iframe-container">&nbsp;</div>',
      '#weight'        => 5.2,
    ];

    $form['options']['username'] = [
      '#type'          => 'textfield',
      '#title'         => t('Username'),
      '#description'   => t('The username for authentication.'),
      '#default_value' => !empty($this->entity->options['username']) ? $this->entity->options['username'] : '',
      '#states'        => [
        'visible' => [
          ':input[name="options[use_authentication]"]' => ['checked' => TRUE],
        ],
      ],
      '#weight'        => 5.4,
    ];

    $form['options']['password'] = [
      '#type'          => 'textfield',
      '#title'         => t('Password'),
      '#description'   => t('The password for authentication.'),
      '#default_value' => !empty($this->entity->options['password']) ? $this->entity->options['password'] : '',
      '#states'        => [
        'visible' => [
          ':input[name="options[use_authentication]"]' => ['checked' => TRUE],
        ],
      ],
      '#weight'        => 5.5,
    ];

    $form['options']['timeout'] = [
      '#type'          => 'number',
      '#title'         => t('Connection timeout'),
      '#size'          => 20,
      '#required'      => TRUE,
      '#description'   => t(
        'After how many seconds the connection should timeout if there is no connection to Elasticsearch.'
      ),
      '#default_value' => !empty($this->entity->options['timeout']) ? $this->entity->options['timeout'] : Cluster::ELASTICSEARCH_CONNECTOR_DEFAULT_TIMEOUT,
      '#weight'        => 5.6,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $values = $form_state->getValues();

    // Set default cluster.
    $default = $this->clusterManager->getDefaultCluster();
    if (empty($default) && !$values['default']) {
      $this->clusterManager->setDefaultCluster($values['cluster_id']);
      $default = $this->clusterManager->getDefaultCluster();
    }
    elseif ($values['default']) {
      $this->clusterManager->setDefaultCluster($values['cluster_id']);
      $default = $this->clusterManager->getDefaultCluster();
    }

    if (!empty($default) && $values['default'] === 0 && $default === $values['cluster_id']) {
      $this->messenger->addMessage(
        t(
          'There must be a default connection. %name is still the default
          connection. Please change the default setting on the cluster you wish
          to set as default.',
          [
            '%name' => $values['name'],
          ]
        ),
        'warning'
      );
    }
  }

  /**
   * Build the cluster info table for the edit page.
   *
   * @return array
   */
  protected function clusterFormInfo(): array {
    $element = [];

    if (isset($this->entity->url)) {
      try {
        $client = $this->clientManager->getClient($this->entity);
        if ($client->hasConnection()) {
          $health = $client->getCluster()->getHealth()->getData();
          $headers = [
            ['data' => t('Cluster name')],
            ['data' => t('Status')],
            ['data' => t('Number of nodes')],
          ];

          $rows = [
            [
              $health['cluster_name'],
              $health['status'],
              $health['number_of_nodes'],
            ],
          ];

          $element = [
            '#theme'      => 'table',
            '#header'     => $headers,
            '#rows'       => $rows,
            '#attributes' => [
              'class' => ['admin-elasticsearch'],
              'id'    => 'cluster-info',
            ],
          ];

        }
        else {
          $element['#type'] = 'markup';
          $element['#markup'] = '<div id="cluster-info">&nbsp;</div>';
        }
      }
      catch (\Exception $e) {
        $this->messenger->addMessage($e->getMessage(), 'error');
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    // Only save the server if the form doesn't need to be rebuilt.
    if (!$form_state->isRebuilding()) {
      try {
        parent::save($form, $form_state);
        $this->messenger->addMessage(t('Cluster %label has been updated.', ['%label' => $this->entity->label()]));
        $form_state->setRedirect('elasticsearch_connector.config_entity.list');
      }
      catch (EntityStorageException $e) {
        $form_state->setRebuild();
        watchdog_exception('elasticsearch_connector', $e);
        $this->messenger->addMessage(
          $this->t('The cluster could not be saved.'),
          'error'
        );
      }
    }
  }

}
