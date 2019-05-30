<?php

namespace Drupal\elasticsearch_connector\Elasticsearch;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Elastica\Query;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use Elastica\Query\Exists;
use Elastica\Query\FunctionScore;
use Elastica\Query\Match;
use Elastica\Query\MatchPhrase;
use Elastica\Query\MoreLikeThis;
use Elastica\Query\MultiMatch;
use Elastica\Query\Range;
use Elastica\Query\SimpleQueryString;
use Elastica\Query\Term;
use Elastica\Query\Terms;
use Elastica\Suggest;
use Elastica\Suggest\CandidateGenerator\DirectGenerator;
use Elastica\Suggest\Phrase;
use Elastica\Aggregation\Terms as TermsAggregation;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\Utility as SearchApiUtility;
use Drupal\search_api\Query\Condition;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;

/**
 * Class SearchBuilder.
 */
class SearchBuilder {

  use StringTranslationTrait;

  /**
   * Search API Index entity.
   *
   * @var \Drupal\search_api\Entity\Index
   */
  protected $index;

  /**
   * Search API Query object.
   *
   * @var \Drupal\search_api\Query\QueryInterface
   */
  protected $query;

  /**
   * Elastica query object.
   *
   * @var \Elastica\Query
   */
  protected $esQuery;

  /**
   * Elastica root query.
   *
   * @var \Elastica\Query\BoolQuery
   */
  protected $esRootQuery;

  /**
   * Index fields.
   *
   * @var array|\Drupal\search_api\Item\FieldInterface[]
   */
  protected $indexFields;

  /**
   * Module Service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * ParameterBuilder constructor.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   Search API Query object.
   */
  public function __construct(QueryInterface $query) {
    $this->query = $query;
    $this->index = $query->getIndex();
    $this->esQuery = new Query();
    $this->esRootQuery = new BoolQuery();
    $this->indexFields = $this->getIndexFields();
    $this->moduleHandler = \Drupal::moduleHandler();
  }

  /**
   * Build query.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function build(): void {
    $this->setRange();
    $this->setFilters();
    $this->setFullTextFilters();
    $this->setExcludedSourceFields();
    $this->setMoreLikeThisQuery();
    $this->setAutocompleteAggs();
    $this->setSort();

    // TODO: Did you mean suggestion query.
    // TODO: Aggregation query.
    $this->esQuery->setQuery($this->esRootQuery);
  }

  /**
   * Returns elastica query.
   *
   * @return \Elastica\Query
   *   Query object.
   */
  public function getElasticQuery(): Query {
    return $this->esQuery;
  }

  /**
   * Sets query size/offset.
   */
  protected function setRange(): void {
    $options = $this->query->getOptions();
    $this->esQuery->setSize(empty($options['limit']) ? 10 : $options['limit']);
    $this->esQuery->setFrom(empty($options['offset']) ? 0 : $options['offset']);
  }

  /**
   * Sets query sort.
   */
  protected function setSort(): void {
    $index_fields = $this->index->getFields();
    $sort = [];
    $query_full_text_fields = $this->index->getFulltextFields();

    // Autocomplete queries are always sorted by score.
    if ($this->query->getOption('autocomplete') !== NULL) {
      $this->esQuery->setSort(['_score' => 'desc']);
      return;
    }

    foreach ($this->query->getSorts() as $field_id => $direction) {
      $direction = mb_strtolower($direction);

      if ($field_id === 'search_api_relevance') {
        $sort['_score'] = $direction;
      }
      elseif ($field_id === 'search_api_id') {
        $sort['id'] = $direction;
      }
      elseif ($field_id === 'search_api_random') {
        $random_sort_params = $this->query->getOption('search_api_random_sort', []);

        // Allow modules to alter random sort params.
        $this->moduleHandler->alter('elasticsearch_connector_search_api_random_sort', $random_sort_params);
        $seed = !empty($random_sort_params['seed']) ? $random_sort_params['seed'] : mt_rand();

        $query = new FunctionScore();
        $query->addRandomScoreFunction($seed, NULL, NULL, '_seq_no');
        $query->setQuery($this->esRootQuery);
        $this->esRootQuery = $query;
      }
      elseif (isset($index_fields[$field_id])) {
        if (in_array($field_id, $query_full_text_fields, TRUE)) {
          // Set the field that has not been analyzed for sorting.
          $sort[self::getNestedPath($field_id) . '.keyword'] = $direction;
        }
        else {
          $sort[self::getNestedPath($field_id)] = $direction;
        }
      }
    }

    if (!empty($sort)) {
      $this->esQuery->setSort($sort);
    }
  }

  /**
   * Sets query filters.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function setFilters(): void {
    $this->addLanguageConditions();
    $this->parseFilterConditionGroup(
      $this->query->getConditionGroup(),
      $this->indexFields,
      $this->esRootQuery
    );
  }

  /**
   * Adds item language conditions to the condition group, if applicable.
   *
   * @see \Drupal\search_api\Query\QueryInterface::getLanguages()
   */
  protected function addLanguageConditions(): void {
    $languages = $this->query->getLanguages();
    if ($languages !== NULL) {
      $condition_group = $this->query->getConditionGroup();
      $condition_group->addCondition('search_api_language', $languages, 'IN');
    }
  }

  /**
   * Returns available index fields.
   *
   * @return array|\Drupal\search_api\Item\FieldInterface[]
   *   Index fields.
   */
  protected function getIndexFields(): array {
    // Index fields.
    $index_fields = $this->index->getFields();

    // Search API does not provide metadata for some special fields but might
    // try to query for them. Thus add the metadata so we allow for querying
    // them.
    if (empty($index_fields['search_api_datasource'])) {
      $index_fields['search_api_datasource'] = \Drupal::getContainer()
        ->get('search_api.fields_helper')
        ->createField($this->index, 'search_api_datasource', ['type' => 'string']);
    }

    return $index_fields;
  }

  /**
   * Generate nested path for complex fields.
   *
   * Nested objects are denoted with __ as path separator due to
   * Field machine name limitations.
   *
   * @param string $field_id
   *   Original field id.
   *
   * @return string
   *   Nested field id, if required.
   */
  public static function getNestedPath(string $field_id): string {
    return str_replace('__', '.', $field_id);
  }

  /**
   * Recursively parse Search API condition group.
   *
   * @param \Drupal\search_api\Query\ConditionGroupInterface $condition_group
   *   The condition group object that holds all conditions that should be
   *   expressed as filters.
   * @param \Drupal\search_api\Item\FieldInterface[] $index_fields
   *   An array of all indexed fields for the index, keyed by field identifier.
   * @param \Elastica\Query\BoolQuery $filterQuery
   *   Filter query.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function parseFilterConditionGroup(
    ConditionGroupInterface $condition_group,
    array $index_fields,
    BoolQuery $filterQuery
  ): void {
    $backend_fields = ['search_api_language' => TRUE];

    foreach ($condition_group->getConditions() as $condition) {
      $filter = NULL;

      // Simple filter [field_id, value, operator].
      if ($condition instanceof Condition) {

        $field_id = $condition->getField();

        // Check field & operator settings.
        // Skip invalid fields.
        if (
          (!isset($index_fields[$field_id]) && !isset($backend_fields[$field_id])) ||
          !$condition->getOperator()
        ) {
          continue;
        }

        // For some data type, we need to do conversions here.
        if (isset($index_fields[$field_id]) && $index_fields[$field_id]->getType() === 'boolean') {
          $condition->setValue((bool) $condition->getValue());
        }

        // Field might be nested object type:
        $condition->setField(self::getNestedPath($condition->getField()));

        // Add filter.
        if ($condition_group->getConjunction() === 'AND') {
          $filterQuery->addFilter($this->parseFilterCondition($condition));
        }
        else {
          $filterQuery->addShould($this->parseFilterCondition($condition));
        }

      }
      // Nested filters.
      elseif ($condition instanceof ConditionGroupInterface) {
        $this->parseFilterConditionGroup($condition, $index_fields, $filterQuery);
      }
    }
  }

  /**
   * Get query by Condition instance.
   *
   * @param \Drupal\search_api\Query\Condition $condition
   *   Condition.
   *
   * @return \Elastica\Query\AbstractQuery
   *   Query filter.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function parseFilterCondition(Condition $condition): AbstractQuery {
    // Handles "empty", "not empty" operators.
    if ($condition->getValue() === NULL) {
      switch ($condition->getOperator()) {
        case '<>':
          $filter = new Exists($condition->getField());
          break;

        case '=':
          $filter = new BoolQuery();
          $filter->addMustNot(
            new Exists($condition->getField())
          );
          break;

        default:
          throw new SearchApiException("No filter value provided for field `{$condition->getField()}`");
      }
    }
    // Normal filters.
    else {
      switch ($condition->getOperator()) {
        case '=':
          $filter = new Term([$condition->getField() => ['value' => $condition->getValue()]]);
          break;

        case 'IN':
          $filter = new Terms($condition->getField(), $condition->getValue());
          break;

        case 'NOT IN':
          $filter = new BoolQuery();
          $filter->addMustNot(
            new Terms($condition->getField(), $condition->getValue())
          );
          break;

        case '<>':
          $filter = new BoolQuery();
          $filter->addMustNot(
            new Term([$condition->getField() => ['value' => $condition->getValue()]])
          );
          break;

        case '>':
          $filter = new Range($condition->getField(), ['gt' => $condition->getValue()]);
          break;

        case '>=':
          $filter = new Range($condition->getField(), ['gte' => $condition->getValue()]);
          break;

        case '<':
          $filter = new Range($condition->getField(), ['lt' => $condition->getValue()]);
          break;

        case '<=':
          $filter = new Range($condition->getField(), ['lte' => $condition->getValue()]);
          break;

        case 'BETWEEN':
          $filter = new Range(
            $condition->getField(),
            [
              'gt' => !empty($condition->getValue()[0]) ? $condition->getValue()[0] : NULL,
              'lt' => !empty($condition->getValue()[1]) ? $condition->getValue()[1] : NULL,
            ]
          );
          break;

        case 'NOT BETWEEN':
          $filter = new BoolQuery();
          $filter->addMustNot(
            new Range(
              $condition->getField(),
              [
                'gt' => !empty($condition->getValue()[0]) ? $condition->getValue()[0] : NULL,
                'lt' => !empty($condition->getValue()[1]) ? $condition->getValue()[1] : NULL,
              ]
            )
          );
          break;

        default:
          throw new SearchApiException("Unsupported operator `{$condition->getOperator()}` used for field `{$condition->getField()}`.");
      }
    }

    return $filter;
  }

  /**
   * Sets full text search for query.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function setFullTextFilters(): void {
    $keys = $this->query->getKeys();

    // Autocomplete query handles filter differently.
    if ($keys === NULL || $this->query->getOption('autocomplete')) {
      return;
    }

    $fuzzyness = $this
      ->index
      ->getServerInstance()
      ->getBackend()
      ->getFuzziness();

    if (is_string($keys)) {
      $keys = [$keys];
    }

    $full_text_fields = $this->query->getFulltextFields();
    if ($full_text_fields === NULL) {
      $full_text_fields = $this->index->getFulltextFields();
    }
    else {
      $full_text_fields = array_intersect($this->index->getFulltextFields(), $full_text_fields);
    }

    $query_fields = [];
    foreach ($full_text_fields as $full_text_field_name) {
      $full_text_field = $this->indexFields[$full_text_field_name];
      $query_fields[] = $full_text_field->getFieldIdentifier() . '^' . $full_text_field->getBoost();
    }

    $parse_mode = $this->query->getParseMode();
    $conjunction = $keys['#conjunction'] ?? 'AND';
    $negation = $keys['#negation'] ?? FALSE;

    switch ($parse_mode->getPluginId()) {
      case 'direct':
        $simpleQuery = new SimpleQueryString($keys[0], $query_fields);
        $simpleQuery->setDefaultOperator(SimpleQueryString::OPERATOR_OR);
        $this->esRootQuery->addMust($simpleQuery);
        break;

      case 'phrase':
      case 'terms':
        // Simple case without sub arrays.
        $terms = [];
        foreach ($keys as $id => $term) {
          if (is_int($id) && is_string($term)) {
            $terms[] = $term;
          }
        }

        if (!empty($terms)) {
          $this->esRootQuery->addMust(
            $this->getFullTextFilter(
              $parse_mode->getPluginId(),
              implode(' ', $terms),
              $query_fields,
              $conjunction,
              $negation,
              $fuzzyness
            )
          );
          break;
        }

        // Advanced case with sub arrays.
        foreach ($keys as $id => $sub_query) {

          if (!is_int($id) || !is_array($sub_query)) {
            continue;
          }

          $sub_conjunction = $sub_query['#conjunction'] ?? 'AND';
          $sub_negation = $sub_query['#negation'] ?? FALSE;
          $sub_terms = [];
          foreach ($sub_query as $sub_id => $sub_term) {
            if (is_int($sub_id) && is_string($sub_term)) {
              $sub_terms[] = $sub_term;
            }
          }

          $query = $this->getFullTextFilter(
            $parse_mode->getPluginId(),
            implode(' ', $sub_terms),
            $query_fields,
            $sub_conjunction,
            $sub_negation,
            $fuzzyness
          );

          if ($conjunction === 'AND') {
            $this->esRootQuery->addMust($query);
          }
          else {
            $this->esRootQuery->addShould($query);
          }
        }
        break;
    }
  }

  /**
   * Returns match query for multiple words.
   *
   * @param string $plugin_id
   *   Plugin ID.
   * @param string $query_string
   *   Search query string.
   * @param array $fields
   *   Full text fields.
   * @param string $conjunction
   *   Conjunction.
   * @param bool $negation
   *   Negation.
   * @param string $fuzzyness
   *   Fuzzyness.
   *
   * @return \Elastica\Query\BoolQuery|\Elastica\Query\AbstractQuery
   *   Query.
   */
  protected function getFullTextFilter(
    string $plugin_id,
    string $query_string,
    array $fields,
    string $conjunction,
    bool $negation,
    string $fuzzyness
  ): AbstractQuery {

    if ($plugin_id === 'phrase') {
      if (count($fields) === 1) {
        $field = array_shift($fields);
        $query = new MatchPhrase($field, $query_string);
        $query->setFieldParam($field, 'zero_terms_query', Match::ZERO_TERM_ALL);
      }
      else {
        $query = new MultiMatch();
        $query->setType(MultiMatch::TYPE_PHRASE);
        $query->setZeroTermsQuery(MultiMatch::ZERO_TERM_ALL);
        $query->setQuery($query_string);
        $query->setFields($fields);
        if ($conjunction === 'AND') {
          $query->setOperator(MultiMatch::OPERATOR_AND);
        }
        else {
          $query->setOperator(MultiMatch::OPERATOR_OR);
        }
      }

      // Negate query:
      if ($negation) {
        $query = (new BoolQuery())->addMustNot($query);
      }
      return $query;
    }

    if (count($fields) === 1) {
      $field = array_shift($fields);
      $query = new Match($field, $query_string);
      $query->setFieldFuzziness($field, $fuzzyness);
      $query->setFieldZeroTermsQuery($field, Match::ZERO_TERM_ALL);
      $query->setFieldOperator($field);
      if ($conjunction === 'AND') {
        $query->setFieldOperator($field, Match::OPERATOR_AND);
      }
      else {
        $query->setFieldOperator($field, Match::OPERATOR_OR);
      }
    }
    else {
      $query = new MultiMatch();
      $query->setType(MultiMatch::TYPE_CROSS_FIELDS);
      $query->setZeroTermsQuery(MultiMatch::ZERO_TERM_ALL);
      $query->setQuery($query_string);
      $query->setFields($fields);
      if ($conjunction === 'AND') {
        $query->setOperator(MultiMatch::OPERATOR_AND);
      }
      else {
        $query->setOperator(MultiMatch::OPERATOR_OR);
      }
    }

    // Negate query:
    if ($negation) {
      $query = (new BoolQuery())->addMustNot($query);
    }
    return $query;
  }

  /**
   * Excludes defined fields from response.
   */
  protected function setExcludedSourceFields(): void {
    $exclude_source_fields = $this->query->getOption('elasticsearch_connector_exclude_source_fields', []);

    if (!empty($exclude_source_fields)) {
      $this->esQuery->setSource([
        'excludes' => $exclude_source_fields,
      ]);
    }
  }

  /**
   * Sets More Like This query.
   */
  protected function setMoreLikeThisQuery(): void {
    $mlt_options = $this->query->getOption('search_api_mlt', []);
    if (empty($mlt_options)) {
      return;
    }
    $index_params = IndexHelper::index($this->index);

    $language_ids = $this->query->getLanguages();
    if (empty($language_ids)) {
      // If the query isn't already restricted by languages we have to do it
      // here in order to limit the MLT suggestions to be of the same language
      // as the currently shown one.
      $language_ids[] = \Drupal::languageManager()
        ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
        ->getId();
      // For non-translatable entity types, add the "not specified" language to
      // the query so they also appear in the results.
      $language_ids[] = LanguageInterface::LANGCODE_NOT_SPECIFIED;
      $this->query->setLanguages($language_ids);
    }

    $ids = [];
    foreach ($this->query->getIndex()->getDatasources() as $datasource) {
      if ($entity_type_id = $datasource->getEntityTypeId()) {
        $entity = \Drupal::entityTypeManager()
          ->getStorage($entity_type_id)
          ->load($mlt_options['id']);

        if ($entity instanceof ContentEntityInterface) {
          $translated = FALSE;
          if ($entity->isTranslatable()) {
            foreach ($language_ids as $language_id) {
              if ($entity->hasTranslation($language_id)) {
                $ids[] = SearchApiUtility::createCombinedId(
                  $datasource->getPluginId(),
                  $datasource->getItemId(
                    $entity->getTranslation($language_id)->getTypedData()
                  )
                );
                $translated = TRUE;
              }
            }
          }

          if (!$translated) {
            // Fall back to the default language of the entity.
            $ids[] = SearchApiUtility::createCombinedId(
              $datasource->getPluginId(),
              $datasource->getItemId($entity->getTypedData())
            );
          }
        }
        else {
          $ids[] = $mlt_options['id'];
        }
      }
    }

    // Object fields support:
    $fields = array_map([$this, 'getNestedPath'], $mlt_options['fields']);

    $mltQuery = new MoreLikeThis();
    $mltQuery->setFields(array_values($fields));

    $documents = [];
    foreach ($ids as $id) {
      $documents[] = [
        '_id'    => $id,
        '_index' => $index_params['index'],
        '_type'  => $index_params['type'],
      ];
    }
    $mltQuery->setLike($documents);

    // TODO: Make this settings configurable in the view.
    $mltQuery->setMaxQueryTerms(3);
    $mltQuery->setMinDocFrequency(1);
    $mltQuery->setMinTermFrequency(1);

    $this->esRootQuery->addMust($mltQuery);
  }

  /**
   * Sets autocomplete aggregations.
   */
  public function setAutocompleteAggs(): void {
    $options = $this->query->getOption('autocomplete');
    /** @var \Drupal\search_api_autocomplete\Entity\Search $search */
    $search = $options['search'];

    $incomplete_key = $options['incomplete_key'];
    $user_input = $options['user_input'];
    if (empty($user_input)) {
      return;
    }

    if (isset($search->getSuggesters()['server'])) {
      // Aggregate suggestions.
      $agg_field = $options['field'];
      $query_field = sprintf('%s.autocomplete', $agg_field);
      $include = sprintf(
        '%s.*',
        !empty($incomplete_key) ? $incomplete_key : $user_input
      );

      $agg = new TermsAggregation('autocomplete');
      $agg->setField($agg_field);
      $agg->setInclude($include);
      $agg->setSize($search->getSuggesterLimits()['server']);

      $match = new Match($query_field, $user_input);

      $this->esRootQuery->addMust($match);
      $this->esQuery->addAggregation($agg);
      $this->esQuery->setSize(0);

      // Retrieve spelling phrase single suggestion.
      $trigram_field = sprintf('%s.suggestion_trigram', $options['field']);
      $trigram_generator = new DirectGenerator($trigram_field);
      $trigram_generator->setSuggestMode(DirectGenerator::SUGGEST_MODE_ALWAYS);

      $reverse_field = sprintf('%s.suggestion_reverse', $options['field']);
      $reverse_generator = new DirectGenerator($reverse_field);
      $reverse_generator->setSuggestMode(DirectGenerator::SUGGEST_MODE_ALWAYS);
      $reverse_generator->setPreFilter('suggestion_reverse');
      $reverse_generator->setPostFilter('suggestion_reverse');

      $suggestion = new Phrase('autocomplete', $trigram_field);
      $suggestion->setSize(1);
      $suggestion->setText($user_input);
      $suggestion->addCandidateGenerator($trigram_generator);
      $suggestion->addCandidateGenerator($reverse_generator);

      $suggest = new Suggest($suggestion);
      $this->esQuery->setSuggest($suggest);
    }


    // Enable live results for the same query:
    if (isset($search->getSuggesters()['live_results'])) {
      $this->esQuery->setSize($search->getSuggesterLimits()['live_results']);
    }
  }

}
