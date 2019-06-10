<?php

namespace Drupal\elasticsearch_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\elasticsearch_connector\Elasticsearch\ClientManager;
use Drupal\elasticsearch_connector\Entity\Cluster;
use Elastica\Exception\ConnectionException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides route responses for elasticsearch clusters.
 */
class ElasticsearchController extends ControllerBase {

  /**
   * Elasticsearch client manager service.
   *
   * @var \Drupal\elasticsearch_connector\Elasticsearch\ClientManager
   */
  private $clientManager;

  /**
   * ElasticsearchController constructor.
   *
   * @param \Drupal\elasticsearch_connector\Elasticsearch\ClientManager $client_manager
   *   Elasticsearch client manager service.
   */
  public function __construct(ClientManager $client_manager) {
    $this->clientManager = $client_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('elasticsearch_connector.client_manager')
    );
  }

  /**
   * Displays information about an Elasticsearch Cluster.
   *
   * @param \Drupal\elasticsearch_connector\Entity\Cluster $elasticsearch_cluster
   *   An instance of Cluster.
   *
   * @return array
   *   An array suitable for drupal_render().
   */
  public function page(Cluster $elasticsearch_cluster): array {
    // Build the Search API index information.
    $render = [
      'view' => [
        '#theme'   => 'elasticsearch_cluster',
        '#cluster' => $elasticsearch_cluster,
      ],
    ];
    // Check if the cluster is enabled and can be written to.
    if ($elasticsearch_cluster->cluster_id) {
      $render['form'] = $this->formBuilder()->getForm(
        'Drupal\elasticsearch_connector\Form\ClusterForm',
        $elasticsearch_cluster
      );
    }

    return $render;
  }

  /**
   * Page title callback for a cluster's "View" tab.
   *
   * @param \Drupal\elasticsearch_connector\Entity\Cluster $elasticsearch_cluster
   *   The cluster that is displayed.
   *
   * @return string|null
   *   The page title.
   */
  public function pageTitle(Cluster $elasticsearch_cluster): ?string {
    // TODO: Check if we need string escaping.
    return $elasticsearch_cluster->label();
  }

  /**
   * Complete information about the Elasticsearch Client.
   *
   * @param \Drupal\elasticsearch_connector\Entity\Cluster $elasticsearch_cluster
   *   Elasticsearch cluster.
   *
   * @return array
   *   Render array.
   */
  public function getInfo(Cluster $elasticsearch_cluster): array {
    $client = $this->clientManager->getClient($elasticsearch_cluster);

    $total_docs = 0;
    $total_size = 0;
    $plugin_rows = [];
    $node_rows = [];
    $cluster_statistics_rows = [];
    $cluster_health_rows = [];

    try {
      if ($client->hasConnection()) {
        // Nodes.
        $nodes = $client->getCluster()->getNodes();
        foreach ($nodes as $node_id => $node) {
          $node_info = $node->getInfo()->getData();
          $node_stats = $node->getStats()->getData();

          $row = [];
          $row[] = ['data' => $node->getName()];
          $row[] = ['data' => $node_stats['indices']['docs']['count']];
          $row[] = [
            'data' => format_size(
              $node_stats['indices']['store']['size_in_bytes']
            ),
          ];
          $total_docs += $node_stats['indices']['docs']['count'];
          $total_size += $node_stats['indices']['store']['size_in_bytes'];
          $node_rows[] = $row;

          foreach ($node_info['plugins'] as $plugin) {
            $row = [];
            $row[] = ['data' => $plugin['name']];
            $row[] = ['data' => $plugin['version']];
            $row[] = ['data' => $plugin['description']];
            $row[] = ['data' => $node->getName()];
            $plugin_rows[] = $row;
          }
        }

        // Cluster:
        $health = $client->getCluster()->getHealth()->getData();
        $state = $client->getCluster()->getState();
        $cluster_statistics_rows = [
          [
            [
              'data' => $health['number_of_nodes'] . ' ' . t('Nodes'),
            ],
            [
              'data' => $health['active_shards'] + $health['unassigned_shards'] . ' ' . t('Total Shards'),
            ],
            [
              'data' => $health['active_shards'] . ' ' . t('Successful Shards'),
            ],
            [
              'data' => count($state['metadata']['indices']) . ' ' . t('Indices'),
            ],
            ['data' => $total_docs . ' ' . t('Total Documents')],
            ['data' => format_size($total_size) . ' ' . t('Total Size')],
          ],
        ];

        $cluster_health_rows = [];
        $cluster_health_mapping = [
          'cluster_name'                     => t('Cluster name'),
          'status'                           => t('Status'),
          'timed_out'                        => t('Time out'),
          'number_of_nodes'                  => t('Number of nodes'),
          'number_of_data_nodes'             => t('Number of data nodes'),
          'active_primary_shards'            => t('Active primary shards'),
          'active_shards'                    => t('Active shards'),
          'relocating_shards'                => t('Relocating shards'),
          'initializing_shards'              => t('Initializing shards'),
          'unassigned_shards'                => t('Unassigned shards'),
          'delayed_unassigned_shards'        => t('Delayed unassigned shards'),
          'number_of_pending_tasks'          => t('Number of pending tasks'),
          'number_of_in_flight_fetch'        => t('Number of in-flight fetch'),
          'task_max_waiting_in_queue_millis' => t(
            'Task max waiting in queue millis'
          ),
          'active_shards_percent_as_number'  => t(
            'Active shards percent as number'
          ),
        ];

        foreach ($health as $health_key => $health_value) {
          if (!isset($cluster_health_mapping[$health_key])) {
            continue;
          }

          $row = [];
          $row[] = ['data' => $cluster_health_mapping[$health_key]];
          $row[] = ['data' => $health_value === FALSE ? 'False' : $health_value];
          $cluster_health_rows[] = $row;
        }
      }
    }
    catch (ConnectionException $e) {
      $this->messenger()->addError(
        $this->t('Elasticsearch connection failed due to: @error', [
          '@error' => $e->getMessage(),
        ])
      );
    }

    $output['cluster_statistics_wrapper'] = [
      '#type'        => 'fieldset',
      '#title'       => t('Cluster statistics'),
      '#collapsible' => TRUE,
      '#collapsed'   => FALSE,
      '#attributes'  => [],
    ];

    $output['cluster_statistics_wrapper']['nodes'] = [
      '#theme'      => 'table',
      '#header'     => [
        ['data' => t('Node name')],
        ['data' => t('Documents')],
        ['data' => t('Size')],
      ],
      '#rows'       => $node_rows,
      '#attributes' => [],
    ];

    $output['cluster_statistics_wrapper']['cluster_statistics'] = [
      '#theme'      => 'table',
      '#header'     => [
        ['data' => t('Total'), 'colspan' => 6],
      ],
      '#rows'       => $cluster_statistics_rows,
      '#attributes' => ['class' => ['admin-elasticsearch-statistics']],
    ];

    $output['cluster_statistics_wrapper']['cluster_plugins'] = [
      '#theme'      => 'table',
      '#header'     => [
        ['data' => t('Plugin name')],
        ['data' => t('Plugin Version')],
        ['data' => t('Plugin Description')],
        ['data' => t('Node')],
      ],
      '#rows'       => $plugin_rows,
      '#attributes' => [],
    ];

    $output['cluster_health'] = [
      '#theme'      => 'table',
      '#header'     => [
        ['data' => t('Cluster Health'), 'colspan' => 2],
      ],
      '#rows'       => $cluster_health_rows,
      '#attributes' => ['class' => ['admin-elasticsearch-health']],
    ];

    return $output;
  }

}
