<?php

namespace Drupal\elasticsearch_connector\Plugin\search_api\backend;

use Drupal\Component\Utility\DiffArray;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\Config;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\elasticsearch_connector\Elasticsearch\ClusterManager;
use Drupal\elasticsearch_connector\Elasticsearch\ClientManager;
use Drupal\elasticsearch_connector\Elasticsearch\SearchBuilder;
use Drupal\elasticsearch_connector\Elasticsearch\IndexHelper;
use Drupal\elasticsearch_connector\Event\BuildSearchQueryEvent;
use Drupal\elasticsearch_connector\Utility\Utility;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSet;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_autocomplete\SearchApiAutocompleteSearchInterface;
use Drupal\search_api_autocomplete\SearchInterface;
use Drupal\search_api_autocomplete\Suggestion\SuggestionFactory;
use Elastica\Exception\ConnectionException;
use Elastica\Exception\ResponseException;
use Elastica\Response;
use Elastica\Search;
use Elastica\Type;
use Elastica\Type\Mapping;
use Elastica\ResultSet as ElasticResultSet;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api\Plugin\PluginFormTrait;

/**
 * Elasticsearch Search API Backend definition.
 *
 * TODO: Check for dependencies and remove them in order to properly test the
 * code.
 *
 * @SearchApiBackend(
 *   id = "elasticsearch",
 *   label = @Translation("Elasticsearch"),
 *   description = @Translation("Index items using an Elasticsearch server.")
 * )
 */
class SearchApiElasticsearchBackend extends BackendPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  /**
   * Set a large integer to be the size for a "Hard limit" value of "No limit".
   */
  const FACET_NO_LIMIT_SIZE = 10000;

  /**
   * Auto fuzziness setting.
   *
   * Auto fuzziness in Elasticsearch means we don't specify a specific
   * Levenshtein distance, falling back to auto behavior. Fuzziness, including
   * auto fuzziness, is defined in the Elasticsearch documentation here:
   *
   * @see https://www.elastic.co/guide/en/elasticsearch/reference/5.6/common-options.html#fuzziness
   */
  const FUZZINESS_AUTO = 'auto';

  /**
   * Elasticsearch settings.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $elasticsearchSettings;

  /**
   * Cluster id.
   *
   * @var int
   */
  protected $clusterId;

  /**
   * Cluster object.
   *
   * @var \Drupal\elasticsearch_connector\Entity\Cluster
   */
  protected $cluster;

  /**
   * Elasticsearch client.
   *
   * @var \Elastica\Client
   */
  protected $client;

  /**
   * Form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Client manager service.
   *
   * @var \Drupal\elasticsearch_connector\Elasticsearch\ClientManager
   */
  protected $clientManager;

  /**
   * The cluster manager service.
   *
   * @var \Drupal\elasticsearch_connector\Elasticsearch\ClusterManager
   */
  protected $clusterManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Elasticsearch index factory.
   *
   * @var \Drupal\elasticsearch_connector\Elasticsearch\IndexHelper
   */
  protected $indexFactory;

  /**
   * SearchApiElasticsearchBackend constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   Form builder service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   Module handler service.
   * @param \Drupal\elasticsearch_connector\Elasticsearch\ClientManager $client_manager
   *   Client manager service.
   * @param \Drupal\Core\Config\Config $elasticsearch_settings
   *   Elasticsearch settings object.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger.
   * @param \Drupal\elasticsearch_connector\Elasticsearch\ClusterManager $cluster_manager
   *   The cluster manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\elasticsearch_connector\Elasticsearch\IndexHelper $indexFactory
   *   Index factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    FormBuilderInterface $form_builder,
    ModuleHandlerInterface $module_handler,
    ClientManager $client_manager,
    Config $elasticsearch_settings,
    LoggerInterface $logger,
    ClusterManager $cluster_manager,
    EntityTypeManagerInterface $entity_type_manager,
    IndexHelper $indexFactory,
    MessengerInterface $messenger
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->formBuilder = $form_builder;
    $this->moduleHandler = $module_handler;
    $this->clientManager = $client_manager;
    $this->logger = $logger;
    $this->elasticsearchSettings = $elasticsearch_settings;
    $this->clusterManager = $cluster_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->indexFactory = $indexFactory;
    $this->setMessenger($messenger);

    if (empty($this->configuration['cluster_settings']['cluster'])) {
      $this->configuration['cluster_settings']['cluster'] = $this->clusterManager->getDefaultCluster();
    }

    $this->cluster = $this
      ->entityTypeManager
      ->getStorage('elasticsearch_cluster')
      ->load($this->configuration['cluster_settings']['cluster']);

    if (!isset($this->cluster)) {
      throw new SearchApiException($this->t('Cannot load the Elasticsearch cluster for your index.'));
    }

    $this->client = $this->clientManager->getClient($this->cluster);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder'),
      $container->get('module_handler'),
      $container->get('elasticsearch_connector.client_manager'),
      $container->get('config.factory')->get('elasticsearch.settings'),
      $container->get('logger.channel.elasticsearch'),
      $container->get('elasticsearch_connector.cluster_manager'),
      $container->get('entity_type.manager'),
      $container->get('elasticsearch_connector.index_helper'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'cluster_settings'          => [
        'cluster' => '',
      ],
      'scheme'                    => 'http',
      'host'                      => 'localhost',
      'port'                      => '9200',
      'path'                      => '',
      'excerpt'                   => FALSE,
      'retrieve_data'             => FALSE,
      'highlight_data'            => FALSE,
      'http_method'               => 'AUTO',
      'autocorrect_spell'         => TRUE,
      'autocorrect_suggest_words' => TRUE,
      'fuzziness'                 => self::FUZZINESS_AUTO,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    if (!$this->server->isNew()) {
      $server_link = $this->cluster->getSafeUrl();
      // Editing this server.
      $form['server_description'] = [
        '#type'        => 'item',
        '#title'       => $this->t('Elasticsearch Cluster'),
        '#description' => Link::fromTextAndUrl($server_link, Url::fromUri($server_link)),
      ];
    }
    $form['cluster_settings'] = [
      '#type'  => 'fieldset',
      '#title' => t('Elasticsearch settings'),
    ];

    // We are not displaying disabled clusters.
    $clusters = $this->clusterManager->loadAllClusters(FALSE);
    $options = [];
    foreach ($clusters as $key => $cluster) {
      $options[$key] = $cluster->cluster_id;
    }

    $options[$this->clusterManager->getDefaultCluster()] = t('Default cluster: @name', ['@name' => $this->clusterManager->getDefaultCluster()]);
    $form['cluster_settings']['cluster'] = [
      '#type'          => 'select',
      '#title'         => t('Cluster'),
      '#required'      => TRUE,
      '#options'       => $options,
      '#default_value' => $this->configuration['cluster_settings']['cluster'] ?: '',
      '#description'   => t('Select the cluster you want to handle the connections.'),
    ];

    $fuzziness_options = [
      ''                   => $this->t('- Disabled -'),
      self::FUZZINESS_AUTO => self::FUZZINESS_AUTO,
    ];
    $fuzziness_options += array_combine(range(0, 5), range(0, 5));
    $form['fuzziness'] = [
      '#type'          => 'select',
      '#title'         => t('Fuzziness'),
      '#required'      => TRUE,
      '#options'       => $fuzziness_options,
      '#default_value' => $this->configuration['fuzziness'],
      '#description'   => $this->t('Some queries and APIs support parameters to allow inexact fuzzy matching, using the fuzziness parameter. See <a href="https://www.elastic.co/guide/en/elasticsearch/reference/5.6/common-options.html#fuzziness">Fuzziness</a> for more information.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    // First, check the features we always support.
    return [
      'search_api_autocomplete',
      'search_api_granular',
      'search_api_facets',
      'search_api_facets_operator_or',
      'search_api_mlt',
      'search_api_random_sort',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type) {
    return in_array($type, ['object', 'nested_object']);
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings() {
    $info = [];

    $server_link = $this->cluster->getSafeUrl();
    $info[] = [
      'label' => $this->t('Elasticsearch server URI'),
      'info'  => Link::fromTextAndUrl($server_link, Url::fromUri($server_link)),
    ];

    if ($this->server->status()) {
      // If the server is enabled, check whether Elasticsearch can be reached.
      $ping = $this->client->hasConnection();
      if ($ping) {
        $msg = $this->t('The Elasticsearch server could be reached');
      }
      else {
        $msg = $this->t('The Elasticsearch server could not be reached. Further data is therefore unavailable.');
      }
      $info[] = [
        'label'  => $this->t('Connection'),
        'info'   => $msg,
        'status' => $ping ? 'ok' : 'error',
      ];
    }

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function getDiscouragedProcessors() {
    return [
      'ignorecase',
      'snowball_stemmer',
      'stemmer',
      'stopwords',
      'tokenizer',
      'transliteration',
    ];
  }

  /**
   * Get the configured cluster; if the cluster is blank, use the default.
   *
   * @return string
   *   The name of the configured cluster.
   */
  public function getCluster() {
    return $this->configuration['cluster_settings']['cluster'];
  }

  /**
   * Get the configured fuzziness value.
   *
   * @return string
   *   The configured fuzziness value.
   */
  public function getFuzziness(): string {
    return $this->configuration['fuzziness'];
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    $params = $this->indexFactory::index($index);
    $elastic_index = $this->client->getIndex($params['index']);

    try {
      // Delete index if already exists:
      if ($elastic_index->exists()) {
        $elastic_index->delete();
      }// Adds index:
      $response = $elastic_index->create($this->indexFactory::create($index));
      if (!$response->isOk()) {
        $this->messenger->addMessage($this->t(
          'Failed to create index. Elasticsearch response: @error',
          ['@error' => $response->getErrorMessage()]
        ), 'error');
        return;
      }

      // Adds mapping:
      $type = $elastic_index->getType($params['type']);
      $response = $this->createMapping($type, $index);
      if (!$response->isOk()) {
        $this->messenger->addMessage($this->t(
          'Failed to create index mapping. Elasticsearch response: @error',
          ['@error' => $response->getErrorMessage()]
        ), 'error');
      }
    }
    catch (ResponseException | ConnectionException $e) {
      $this->messenger->addMessage($e->getMessage(), 'error');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    if ($this->hasMappingChanges($index) || $this->hasSettingsChanged($index)) {
      // Reinstall index & mapping, then schedule full reindex.
      $this->addIndex($index);
      $index->reindex();
    }
  }

  /**
   * Create mapping.
   *
   * @param \Elastica\Type $type
   *   Type.
   * @param \Drupal\search_api\IndexInterface $index
   *   Index.
   *
   * @return \Elastica\Response
   *   Response object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function createMapping(Type $type, IndexInterface $index): Response {
    $mappingParams = $this->indexFactory::mapping($index);

    $mapping = new Mapping($type, $mappingParams['properties']);
    if (isset($mappingParams['dynamic_templates']) && !empty($mappingParams['dynamic_templates'])) {
      $mapping->setParam('dynamic_templates', $mappingParams['dynamic_templates']);
    }

    return $mapping->send();
  }

  /**
   * Checks whether basic index settings has changed.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index.
   *
   * @return bool
   *   Test result.
   */
  public function hasSettingsChanged(IndexInterface $index): bool {
    $params = $this->indexFactory::index($index);
    $new_settings = $index->getThirdPartySettings('elasticsearch_connector');
    $settings = $this->client->getIndex($params['index'])->getSettings();

    if ($new_settings['index']['number_of_shards'] !== $settings->getNumberOfShards()) {
      return TRUE;
    }

    if ($new_settings['index']['number_of_replicas'] !== $settings->getNumberOfReplicas()) {
      return TRUE;
    }

    $new_refresh_interval = $new_settings['index']['refresh_interval'] . 's';
    return $new_refresh_interval !== $settings->getRefreshInterval();
  }

  /**
   * Check if the index has mapping differences.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index for which to update fields.
   *
   * @return bool
   *   TRUE if changes, FALSE otherwise.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function hasMappingChanges(IndexInterface $index): bool {
    $params = $this->indexFactory::index($index);

    // Get the new mapping settings.
    $new_mapping = $this->indexFactory::mapping($index);

    // Get the current mapping settings.
    $current_mapping = $this
      ->client
      ->getIndex($params['index'])
      ->getType($params['type'])
      ->getMapping();

    // Get diff on both sides.
    $diff_1 = DiffArray::diffAssocRecursive(
      $new_mapping,
      $current_mapping[$params['type']]['properties']
    );

    $diff_2 = DiffArray::diffAssocRecursive(
      $current_mapping[$params['type']]['properties'],
      $new_mapping
    );

    return !empty($diff_1) || !empty($diff_2);
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    $params = $this->indexFactory::index($index);

    try {
      if ($this->client->getIndex($params['index'])->exists()) {
        $this->client->getIndex($params['index'])->delete();
      }
    }
    catch (ResponseException | ConnectionException $e) {
      $this->messenger->addMessage($e->getMessage(), 'error');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    if (empty($items)) {
      return [];
    }

    $params = $this->indexFactory::index($index);
    try {
      $type = $this->client->getIndex($params['index'])
        ->getType($params['type']);
      if (!$type->exists()) {
        $this->messenger->addMessage(
          $this->t('Failed to index documents. Mapping type does not exist.'),
          'error'
        );
        return [];
      }
    }
    catch (ResponseException | ConnectionException $e) {
      $this->messenger->addMessage($e->getMessage(), 'error');
      return [];
    }

    try {
      $response = $type->addDocuments(
        $this->indexFactory::bulkIndex($index, $items)
      );
      $this->client->getIndex($params['index'])->refresh();

      // If there were any errors, log them and throw an exception.
      if ($response->hasError()) {
        foreach ($response->getBulkResponses() as $bulkResponse) {
          if ($bulkResponse->hasError()) {
            $this->logger->error($bulkResponse->getError());
          }
        }
        throw new SearchApiException($this->t('An error occurred during indexing. Check your watchdog for more information.'));
      }
    }
    catch (ResponseException | ConnectionException $e) {
      $this->messenger->addMessage($e->getMessage(), 'error');
    }

    return array_keys($items);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $this->removeIndex($index);
    $this->addIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $ids) {
    if (!count($ids)) {
      return;
    }

    try {
      $this->client->bulk(
        $this->indexFactory::bulkDelete($index, $ids)
      );
    }
    catch (ResponseException | ConnectionException $e) {
      $this->messenger->addMessage($e->getMessage(), 'error');
    }
  }

  /**
   * Implements SearchApiAutocompleteInterface::getAutocompleteSuggestions().
   *
   * Note that the interface is not directly implemented to avoid a dependency
   * on search_api_autocomplete module.
   */
  public function getAutocompleteSuggestions(QueryInterface $query, SearchInterface $search, $incomplete_key, $user_input) {
    try {
      $fields = $this->getQueryFulltextFields($query);
      if (count($fields) > 1) {
        throw new \LogicException('Elasticsearch requires a single fulltext field for use with autocompletion! Please adjust your configuration.');
      }
      $query->setOption('autocomplete', $incomplete_key);
      $query->setOption('autocomplete_field', reset($fields));

      // Disable facets so it does not collide with autocompletion results.
      $query->setOption('search_api_facets', FALSE);

      $result = $this->search($query);
      $query->postExecute($result);

      // Parse suggestions out of the response.
      $suggestions = [];
      $factory = new SuggestionFactory($user_input);

      $response = $result->getExtraData('elasticsearch_response');
      if (isset($response['aggregations']['autocomplete']['buckets'])) {
        $suffix_start = strlen($user_input);
        $buckets = $response['aggregations']['autocomplete']['buckets'];
        foreach ($buckets as $bucket) {
          $suggestions[] = $factory->createFromSuggestionSuffix(substr($bucket['key'], $suffix_start), $bucket['doc_count']);
        }
      }
      return $suggestions;
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    // Results.
    $search_result = $query->getResults();


    // Add the facets to the request.
    //    if ($query->getOption('search_api_facets')) {
    //      $this->addFacets($query);
    //    }

    // Build Elasticsearch query.

    // Note that this requires fielddata option to be enabled.
    // @see ::getAutocompleteSuggestions()
    // @see \Drupal\elasticsearch_connector\Elasticsearch\IndexHelper::mapping()
    //    if ($incomplete_key = $query->getOption('autocomplete')) {
    //      // Autocomplete suggestions are determined using a term aggregation (like
    //      // facets), but filtered to only include facets with the right prefix.
    //      // As the search facet field is analyzed, facets are tokenized terms and
    //      // all in lower case. To match that, we need convert the our filter to
    //      // lower case also.
    //      $incomplete_key = strtolower($incomplete_key);
    //      // Note that we cannot use the elasticsearch client aggregations API as
    //      // it does not support the "include" parameter.
    //      $params['body']['aggs']['autocomplete']['terms'] = [
    //        'field'   => $query->getOption('autocomplete_field'),
    //        'include' => $incomplete_key . '.*',
    //        'size'    => $query->getOption('limit'),
    //      ];
    //    }

    try {
      // Allow modules to alter the Elastic Search query.
      $this->moduleHandler->alter('elasticsearch_connector_search_api_query', $query);
      $this->preQuery($query);

      // Build Elasticsearch query.
      $builder = new SearchBuilder($query);
      $builder->build();
      $elastic_query = $builder->getElasticQuery();

      // Allow other modules to alter search query before we use it.
      $this->moduleHandler->alter('elasticsearch_connector_elastic_search_query', $elastic_query, $query);
      $dispatcher = \Drupal::service('event_dispatcher');
      $prepareSearchQueryEvent = new BuildSearchQueryEvent($elastic_query, $query, $query->getIndex());
      $event = $dispatcher->dispatch(BuildSearchQueryEvent::BUILD_QUERY, $prepareSearchQueryEvent);
      $elastic_query = $event->getElasticQuery();


      // Execute search.
      $params = IndexHelper::index($query->getIndex());
      $search = new Search($this->client);
      $search->addIndex($params['index'])->addType($params['type']);
      $result_set = $search->search($elastic_query);
      $results = self::parseResult($query, $result_set);

      // Handle the facets result when enabled.
      //      if ($query->getOption('search_api_facets')) {
      //        $this->parseFacets($results, $query);
      //      }

      // Allow modules to alter the Elastic Search Results.
      $this->moduleHandler->alter('elasticsearch_connector_search_results', $results, $query, $result_set);
      $this->postQuery($results, $query, $result_set);

      return $results;
    }
    catch (\Exception $e) {
      watchdog_exception('Elasticsearch API', $e);
      $this->messenger->addError($this->t('Search request failed.'));
      return $search_result;
    }
  }

  /**
   * Parse a Elasticsearch response into a ResultSetInterface.
   *
   * TODO: Add excerpt handling.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   Search API query.
   * @param \Elastica\ResultSet $result_set
   *   ResultSet.
   *
   * @return \Drupal\search_api\Query\ResultSetInterface
   *   The results of the search.
   */
  public static function parseResult(QueryInterface $query, ElasticResultSet $result_set) {
    $index = $query->getIndex();
    $fields = $index->getFields();

    // Set up the results array.
    $results = $query->getResults();
    $results->setExtraData(
      'elasticsearch_response',
      $result_set->getResponse()->getData()
    );
    $results->setResultCount($result_set->getTotalHits());

    /** @var \Drupal\search_api\Utility\FieldsHelper $fields_helper */
    $fields_helper = \Drupal::getContainer()->get('search_api.fields_helper');

    foreach ($result_set->getResults() as $result) {
      $result = $result->getHit();
      $result_item = $fields_helper->createItem($index, $result['_id']);
      $result_item->setScore($result['_score']);

      // Nested objects needs to be unwrapped before passing into fields.
      $flatten_result = Utility::dot($result['_source'], '', '__');
      foreach ($flatten_result as $result_key => $result_value) {
        if (isset($fields[$result_key])) {
          $field = clone $fields[$result_key];
        }
        else {
          $field = $fields_helper->createField($index, $result_key);
        }
        $field->setValues((array) $result_value);
        $result_item->setField($result_key, $field);
      }

      // Preserve complex fields defined in index as unwrapped.
      foreach ($result['_source'] as $result_key => $result_value) {
        if (
          isset($fields[$result_key]) &&
          in_array($fields[$result_key]->getType(), [
            'object',
            'nested_object',
          ])
        ) {
          $field = clone $fields[$result_key];
          $field->setValues((array) $result_value);
          $result_item->setField($result_key, $field);
        }
      }

      $results->addResultItem($result_item);
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    return $this->client->hasConnection();
  }

  /**
   * Fill the aggregation array of the request.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   Search API query.
   */
  protected function addFacets(QueryInterface $query) {
    foreach ($query->getOption('search_api_facets') as $key => $facet) {
      $facet += ['type' => NULL];

      $object = NULL;

      // @todo Add more options.
      switch ($facet['type']) {
        case 'stats':
          $object = new Stats($key, $key);
          break;

        default:
          $object = new Terms($key, $key);

          // Limit the number of facets in the result according the to facet
          // setting. A limit of 0 means no limit. Elasticsearch doesn't have a
          // way to set no limit, so we set a large integer in that case.
          $size = $facet['limit'] ? $facet['limit'] : self::FACET_NO_LIMIT_SIZE;
          $object->setSize($size);

          // Set global scope for facets with 'OR' operator.
          if ($facet['operator'] == 'or') {
            $object->setGlobalScope(TRUE);
          }
      }

      if (!empty($object)) {
        $this->client->aggregations()->setAggregation($object);
      }
    }
  }

  /**
   * Parse the result set and add the facet values.
   *
   * @param \Drupal\search_api\Query\ResultSet $results
   *   Result set, all items matched in a search.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   Search API query object.
   */
  protected function parseFacets(ResultSet $results, QueryInterface $query) {
    $response = $results->getExtraData('elasticsearch_response');
    $facets = $query->getOption('search_api_facets');

    // Create an empty array that will be attached to the result object.
    $attach = [];

    foreach ($facets as $key => $facet) {
      $terms = [];

      // Handle 'and' operator.
      if ($facet['operator'] === 'and' || ($facet['operator'] === 'or' && !isset($response['aggregations'][$key . '_global']))) {
        if (!empty($facet['type']) && $facet['type'] === 'stats') {
          $terms = $response['aggregations'][$key];
        }
        else {
          $buckets = $response['aggregations'][$key]['buckets'];
          array_walk($buckets, static function ($value) use (&$terms, $facet) {
            if ($value['doc_count'] >= $facet['min_count']) {
              $terms[] = [
                'count'  => $value['doc_count'],
                'filter' => '"' . $value['key'] . '"',
              ];
            }
          });
        }
      }
      elseif ($facet['operator'] === 'or') {
        if (!empty($facet['type']) && $facet['type'] === 'stats') {
          $terms = $response['aggregations'][$key . '_global'];
        }
        else {
          $buckets = $response['aggregations'][$key . '_global'][$key]['buckets'];
          array_walk($buckets, static function ($value) use (&$terms, $facet) {
            if ($value['doc_count'] >= $facet['min_count']) {
              $terms[] = [
                'count'  => $value['doc_count'],
                'filter' => '"' . $value['key'] . '"',
              ];
            }
          });
        }
      }
      $attach[$key] = $terms;
    }

    $results->setExtraData('search_api_facets', $attach);
  }

  /**
   * Prefixes an index ID as configured.
   *
   * The resulting ID will be a concatenation of the following strings:
   * - If set, the "elasticsearch.settings.index_prefix" configuration.
   * - If set, the index-specific "elasticsearch.settings.index_prefix_INDEX"
   *   configuration.
   * - The index's machine name.
   *
   * @param string $machine_name
   *   The index's machine name.
   *
   * @return string
   *   The prefixed machine name.
   */
  protected function getIndexId($machine_name) {
    // Prepend per-index prefix.
    $id = $this->elasticsearchSettings->get('index_prefix_' . $machine_name) . $machine_name;
    // Prepend environment prefix.
    $id = $this->elasticsearchSettings->get('index_prefix') . $id;
    return $id;
  }

  /**
   * Helper function. Return date gap from two dates or timestamps.
   *
   * @param mixed $min
   *   Start date or timestamp.
   * @param mixed $max
   *   End date or timestamp.
   * @param bool $timestamp
   *   TRUE if the first two params are timestamps, FALSE otherwise. In the case
   *   of FALSE, it's assumed the first two arguments are strings and they are
   *   converted to timestamps using strtotime().
   *
   * @return string
   *   One of 'NONE', 'YEAR', 'MONTH', or 'DAY' depending on the difference
   *
   * @see facetapi_get_timestamp_gap()
   */
  protected static function getDateGap($min, $max, $timestamp = TRUE) {
    if ($timestamp !== TRUE) {
      $min = strtotime($min);
      $max = strtotime($max);
    }

    if (empty($min) || empty($max)) {
      return 'DAY';
    }

    $diff = $max - $min;

    switch (TRUE) {
      case ($diff > 86400 * 365):
        return 'NONE';

      case ($diff > 86400 * gmdate('t', $min)):
        return 'YEAR';

      case ($diff > 86400):
        return 'MONTH';

      default:
        return 'DAY';
    }
  }

  /**
   * Helper function build facets in search.
   *
   * @param array $params
   *   Array of parameters to be sent in the body of a _search endpoint
   *   Elasticsearch request.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   Search API query object.
   */
  protected function addSearchFacets(array &$params, QueryInterface $query) {

    // Search API facets.
    $facets = $query->getOption('search_api_facets');
    $index_fields = $this->getIndexFields($query);

    if (!empty($facets)) {
      // Loop through facets.
      foreach ($facets as $facet_id => $facet_info) {
        $field_id = $facet_info['field'];
        $facet = [$field_id => []];

        // Skip if not recognized as a known field.
        if (!isset($index_fields[$field_id])) {
          continue;
        }
        // TODO: missing function reference.
        $field_type = search_api_extract_inner_type($index_fields[$field_id]['type']);

        // TODO: handle different types (GeoDistance and so on). See the
        // supportedFeatures todo.
        if ($field_type === 'date') {
          $facet_type = 'date_histogram';
          $facet[$field_id] = $this->createDateFieldFacet($field_id, $facet);
        }
        else {
          $facet_type = 'terms';
          $facet[$field_id][$facet_type]['all_terms'] = (bool) $facet_info['missing'];
        }

        // Add the facet.
        if (!empty($facet[$field_id])) {
          // Add facet options.
          $facet_info['facet_type'] = $facet_type;
          $facet[$field_id] = $this->addFacetOptions($facet[$field_id], $query, $facet_info);
        }
        $params['body']['facets'][$field_id] = $facet[$field_id];
      }
    }
  }

  /**
   * Helper function that adds options and returns facet.
   *
   * @param array $facet
   * @param QueryInterface $query
   * @param string $facet_info
   *
   * @return array
   */
  protected function addFacetOptions(array &$facet, QueryInterface $query, $facet_info) {
    $facet_limit = $this->getFacetLimit($facet_info);
    $facet_search_filter = $this->getFacetSearchFilter($query, $facet_info);

    // Set the field.
    $facet[$facet_info['facet_type']]['field'] = $facet_info['field'];

    // OR facet. We remove filters affecting the associated field.
    // TODO: distinguish between normal filters and facet filters.
    // See http://drupal.org/node/1390598.
    // Filter the facet.
    if (!empty($facet_search_filter)) {
      $facet['facet_filter'] = $facet_search_filter;
    }

    // Limit the number of returned entries.
    if ($facet_limit > 0 && $facet_info['facet_type'] == 'terms') {
      $facet[$facet_info['facet_type']]['size'] = $facet_limit;
    }

    return $facet;
  }

  /**
   * Helper function return Facet filter.
   *
   * @param QueryInterface $query
   * @param array $facet_info
   *
   * @return array|null|string
   */
  protected function getFacetSearchFilter(QueryInterface $query, array $facet_info) {
    $index_fields = $this->getIndexFields($query);

    if (isset($facet_info['operator']) && Unicode::strtolower($facet_info['operator']) === 'or') {
      $facet_search_filter = $this->parseConditionGroup($query->getConditionGroup(), $index_fields, $facet_info['field']);
      if (!empty($facet_search_filter)) {
        $facet_search_filter = $facet_search_filter[0];
      }
    }
    // Normal facet, we just use the main query filters.
    else {
      $facet_search_filter = $this->parseConditionGroup($query->getConditionGroup(), $index_fields);
      if (!empty($facet_search_filter)) {
        $facet_search_filter = $facet_search_filter[0];
      }
    }

    return $facet_search_filter;
  }

  /**
   * Helper function create Facet for date field type.
   *
   * @param mixed $facet_id
   * @param array $facet
   *
   * @return array.
   */
  protected function createDateFieldFacet($facet_id, array $facet) {
    $result = $facet[$facet_id];

    $date_interval = $this->getDateFacetInterval($facet_id);
    $result['date_histogram']['interval'] = $date_interval;
    // TODO: Check the timezone cause this hardcoded way doesn't seem right.
    $result['date_histogram']['time_zone'] = 'UTC';
    // Use factor 1000 as we store dates as seconds from epoch
    // not milliseconds.
    $result['date_histogram']['factor'] = 1000;

    return $result;
  }

  /**
   * Helper function that return facet limits.
   *
   * @param array $facet_info
   *
   * @return int|null
   */
  protected function getFacetLimit(array $facet_info) {
    // If no limit (-1) is selected, use the server facet limit option.
    $facet_limit = !empty($facet_info['limit']) ? $facet_info['limit'] : -1;
    if ($facet_limit < 0) {
      $facet_limit = $this->getOption('facet_limit', 10);
    }
    return $facet_limit;
  }

  /**
   * Helper function which add params to date facets.
   *
   * @param mixed $facet_id
   *
   * @return string
   */
  protected function getDateFacetInterval($facet_id) {
    // Active search corresponding to this index.
    $searcher = key(facetapi_get_active_searchers());

    // Get the FacetApiAdapter for this searcher.
    $adapter = isset($searcher) ? facetapi_adapter_load($searcher) : NULL;

    // Get the date granularity.
    $date_gap = $this->getDateGranularity($adapter, $facet_id);

    switch ($date_gap) {
      // Already a selected YEAR, we want the months.
      case 'YEAR':
        $date_interval = 'month';
        break;

      // Already a selected MONTH, we want the days.
      case 'MONTH':
        $date_interval = 'day';
        break;

      // Already a selected DAY, we want the hours and so on.
      case 'DAY':
        $date_interval = 'hour';
        break;

      // By default we return result counts by year.
      default:
        $date_interval = 'year';
    }

    return $date_interval;
  }

  /**
   * Helper function to return date gap.
   *
   * @param $adapter
   * @param $facet_id
   *
   * @return mixed|string
   */
  public function getDateGranularity($adapter, $facet_id) {
    // Date gaps.
    $gap_weight = ['YEAR' => 2, 'MONTH' => 1, 'DAY' => 0];
    $gaps = [];
    $date_gap = 'YEAR';

    // Get the date granularity.
    if (isset($adapter)) {
      // Get the current date gap from the active date filters.
      $active_items = $adapter->getActiveItems(['name' => $facet_id]);
      if (!empty($active_items)) {
        foreach ($active_items as $active_item) {
          $value = $active_item['value'];
          if (strpos($value, ' TO ') > 0) {
            list($date_min, $date_max) = explode(' TO ', str_replace([
              '[',
              ']',
            ], '', $value), 2);
            $gap = self::getDateGap($date_min, $date_max, FALSE);
            if (isset($gap_weight[$gap])) {
              $gaps[] = $gap_weight[$gap];
            }
          }
        }
        if (!empty($gaps)) {
          // Minimum gap.
          $date_gap = array_search(min($gaps), $gap_weight);
        }
      }
    }

    return $date_gap;
  }

  /**
   * Helper function that parse facets.
   *
   * @param array $response
   * @param QueryInterface $query
   *
   * @return array
   */
  protected function parseSearchFacets(array $response, QueryInterface $query) {

    $result = [];
    $index_fields = $this->getIndexFields($query);
    $facets = $query->getOption('search_api_facets');
    if (!empty($facets) && isset($response['facets'])) {
      foreach ($response['facets'] as $facet_id => $facet_data) {
        if (isset($facets[$facet_id])) {
          $facet_info = $facets[$facet_id];
          $facet_min_count = $facet_info['min_count'];

          $field_id = $facet_info['field'];
          $field_type = search_api_extract_inner_type($index_fields[$field_id]['type']);

          // TODO: handle different types (GeoDistance and so on).
          if ($field_type === 'date') {
            foreach ($facet_data['entries'] as $entry) {
              if ($entry['count'] >= $facet_min_count) {
                // Divide time by 1000 as we want seconds from epoch
                // not milliseconds.
                $result[$facet_id][] = [
                  'count'  => $entry['count'],
                  'filter' => '"' . ($entry['time'] / 1000) . '"',
                ];
              }
            }
          }
          else {
            foreach ($facet_data['terms'] as $term) {
              if ($term['count'] >= $facet_min_count) {
                $result[$facet_id][] = [
                  'count'  => $term['count'],
                  'filter' => '"' . $term['term'] . '"',
                ];
              }
            }
          }
        }
      }
    }

    return $result;
  }

  /**
   * Allow custom changes before sending a search query to Elastic Search.
   *
   * This allows subclasses to apply custom changes before the query is sent to
   * Solr.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The \Drupal\search_api\Query\Query object representing the executed
   *   search query.
   */
  protected function preQuery(QueryInterface $query) {
  }

  /**
   * Allow custom changes before search results are returned for subclasses.
   *
   * @param \Drupal\search_api\Query\ResultSetInterface $results
   *   The results array that will be returned for the search.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The \Drupal\search_api\Query\Query object representing the executed
   *   search query.
   * @param object $response
   *   The response object returned by Elastic Search.
   */
  protected function postQuery(ResultSetInterface $results, QueryInterface $query, $response) {
  }

  /**
   * Implements __sleep()
   *
   * Prevents closure serialization error on search_api server add form
   */
  public function __sleep() {
    return [];
  }

}
