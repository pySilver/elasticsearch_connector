<?php

namespace Drupal\elasticsearch_connector\Plugin\views;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\ElasticsearchConnectorException;
use Drupal\search_api\Plugin\views\filter\SearchApiFulltext;
use Drupal\views\Plugin\views\HandlerBase;

/**
 * Provides a trait to use for Search API Views handlers.
 */
trait ElasticsearchObjectHandlerTrait {

  /**
   * Views Handler Manager.
   *
   * @var \Drupal\views\Plugin\ViewsHandlerManager
   */
  public $viewsHandlerManager;

  /**
   * Real view handler.
   *
   * @var \Drupal\views\Plugin\views\HandlerBase
   */
  public $realHandler;

  /**
   * Object field properties.
   *
   * @var array
   */
  public $objectProperties = [];

  /**
   * Setting proxied handler.
   *
   * @param \Drupal\views\Plugin\views\HandlerBase $realHandler
   *   Real handler.
   *
   * @return self
   *   Self.
   */
  public function setRealHandler(HandlerBase $realHandler): self {
    $this->realHandler = $realHandler;
    return $this;
  }

  /**
   * Creates instance of real handler.
   *
   * @param string $plugin_id
   *   ViewFilter|ViewSort|ViewField plugin id.
   *
   * @return \Drupal\views\Plugin\views\HandlerBase
   *   Plugin instance.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function createRealHandler(string $plugin_id): HandlerBase {
    $configuration = $this->configuration;
    $configuration['id'] = $plugin_id;
    $instance = $this->viewsHandlerManager->createInstance($plugin_id, $configuration);

    $options = $this->options;
    $options['id'] = $options['field'] = sprintf(
      '%s.%s',
      $this->options['field'],
      $this->options['property_select']['property']
    );

    $instance->init($this->view, $this->displayHandler, $options);
    return $instance;
  }

  /**
   * Returns real handler instance.
   *
   * @return \Drupal\views\Plugin\views\HandlerBase|null
   *   Handler instance.
   */
  public function getRealHandler(): ?HandlerBase {
    return $this->realHandler;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return $this->realHandler->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return $this->realHandler->getCacheMaxAge();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->realHandler->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['property_select'] = [
      'contains' => [
        'property' => ['default' => ''],
      ],
    ];

    if (empty($this->objectProperties) && !empty($this->options['field'])) {
      $index = $this->getIndex();
      $field = $this->options['field'];
      $this->moduleHandler->alter(
        'elasticsearch_connector_object_properties',
        $this->objectProperties,
        $index,
        $field
      );
    }

    if (!empty($this->options['property_select']['property'])) {
      $property_id = $this->options['property_select']['property'];
      $plugin_type = $this->getPluginDefinition()['plugin_type'];
      $plugin_id = $this->objectProperties[$property_id][$plugin_type];
      $this->setRealHandler($this->createRealHandler($plugin_id));

      // Build options based on real handler.
      $new_options = $this->realHandler->defineOptions();
      $new_options['property_select'] = [
        'contains' => [
          'property' => ['default' => ''],
        ],
      ];

      $options = $new_options;
    }

    return $options;
  }

  /**
   * Creates object property select box.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function showPropertySelect(array &$form, FormStateInterface $form_state): void {
    $options = ['' => $this->t('Select property')];
    foreach ($this->objectProperties as $property_id => $property) {
      $options[$property_id] = $property['label'];
    }

    $form['property_select'] = [
      '#prefix' => '<div class="views-property clearfix">',
      '#suffix' => '</div>',
      // Should always come after the description and the relationship.
      '#weight' => -200,
    ];

    $form['property_select']['property'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Object property'),
      '#options'       => $options,
      '#required'      => FALSE,
      '#default_value' => $this->options['property_select']['property'] ?? '',
    ];

    $form['property_select']['button'] = [
      '#limit_validation_errors' => [['options', 'property_select']],
      '#type'                    => 'submit',
      '#value'                   => $this->t('Select property'),
      '#submit'                  => [[$this, 'saveSelectedProperty']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    $this->showPropertySelect($form, $form_state);

    if ($this->realHandler !== NULL) {
      $this->realHandler->buildOptionsForm($form, $form_state);
    }
  }

  /**
   * Saves selected property to options.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function saveSelectedProperty(array $form, FormStateInterface $form_state): void {
    $item = &$this->options;
    $property_id = $form_state->getValue(
      ['options', 'property_select', 'property']
    );

    if (!empty($property_id)) {
      $item['property_select']['property'] = $property_id;
    }
    // Reset selected value & operator.
    else {
      $item['property_select']['property'] = '';
      $item['operator'] = '=';
      $item['value'] = NULL;
    }

    $view = $form_state->get('view');
    $display_id = $form_state->get('display_id');
    $type = $form_state->get('type');
    $id = $form_state->get('id');
    $view->getExecutable()->setHandler($display_id, $type, $id, $item);

    $view->addFormToStack($form_state->get('form_key'), $display_id, $type, $id, TRUE, TRUE);

    $view->cacheSet();
    $form_state->set('rerender', TRUE);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    if ($this->realHandler !== NULL) {
      return $this->realHandler->adminSummary();
    }

    if (empty($this->options['property_select']['property'])) {
      return $this->t('Not Configured');
    }

    return parent::adminSummary();
  }

  /**
   * {@inheritdoc}
   */
  public function adminLabel($short = FALSE) {
    if (!empty($this->options['admin_label'])) {
      return $this->options['admin_label'];
    }

    $title = $this->definition['title'];
    if (!empty($this->options['property_select']['property'])) {
      $title = sprintf('%s.%s', $title, $this->options['property_select']['property']);
    }

    $title = ($short && isset($this->definition['title short'])) ? $this->definition['title short'] : $title;
    return $this->t('@group: @title', [
      '@group' => $this->definition['group'],
      '@title' => $title,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    if ($this->realHandler !== NULL && !empty($this->options['property_select']['property'])) {
      // TODO: Add support for nested full text filters.
      if ($this->realHandler instanceof SearchApiFulltext) {
        throw new ElasticsearchConnectorException('Nested full text fields are not supported');
      }
      $this->realHandler->query();
    }
  }

}
