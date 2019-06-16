<?php

namespace Drupal\elasticsearch_connector\Plugin\facets\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\BuildProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginBase;

/**
 * Provides a processor for nested objects.
 *
 * @FacetsProcessor(
 *   id = "nested_item",
 *   label = @Translation("Elasticsearch nested item processor"),
 *   description = @Translation("Allows to aggregate nested object available in Elasticsearch."),
 *   stages = {
 *     "build" = 35
 *   }
 * )
 */
class NestedFieldProcessor extends ProcessorPluginBase implements BuildProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet, array $results) {
    // @todo: We might need a special processing of results here be nicer links.
    // @see: \Drupal\facets\Plugin\facets\processor\ListItemProcessor
    /** @var \Drupal\facets\Result\ResultInterface $result */
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $fields = [
      'nested_path'       => '',
      'group_field_name'  => '',
      'group_field_value' => '',
      'value_field_name'  => '',
    ];
    return array_merge($fields, parent::defaultConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, FacetInterface $facet) {
    $configuration = $this->getConfiguration();

    $build['nested_path'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Nested path'),
      '#default_value' => $facet->getFieldIdentifier(),
      '#description'   => $this->t('Path to aggregated nested field.'),
      '#weight'        => 1,
    ];

    $build['group_field_name'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Group field name'),
      '#default_value' => $configuration['group_field_name'],
      '#description'   => $this->t('Field to group results by. Example: facet_name (will be concatenated with path as some_path.facet_name)'),
      '#weight'        => 1,
    ];

    $build['group_field_value'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Group field value'),
      '#default_value' => $configuration['group_field_value'],
      '#description'   => $this->t('Value of group by field to build a bucket against.'),
      '#weight'        => 1,
    ];

    $build['value_field_name'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Values field name'),
      '#default_value' => $configuration['value_field_name'],
      '#description'   => $this->t('Field name that contains values for aggregations. Example: facet_value (will be concatenated with path as some_path.facet_value)'),
      '#weight'        => 1,
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryType() {
    return 'nested';
  }

}
