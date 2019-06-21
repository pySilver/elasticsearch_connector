<?php

namespace Drupal\elasticsearch_connector\Elasticsearch;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\elasticsearch_connector\Entity\Cluster;
use Elastica\Client;

/**
 * Class ClientManager.
 */
class ClientManager {

  /**
   * Array of clients keyed by cluster URL.
   *
   * @var \Elastica\Client[]
   */
  protected $clients = [];

  /**
   * Module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|null
   */
  private $logger;

  /**
   * ClientManager constructor.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface|null $logger
   *   Logger.
   */
  public function __construct(ModuleHandlerInterface $moduleHandler, LoggerChannelInterface $logger = NULL) {
    $this->moduleHandler = $moduleHandler;
    $this->logger        = $logger;
  }

  /**
   * Returns Elasticsearch client.
   *
   * @param \Drupal\elasticsearch_connector\Entity\Cluster $cluster
   *   Cluster to connect.
   *
   * @return \Elastica\Client
   *   Instance of Elasticsearch client.
   */
  public function getClient(Cluster $cluster): Client {

    $url = rtrim($cluster->url, '/') . '/';
    if (!isset($this->clients[$url])) {
      $timeout = !empty($cluster->options['timeout']) ?
        (int) $cluster->options['timeout'] :
        Cluster::ELASTICSEARCH_CONNECTOR_DEFAULT_TIMEOUT;

      $options = [
        'url'       => $url,
        'transport' => 'Guzzle',
        'timeout'   => $timeout,
      ];

      if ($cluster->options['use_authentication']) {
        $options['username'] = $cluster->options['username'];
        $options['password'] = $cluster->options['password'];
      }

      $this->moduleHandler->alter(
        'elasticsearch_connector_load_library_options',
        $options,
        $cluster
      );

      // $this->clients[$url] = new Client($options, NULL, $this->logger);
      // Skip logger until severity is properly filtered.
      $this->clients[$url] = new Client($options, NULL);
    }

    return $this->clients[$url];
  }

}
