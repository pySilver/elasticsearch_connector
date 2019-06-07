<?php

namespace Drupal\elasticsearch_connector\Plugin\search_api_autocomplete\suggester;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api_autocomplete\Plugin\search_api_autocomplete\suggester\Server;

/**
 * Provides a suggester that retrieves suggestions from Elasticsearch terms.
 *
 * @SearchApiAutocompleteSuggester(
 *   id = "elasticsearch_terms",
 *   label = @Translation("Elasticsearch Terms"),
 *   description = @Translation("Autocomplete the entered string based on Elasticsearch terms aggregation with optional live results.")
 * )
 */
class Terms extends Server {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'fields'       => [],
      'live_results' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Let the user select the fulltext & text fields to use for autocomplete.
    $options = [];
    $search = $this->getSearch();
    $fields = $search->getIndex()->getFields();
    foreach ($fields as $field) {
      $field_id = $field->getFieldIdentifier();
      if (in_array($field->getType(), ['text', 'string'])) {
        $options[$field_id] = $fields[$field_id]->getFieldIdentifier();
      }
    }
    $form['fields']['#options'] = $options;

    // Let the user to enable live results.
    $form['live_results'] = [
      '#type'          => 'number',
      '#title'         => t('Live results'),
      '#min'           => 0,
      '#max'           => 100,
      '#default_value' => $this->getConfiguration()['live_results'],
      '#description'   => t('Number of live results to return.'),
    ];

    return $form;
  }

}
