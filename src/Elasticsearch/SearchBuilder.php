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
use Drupal\facets\Entity\Facet;
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

    if ($this->esPostFilter->count() > 0) {
      $this->esQuery->setPostFilter($this->esPostFilter);
    }
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
      elseif (in_array($field_id, $query_full_text_fields, TRUE)) {
        // Set the field that has not been analyzed for sorting.
        $sort[self::buildNestedField($field_id) . '.keyword'] = $direction;
      }
      else {
        $sort[self::buildNestedField($field_id)] = $direction;
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
    $this->parseFilterConditionGroup($this->query->getConditionGroup(), $this->esRootQuery);
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
   * @param string $field_identifier
   *   Original field id.
   *
   * @return string
   *   Nested field id, if required.
   */
  public static function buildNestedField(string $field_identifier): string {
    return str_replace('__', '.', $field_identifier);
  }

  /**
   * Returns base for a nested field.
   *
   * @param string $field_identifier
   *   Field identifier.
   *
   * @return string
   *   Field base path.
   */
  public static function getNestedField(string $field_identifier): string {
    [$field_identifier] = explode('.', self::buildNestedField($field_identifier));
    return $field_identifier;
  }

  /**
   * Returns base name for a nested field.
   *
   * @param string $field_identifier
   *   Field identifier.
   *
   * @return string
   *   Last element of nested field path.
   */
  public static function getNestedFieldBaseName(string $field_identifier): string {
    return basename(str_replace(['.', '__'], '/', $field_identifier));
  }

  /**
   * Checks whether given field is an elasticsearch nested object type.
   *
   * @param string $field_identifier
   *   Field identifier.
   *
   * @return bool
   *   Result.
   */
  public function isNestedField(string $field_identifier): bool {
    $field_identifier = self::buildNestedField($field_identifier);
    $field_identifier = self::getNestedField($field_identifier);
    if (isset($this->indexFields[$field_identifier]) &&
        $this->indexFields[$field_identifier]->getType() === 'nested_object') {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Recursively parse Search API condition group.
   *
   * @param \Drupal\search_api\Query\ConditionGroupInterface $condition_group
   *   The condition group object that holds all conditions that should be
   *   expressed as filters.
   * @param \Elastica\Query\BoolQuery $query
   *   Filter query.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function parseFilterConditionGroup(ConditionGroupInterface $condition_group, BoolQuery $query): BoolQuery {
    foreach ($condition_group->getConditions() as $condition) {

      // Simple filter [field_id, value, operator].
      if ($condition instanceof ConditionInterface) {
        $field_id = $condition->getField();

        // For some data type, we need to do conversions here.
        if (
          isset($this->indexFields[$field_id]) &&
          $this->indexFields[$field_id]->getType() === 'boolean'
        ) {
          $condition->setValue((bool) $condition->getValue());
        }

        // Field might be nested object type:
        $condition->setField(self::buildNestedField($condition->getField()));

        // Add filter/post_filter.
        if ($condition_group->getConjunction() === 'AND') {
          $query->addFilter($this->parseFilterCondition($condition));
        }
        elseif ($condition_group->getConjunction() === 'OR') {
          // Filter provided by facet module with "OR" operator should use
          // post_filter instead of main query.
          if ($condition_group->hasTag(sprintf('facet:%s', $field_id))) {
            $facet_id = $this->getFacetIdFromConditionGroup($condition_group);
            // Nested fields are handled in setFacets().
            if (!empty($facet_id) && !$this->isNestedField($field_id)) {
              $filter = $this->parseFilterCondition($condition);
              $this->esPostFilter->addFilter($filter);
              $this->facetPostFilters[$facet_id] = $filter;
            }
          }
          else {
            $query->addShould($this->parseFilterCondition($condition));
          }
        }

      }
      // Nested filters.
      elseif ($condition instanceof ConditionGroupInterface) {
        if ($condition_group->getConjunction() === 'OR') {
          $clause = $this->parseFilterConditionGroup($condition, new BoolQuery());
          if ($clause->count() > 0) {
            $query->addShould($clause);
          }
        }
        else {
          $clause = $this->parseFilterConditionGroup($condition, new BoolQuery());
          if ($clause->count() > 0) {
            $query->addFilter($clause);
          }
        }
      }
    }

    return $query;
  }

  /**
   * Finds facet machine name in condition group tags.
   *
   * @param \Drupal\search_api\Query\ConditionGroupInterface $group
   *   Condition group object.
   *
   * @return string|null
   *   Facet id, if found.
   */
  protected function getFacetIdFromConditionGroup(ConditionGroupInterface $group): ?string {
    $ret = NULL;
    foreach ($group->getTags() as $tag) {
      if (Str::startsWith($tag, 'facet_id:')) {
        [, $ret] = explode(':', $tag);
        break;
      }
    }

    return $ret;
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
              'gte' => !empty($condition->getValue()[0]) ? (float) $condition->getValue()[0] : NULL,
              'lte' => !empty($condition->getValue()[1]) ? (float) $condition->getValue()[1] : NULL,
            ]
          );
          break;

        case 'NOT BETWEEN':
          $filter = new BoolQuery();
          $filter->addMustNot(
            new Range(
              $condition->getField(),
              [
                'gte' => !empty($condition->getValue()[0]) ? (float) $condition->getValue()[0] : NULL,
                'lte' => !empty($condition->getValue()[1]) ? (float) $condition->getValue()[1] : NULL,
              ]
            )
          );
          break;

        default:
          throw new SearchApiException("Unsupported operator `{$condition->getOperator()}` used for field `{$condition->getField()}`.");
      }
    }

    // Adds support for nested queries:
    if ($this->isNestedField($condition->getField())) {
      $nested_filter = new Nested();
      $nested_filter->setPath(self::getNestedField($condition->getField()));
      $nested_filter->setQuery($filter);
      $filter = $nested_filter;
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
    $fields = array_map([$this, 'buildNestedField'], $mlt_options['fields']);

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

    foreach ($facets as $facet_id => $facet_options) {
      /** @var \Drupal\facets\Entity\Facet $facet_obj */
      $facet_obj = $facet_options['facet'];

      // Field might be part of objects that are flattened in search api.
      $facet_options['field'] = self::buildNestedField($facet_options['field']);
      $values_field = $facet_options['field'];
      $is_nested_field = $this->isNestedField($facet_options['field']);

      // Default filter.
      $filter_agg = new FilterAggregation($facet_id);
      $filter_agg->setFilter(new MatchAll());

      // Setup nested aggregation wrapper.
      if ($is_nested_field) {
        $options = $facet_obj->getThirdPartySettings('elasticsearch_connector')['nested'];
        $nested_field = self::getNestedField($facet_options['field']);
        $values_field = self::buildNestedField($facet_options['field']);
        $filter_field = self::buildNestedField($options['filter_field_identifier']);
        $filter_value = $options['filter_field_value'];

        // Nested field should use nested aggregation.
        $nested_agg = new NestedAggregation(
          $facet_id,
          $nested_field
        );
        $filter_agg->addAggregation($nested_agg);

        // Nested values needs to be filtered before aggregation.
        $values_filter_agg = new FilterAggregation(
          $facet_id,
          new Term([$filter_field => ['value' => $filter_value]])
        );
        $nested_agg->addAggregation($values_filter_agg);

        // Create underlying facet.
        $facet_options['limit'] = 1000;
        $this->buildFacetAggregation($facet_id, $values_field, $facet_options, $values_filter_agg);

        // Add top hits aggregation for terms agg.
        if ($facet_options['query_type'] === 'search_api_string' && !empty($values_filter_agg->getAggs())) {
          /** @var \Elastica\Aggregation\Terms $terms_agg */
          $terms_agg = $values_filter_agg->getAggs()[0];
          if ($terms_agg instanceof TermsAggregation) {
            $top_hits_agg = new TopHits($facet_id);
            $top_hits_agg->setSize(1);
            $top_hits_agg->setSource($nested_field);
            $terms_agg->addAggregation($top_hits_agg);
          }
        }

        // Process selected values to build filters.
        $this->filterActiveNestedFacetValues(
          $facet_obj,
          $facet_id,
          $facet_options['query_type'],
          $nested_field,
          $values_field,
          $filter_field,
          $filter_value
        );
      }
      // Setup basic flat field based aggregation.
      else {
        $this->buildFacetAggregation($facet_id, $values_field, $facet_options, $filter_agg);
      }

      $aggs[$facet_id] = $filter_agg;
    }

    // Construct filters based on post_filter for facets
    // with "OR" query operator.
    foreach ($facets as $facet_id => $facet_options) {
      if (!isset($aggs[$facet_id])) {
        continue;
      }

      $agg = $aggs[$facet_id];
      if ($facet_options['operator'] !== 'or') {
        $this->esQuery->addAggregation($agg);
        continue;
      }

      // Filter for aggregation should contain full post filter - without
      // filtering for currently processed facet.
      $facet_filter = new BoolQuery();
      foreach ($this->facetPostFilters as $filter_facet_id => $filter) {
        if ($filter_facet_id === $facet_id) {
          continue;
        }
        $facet_filter->addFilter($filter);
      }

      $agg->setFilter($facet_filter);
      $this->esQuery->addAggregation($agg);
    }
  }

  /**
   * Build aggregation for single facet.
   *
   * @param string $facet_id
   *   Facet machine name.
   * @param string $values_field
   *   Values field for facet.
   * @param array $facet_options
   *   Provided facet options.
   * @param \Elastica\Aggregation\Filter $filter_agg
   *   Parent filter aggregation to attach created aggregation.
   */
  protected function buildFacetAggregation(
    string $facet_id,
    string $values_field,
    array $facet_options,
    FilterAggregation $filter_agg
  ): void {
    switch ($facet_options['query_type']) {
      case 'search_api_range':
        $min_agg = new Min('min');
        $min_agg->setField($values_field);
        $max_agg = new Max('max');
        $max_agg->setField($values_field);
        $filter_agg->addAggregation($min_agg);
        $filter_agg->addAggregation($max_agg);
        break;

      case 'search_api_granular':
      case 'search_api_date':
        if (isset($facet_options['date_display'])) {
          $histogram = new DateHistogramAggregation(
            $facet_id,
            $values_field,
            $this->getFacetApiDateGranularity($facet_options['granularity'])
          );
        }
        else {
          $histogram = new HistogramAggregation($facet_id, $values_field, $facet_options['granularity']);
          if (is_numeric($facet_options['min_value']) && is_numeric($facet_options['max_value'])) {
            $histogram->setParam('setExtendedBounds', [
              'min' => $facet_options['min_value'],
              'max' => $facet_options['max_value'],
            ]);
          }
        }
        $filter_agg->addAggregation($histogram);
        break;

      case 'search_api_string':
      default:
        $terms_agg = new TermsAggregation($facet_id);
        $terms_agg->setField($values_field);
        $terms_agg->setMinimumDocumentCount($facet_options['min_count']);
        if ($facet_options['limit'] > 0) {
          $terms_agg->setSize($facet_options['limit']);
        }
        if ($facet_options['missing']) {
          $terms_agg->setParam('missing', '');
        }
        $filter_agg->addAggregation($terms_agg);
        break;
    }
  }

  /**
   * Adds filter for nested aggregations.
   *
   * @param \Drupal\facets\Entity\Facet $facet
   *   Facet object.
   * @param string $facet_id
   *   Facet machine name.
   * @param string $query_type
   *   Facet query type.
   * @param string $nested_field
   *   Nested field.
   * @param string $values_field
   *   Values field.
   * @param string $filter_field
   *   Nested filter field.
   * @param string $filter_value
   *   Nested filter field value.
   */
  protected function filterActiveNestedFacetValues(
    Facet $facet,
    string $facet_id,
    string $query_type,
    string $nested_field,
    string $values_field,
    string $filter_field,
    string $filter_value
  ): void {
    $active_items = $facet->getActiveItems();
    $exclude = $facet->getExclude();
    if (empty($active_items)) {
      return;
    }

    $type_filter = new Term([$filter_field => ['value' => $filter_value]]);
    switch ($query_type) {
      // TODO: Test date & granular queries.
//      case 'search_api_granular':
//      case 'search_api_date':
      case 'search_api_range':
        $active_items = $active_items[0];
        $value_filter = new Range(
          $values_field,
          [
            'gte' => isset($active_items[0]) ? (float) $active_items[0] : NULL,
            'lte' => isset($active_items[1]) ? (float) $active_items[1] : NULL,
          ]
        );
        break;

      case 'search_api_string':
      default:
        $value_filter = new Terms($values_field, $active_items);
        break;
    }

    if ($exclude) {
      $value_filter = (new BoolQuery())->addMustNot($value_filter);
    }
    $nested_filter = new Nested();
    $nested_filter->setPath($nested_field);
    $nested_filter->setQuery(
      (new BoolQuery())->addFilter($type_filter)->addFilter($value_filter)
    );

    // Facet "OR" query operator uses post_filter to widen search options.
    if ($facet->getQueryOperator() === 'or') {
      $this->esPostFilter->addFilter($nested_filter);
      $this->facetPostFilters[$facet_id] = $nested_filter;
    }
    // Facet "AND" query operator uses query filter to narrow search options.
    else {
      $this->esRootQuery->addFilter($nested_filter);
    }
  }

}
