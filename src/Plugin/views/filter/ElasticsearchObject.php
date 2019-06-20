<?php

namespace Drupal\elasticsearch_connector\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;
use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
 * Filter handler for simple (flat) elasticsearch objects fields.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsFilter("elasticsearch_object")
 */
class ElasticsearchObject extends FilterPluginBase {

  use SearchApiFilterTrait;

  /**
   * Views Handler Manager.
   *
   * @var \Drupal\views\Plugin\ViewsHandlerManager
   */
  public $viewsHandlerManager;

  /**
   * Real filter handler.
   *
   * @var \Drupal\views\Plugin\views\filter\FilterPluginBase
   */
  public $realFilter;


  /**
   * Object field properties.
   *
   * @var array
   */
  public $objectProperties = [];

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->viewsHandlerManager = \Drupal::service('plugin.manager.views.filter');
  }

  /**
   * Setting filter.
   *
   * @param \Drupal\views\Plugin\views\filter\FilterPluginBase $realFilter
   *   Real filter that processes this filter.
   *
   * @return self
   *   Self.
   */
  public function setRealFilter(FilterPluginBase $realFilter): self {
    $this->realFilter = $realFilter;
    return $this;
  }

  /**
   * Creates instance of real filter.
   *
   * @param string $plugin_id
   *   ViewFilter plugin id.
   *
   * @return \Drupal\views\Plugin\views\filter\FilterPluginBase
   *   Plugin instance.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function createRealFilter(string $plugin_id): FilterPluginBase {
    $configuration = $this->configuration;
    $configuration['id'] = $plugin_id;
    $instance = $this->viewsHandlerManager->createInstance($plugin_id, $configuration);
    $instance->init($this->view, $this->displayHandler, $this->options);
    return $instance;
  }

  /**
   * Returns real filter instance.
   *
   * @return \Drupal\views\Plugin\views\filter\FilterPluginBase|null
   *   Filter instance.
   */
  public function getRealFilter(): ?FilterPluginBase {
    return $this->realFilter;
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

    if (empty($this->objectProperties) && !empty($this->options['table']) && !empty($this->options['field'])) {
      $index = $this->getIndex();
      $this->moduleHandler->alter(
        'elasticsearch_connector_object_properties',
        $this->objectProperties,
        $index,
        $this->options['field']
      );
    }

    if (!empty($this->options['property_select']['property'])) {
      $property_id = $this->options['property_select']['property'];
      $plugin_id = $this->objectProperties[$property_id]['type'];
      $this->setRealFilter($this->createRealFilter($plugin_id));

      // Build options based on real filter.
      $new_options = $this->realFilter->defineOptions();
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
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $this->showPropertySelect($form, $form_state);

    if ($this->realFilter instanceof FilterPluginBase) {
      $this->realFilter->buildOptionsForm($form, $form_state);
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
    if ($this->realFilter instanceof FilterPluginBase) {
      return $this->realFilter->adminSummary();
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

}
