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
use Elastica\Query\MatchAll;
use Elastica\Query\MoreLikeThis;
use Elastica\Query\MultiMatch;
use Elastica\Query\Nested;
use Elastica\Query\Range;
use Elastica\Query\SimpleQueryString;
use Elastica\Query\Term;
use Elastica\Query\Terms;
use Elastica\Suggest;
use Elastica\Suggest\CandidateGenerator\DirectGenerator;
use Elastica\Suggest\Phrase;
use Elastica\Aggregation\Terms as TermsAggregation;
use Elastica\Aggregation\Nested as NestedAggregation;
use Elastica\Aggregation\Filter as FilterAggregation;
use Elastica\Aggregation\Max;
use Elastica\Aggregation\Min;
use Elastica\Aggregation\TopHits;
use Elastica\Aggregation\Histogram as HistogramAggregation;
use Elastica\Aggregation\DateHistogram as DateHistogramAggregation;
use Drupal\facets\Plugin\facets\query_type\SearchApiDate;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\Utility as SearchApiUtility;
use Drupal\search_api\Query\ConditionInterface;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Illuminate\Support\Str;

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
   * Elastica post filter.
   *
   * @var \Elastica\Query\BoolQuery
   */
  protected $esPostFilter;

  /**
   * Named facet value filters.
   *
   * @var array
   */
  protected $facetPostFilters = [];

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
    $this->esPostFilter = new BoolQuery();
    $this->indexFields = $this->getIndexFields();
    $this->moduleHandler = \Drupal::moduleHandler();
  }

  /**
   * Build query.
   *
   * @throws \Drupal\search_api\SearchApiException
   *
   * @todo: Add support for geo queries.
   */
  public function build(): void {
    $this->setRange();
    $this->setFilters();
    $this->setFullTextFilters();
    $this->setExcludedSourceFields();
    $this->setMoreLikeThisQuery();
    $this->setAutocompleteAggs();
    $this->setDidYouMeanQuery();
    $this->setFacets();
    $this->setSort();

    $this->esQuery->setQuery($this->esRootQuery);
    $this->esQuery->setPostFilter($this->esPostFilter);
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
      $this->indexFields
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
   *
   * @todo: Remove once nested support implemented
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
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function parseFilterConditionGroup(
    ConditionGroupInterface $condition_group,
    array $index_fields
  ): void {

    foreach ($condition_group->getConditions() as $condition) {

      // Simple filter [field_id, value, operator].
      if ($condition instanceof ConditionInterface) {
        $field_id = $condition->getField();

        // For some data type, we need to do conversions here.
        if (isset($index_fields[$field_id]) && $index_fields[$field_id]->getType() === 'boolean') {
          $condition->setValue((bool) $condition->getValue());
        }

        // Field might be nested object type:
        $condition->setField(self::getNestedPath($condition->getField()));

        // Add filter/post_filter.
        if ($condition_group->getConjunction() === 'AND') {
          $this->esRootQuery->addFilter($this->parseFilterCondition($condition));
        }
        elseif ($condition_group->getConjunction() === 'OR') {
          // Filter provided by facet module with "OR" operator should use
          // post_filter instead of main query.
          if ($condition_group->hasTag(sprintf('facet:%s', $field_id))) {
            $filter = $this->parseFilterCondition($condition);
            $this->esPostFilter->addFilter($filter);
            $this->facetPostFilters[$field_id] = $filter;
          }
          else {
            $this->esRootQuery->addShould($this->parseFilterCondition($condition));
          }
        }

      }
      // Nested filters.
      elseif ($condition instanceof ConditionGroupInterface) {
        $this->parseFilterConditionGroup($condition, $index_fields);
      }
    }
  }

  /**
   * Get query by Condition instance.
   *
   * @param \Drupal\search_api\Query\ConditionInterface $condition
   *   Condition.
   *
   * @return \Elastica\Query\AbstractQuery
   *   Query filter.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function parseFilterCondition(ConditionInterface $condition): AbstractQuery {
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
          $filter = new Range($condition->getField(), ['gt' => (float) $condition->getValue()]);
          break;

        case '>=':
          $filter = new Range($condition->getField(), ['gte' => (float) $condition->getValue()]);
          break;

        case '<':
          $filter = new Range($condition->getField(), ['lt' => (float) $condition->getValue()]);
          break;

        case '<=':
          $filter = new Range($condition->getField(), ['lte' => (float) $condition->getValue()]);
          break;

        case 'BETWEEN':
          $filter = new Range(
            $condition->getField(),
            [
              'gt' => !empty($condition->getValue()[0]) ? (float) $condition->getValue()[0] : NULL,
              'lt' => !empty($condition->getValue()[1]) ? (float) $condition->getValue()[1] : NULL,
            ]
          );
          break;

        case 'NOT BETWEEN':
          $filter = new BoolQuery();
          $filter->addMustNot(
            new Range(
              $condition->getField(),
              [
                'gt' => !empty($condition->getValue()[0]) ? (float) $condition->getValue()[0] : NULL,
                'lt' => !empty($condition->getValue()[1]) ? (float) $condition->getValue()[1] : NULL,
              ]
            )
          );
          break;

        default:
          throw new SearchApiException("Unsupported operator `{$condition->getOperator()}` used for field `{$condition->getField()}`.");
      }
    }

    // Adds support for nested queries:
    if (Str::contains($condition->getField(), '.')) {
      [$object_field] = explode('.', $condition->getField());
      if (isset($this->indexFields[$object_field]) &&
          $this->indexFields[$object_field]->getType() === 'nested_object') {
        $nested_filter = new Nested();
        $nested_filter->setPath($object_field);
        $nested_filter->setQuery($filter);
        $filter = $nested_filter;
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
    $query = new MultiMatch();
    $query->setType(MultiMatch::TYPE_BEST_FIELDS);
    $query->setQuery($query_string);
    $query->setFields($fields);
    if ($conjunction === 'AND') {
      $query->setOperator(MultiMatch::OPERATOR_AND);
    }
    else {
      $query->setOperator(MultiMatch::OPERATOR_OR);
    }

    // Phrase query support.
    if ($plugin_id === 'phrase') {
      $query->setType(MultiMatch::TYPE_PHRASE);
    }
    else {
      // The fuzziness parameter cannot be used with the
      // phrase or phrase_prefix type.
      $query->setFuzziness($fuzzyness);
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
  protected function setAutocompleteAggs(): void {
    $options = $this->query->getOption('autocomplete');
    /** @var \Drupal\search_api_autocomplete\Entity\Search $search */
    $search = $options['search'];

    $incomplete_key = $options['incomplete_key'];
    $user_input = $options['user_input'];
    if (empty($user_input)) {
      return;
    }

    $suggesters = $search->getSuggesters();
    $suggester_limits = $search->getSuggesterLimits();

    if (isset($suggesters['elasticsearch_terms']) || isset($suggesters['server'])) {
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
      $agg->setSize($suggester_limits['elasticsearch_terms'] ?? $suggester_limits['server']);

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
    if (isset($suggesters['elasticsearch_terms'])) {
      $this->esQuery->setSize(
        (int) $suggesters['elasticsearch_terms']->getConfiguration()['live_results']
      );
    }
  }

  /**
   * Returns granularity for Facets API date histogram.
   *
   * @param int $granularity
   *   Granularity.
   *
   * @return string
   *   Granularity supported by Elasticsearch date histogram aggregation.
   *
   * @see: \Drupal\facets\Plugin\facets\query_type\SearchApiDate
   */
  protected function getFacetApiDateGranularity($granularity): string {
    switch ($granularity) {
      case SearchApiDate::FACETAPI_DATE_YEAR:
        $ret = 'year';
        break;

      case SearchApiDate::FACETAPI_DATE_MONTH:
        $ret = 'month';
        break;

      case SearchApiDate::FACETAPI_DATE_DAY:
        $ret = 'day';
        break;

      case SearchApiDate::FACETAPI_DATE_HOUR:
        $ret = 'hour';
        break;

      case SearchApiDate::FACETAPI_DATE_MINUTE:
        $ret = 'minute';
        break;

      case SearchApiDate::FACETAPI_DATE_SECOND:
        $ret = 'second';
        break;

      default:
        $ret = 'month';
        break;
    }

    return $ret;
  }

  /**
   * Sets "Did you mean" spell suggestion.
   */
  protected function setDidYouMeanQuery(): void {
    $query_string = $this->query->getOriginalKeys();
    if (!is_string($query_string)) {
      return;
    }

    $query_string = trim($query_string);

    // Skip for autocomplete queries and empty query strings.
    if (empty($query_string) || !empty($this->query->getOption('autocomplete'))) {
      return;
    }

    // Determine suggestion field.
    $suggestion_field = '';
    $this->moduleHandler->alter(
      'elasticsearch_connector_search_api_spelling_suggestion_field',
      $suggestion_field
    );

    if (empty($suggestion_field)) {
      return;
    }

    // Retrieve spelling phrase single suggestion.
    $trigram_field = sprintf('%s.suggestion_trigram', $suggestion_field);
    $trigram_generator = new DirectGenerator($trigram_field);
    $trigram_generator->setSuggestMode(DirectGenerator::SUGGEST_MODE_ALWAYS);

    $reverse_field = sprintf('%s.suggestion_reverse', $suggestion_field);
    $reverse_generator = new DirectGenerator($reverse_field);
    $reverse_generator->setSuggestMode(DirectGenerator::SUGGEST_MODE_ALWAYS);
    $reverse_generator->setPreFilter('suggestion_reverse');
    $reverse_generator->setPostFilter('suggestion_reverse');

    $suggestion = new Phrase('spelling_suggestion', $trigram_field);
    $suggestion->setSize(1);
    $suggestion->setText($query_string);
    $suggestion->addCandidateGenerator($trigram_generator);
    $suggestion->addCandidateGenerator($reverse_generator);

    $suggest = new Suggest($suggestion);
    $this->esQuery->setSuggest($suggest);
  }

  /**
   * Sets facets.
   */
  protected function setFacets(): void {
    $facets = $this->query->getOption('search_api_facets', []);

    // Do not build aggregations for autocomplete queries.
    if (empty($facets) || $this->query->getOption('autocomplete') !== NULL) {
      return;
    }

    /** @var \Elastica\Aggregation\Filter[] $aggs */
    $aggs = [];

    foreach ($facets as $facet_id => $facet) {
      $agg = NULL;

      // Field might be part of objects that are flattened in search api.
      $facet['field'] = self::getNestedPath($facet['field']);

      switch ($facet['query_type']) {
        case 'search_api_range':
          $min_agg = new Min('min');
          $min_agg->setField($facet['field']);

          $max_agg = new Max('max');
          $max_agg->setField($facet['field']);

          $agg = new FilterAggregation($facet_id);
          $agg->addAggregation($min_agg);
          $agg->addAggregation($max_agg);
          break;

        case 'search_api_granular':
        case 'search_api_date':
          if (isset($facet['date_display'])) {
            $histogram = new DateHistogramAggregation(
              $facet_id,
              $facet['field'],
              $this->getFacetApiDateGranularity($facet['granularity'])
            );
          }
          else {
            $histogram = new HistogramAggregation($facet_id, $facet['field'], $facet['granularity']);
            if (is_numeric($facet['min_value']) && is_numeric($facet['max_value'])) {
              $agg->setParam('setExtendedBounds', [
                'min' => $facet['min_value'],
                'max' => $facet['max_value'],
              ]);
            }
          }

          $agg = new FilterAggregation($facet_id);
          $agg->addAggregation($histogram);
          break;

        case 'search_api_nested':
          // TODO: Add support for numeric values.
          // Outermost filter aggregation:
          $agg = new FilterAggregation($facet_id);

          // That contains nested aggregation required by nested object mapping.
          $nested_agg = new NestedAggregation($facet_id, $facet['nested_path']);

          // ...That contains inner filter aggregation used
          // to group results by $facet['group_field_value']:
          $filter_agg = new FilterAggregation(
            $facet_id,
            new Term([
              sprintf('%s.%s', $facet['nested_path'], $facet['group_field_name']) => [
                'value' => $facet['group_field_value'],
              ],
            ])
          );

          // ...That contains inner terms aggregation
          // to build buckets of values:
          $terms_agg = new TermsAggregation($facet_id);
          $terms_agg->setSize(1000);
          $terms_agg->setField(sprintf('%s.%s', $facet['nested_path'], $facet['value_field_name']));

          // ...That contains inner top hits aggregation
          // to retrieve more data from nested objects.
          $top_hits_agg = new TopHits($facet_id);
          $top_hits_agg->setSize(1);
          $top_hits_agg->setSource($facet['nested_path']);

          // Build the agg:
          $terms_agg->addAggregation($top_hits_agg);
          $filter_agg->addAggregation($terms_agg);
          $nested_agg->addAggregation($filter_agg);
          $agg->addAggregation($nested_agg);

          // Process selected values.
          $this->filterActiveNestedFacetValues($facet);
          break;

        case 'search_api_string':
        default:
          $terms_agg = new TermsAggregation($facet_id);
          $terms_agg->setField($facet['field']);
          $terms_agg->setMinimumDocumentCount($facet['min_count']);
          if ($facet['limit'] > 0) {
            $terms_agg->setSize($facet['limit']);
          }

          if ($facet['missing']) {
            $terms_agg->setParam('missing', '');
          }

          $agg = new FilterAggregation($facet_id);
          $agg->addAggregation($terms_agg);
          break;
      }

      if ($agg === NULL) {
        continue;
      }

      $agg->setFilter(new MatchAll());
      $aggs[$facet_id] = $agg;
    }

    // Construct filters based on post_filter for facets
    // with "OR" query operator.
    foreach ($facets as $facet_id => $facet) {
      if (!isset($aggs[$facet_id])) {
        continue;
      }

      $agg = $aggs[$facet_id];
      if ($facet['operator'] !== 'or') {
        $this->esQuery->addAggregation($agg);
        continue;
      }

      $facet_field_id = $facet['field'];
      if ($facet['query_type'] === 'search_api_nested') {
        $facet_field_id = sprintf(
          '%s.%s:%s',
          $facet['nested_path'],
          $facet['group_field_name'],
          $facet['group_field_value']
        );
      }

      // Filter for aggregation should contain full post filter - without
      // filtering for currently processed facet.
      $facet_filter = new BoolQuery();
      foreach ($this->facetPostFilters as $field_id => $filter) {
        if ($field_id === $facet_field_id) {
          continue;
        }
        $facet_filter->addFilter($filter);
      }

      $agg->setFilter($facet_filter);
      $this->esQuery->addAggregation($agg);
    }
  }

  /**
   * Adds filter for nested aggregations.
   *
   * @param array $current_facet
   *   Currently processed facet.
   */
  protected function filterActiveNestedFacetValues(array $current_facet): void {

    /** @var \Drupal\facets\Entity\Facet $facet */
    $facet = $current_facet['facet'];
    $active_items = $facet->getActiveItems();
    $exclude = $facet->getExclude();
    if (empty($active_items)) {
      return;
    }

    $filter_field = sprintf(
      '%s.%s',
      $current_facet['nested_path'],
      $current_facet['group_field_name']
    );

    $value_field = sprintf(
      '%s.%s',
      $current_facet['nested_path'],
      $current_facet['value_field_name']
    );

    $type_filter = new Term([$filter_field => ['value' => $current_facet['group_field_value']]]);
    $value_filter = new Terms($value_field, $active_items);
    if ($exclude) {
      $value_filter = (new BoolQuery())->addMustNot($value_filter);
    }

    $nested_filter = new Nested();
    $nested_filter->setPath($current_facet['nested_path']);
    $nested_filter->setQuery(
      (new BoolQuery())->addFilter($type_filter)->addFilter($value_filter)
    );

    // Facet "OR" query operator uses post_filter to widen search options.
    if ($facet->getQueryOperator() === 'or') {
      $this->esPostFilter->addFilter($nested_filter);

      $field_id = sprintf('%s:%s', $filter_field, $current_facet['group_field_value']);
      $this->facetPostFilters[$field_id] = $nested_filter;
    }
    // Facet "AND" query operator uses query filter to narrow search options.
    else {
      $this->esRootQuery->addFilter($nested_filter);
    }

  }

}
