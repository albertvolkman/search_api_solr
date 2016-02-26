<?php

/**
 * @file
 * Contains \Drupal\as_search\Plugin\search_api\backend\AbstractSearchApiSolrMultilingualBackend.
 */

namespace Drupal\search_api_solr_multilingual\Plugin\search_api\backend;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Language\LanguageInterface;
use Drupal\search_api_solr_multilingual\SearchApiSolrMultilingualException;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;
use Drupal\search_api_solr\Utility\Utility as SearchApiSolrUtility;
use Drupal\search_api_solr_multilingual\Utility\Utility;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Select\Query\FilterQuery;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Select\Result\Result;

/**
 * The name of the language field might be change in future releases of
 * search_api. @see https://www.drupal.org/node/2641392 for details.
 * Therefor we define a constant here that could be easily changed.
 */
define('SEARCH_API_LANGUAGE_FIELD_NAME', 'search_api_language');

  /**
   * Class AbstractSearchApiSolrMultilingualBackend
   * @package Drupal\search_api_solr_multilingual\Plugin\search_api\backend
   */
abstract class AbstractSearchApiSolrMultilingualBackend extends SearchApiSolrBackend {

  abstract protected function createSolrDynamicField($solr_field_name, $solr_field_type_name);

  abstract protected function createSolrMultilingualFieldType($solr_field_type_name);

  /**
   * @inheritdoc
   */
  protected function connect() {
    parent::connect();

    // @todo the configurations don't exist yet.
    $this->configuration['sasm_limit_search_page_to_content_language'] = TRUE;
    $this->configuration['sasm_language_unspecific_fallback_on_schema_issues'] = TRUE;

    #var_dump($this->getSolrHelper()->getSystemInfo());
  }
    /**
   * Modify the query before it is sent to solr.
   *
   * Replace all language unspecific fulltext query fields by language specific
   * ones and add a language filter if required.
   *
   * @param \Solarium\QueryType\Select\Query\Query $solarium_query
   *   The Solarium select query object.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The \Drupal\search_api\Query\Query object representing the executed
   *   search query.
   */
  protected function preQuery(Query $solarium_query, QueryInterface $query) {
    // Do not modify 'Server index status' queries. @see
    // https://www.drupal.org/node/2668852
    if ($query->hasTag('server_index_status')) {
      return;
    }

    parent::preQuery($solarium_query, $query);

    $language_ids = $this->getLanguageIdFiltersFromQuery($solarium_query, $query, TRUE);

    if (empty($language_ids)) {
      // If the query is generated by views and the query isn't limited by any
      // languages we have to search for all languages using their specific
      // fields.
      if (!$query->hasTag('views') && $this->configuration['sasm_limit_search_page_to_content_language']) {
        $language_ids[] = \Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
      }
      else {
        foreach (\Drupal::languageManager()->getLanguages() as $language) {
          $language_ids[] = $language->getId();
        }
      }
    }

    if (!empty($language_ids)) {
      $edismax = $solarium_query->getEDisMax();
      $query_fields = $edismax->getQueryFields();

      $index = $query->getIndex();
      $fulltext_fields = $index->getFulltextFields();
      $multiple_field_names = $this->getFieldNames($index);
      $single_field_names = $this->getFieldNames($index, TRUE);

      foreach($fulltext_fields as $fulltext_field) {
        foreach ([$single_field_names[$fulltext_field], $multiple_field_names[$fulltext_field]] as $field_name) {
          $boost = '';
          if (preg_match('@' . $field_name . '(^[\d.]?)@', $query_fields, $matches)) {
            $boost = $matches[1];
          }

          $language_specific_fields = [];
          foreach ($language_ids as $language_id) {
            $language_specific_field = Utility::getLanguageSpecificSolrDynamicFieldNameForSolrDynamicFieldName($field_name, $language_id);
            if ($this->isPartOfSchema('dynamicFields', $language_specific_field)) {
              $language_specific_fields[] = Utility::getLanguageSpecificSolrDynamicFieldNameForSolrDynamicFieldName($field_name, $language_id) . $boost;
            }
            elseif ($this->configuration['sasm_language_unspecific_fallback_on_schema_issues']) {
              $language_specific_fields[] = $field_name . $boost;
            }
          }

          $query_fields = str_replace(
            $field_name,
            implode(',', array_unique($language_specific_fields)),
            $query_fields
          );
        }
        $edismax->setQueryFields($query_fields);
      }

      $fq = new FilterQuery();
      $fq->setKey('sasm_language_filter');
      $fq->setQuery($single_field_names[SEARCH_API_LANGUAGE_FIELD_NAME] . ':("' . implode('" OR "', $language_ids) . '")');
      $solarium_query->addFilterQuery($fq);
    }
  }

  /**
   * @inheritdoc
   */
  protected function extractResults(QueryInterface $query, ResultInterface $result) {
    if ($this->configuration['retrieve_data']) {
      $language_ids = $this->getLanguageIdFiltersFromQuery($result->getQuery(), $query);
      $index = $query->getIndex();
      $single_field_names = $this->getFieldNames($index, TRUE);
      $data = $result->getData();
      foreach ($data['response']['docs'] as &$doc) {
        $language_id = $doc[$single_field_names[SEARCH_API_LANGUAGE_FIELD_NAME]];
        foreach (array_keys($doc) as $language_specific_field_name) {
          $field_name = Utility::getSolrDynamicFieldNameForLanguageSpecificSolrDynamicFieldName($language_specific_field_name);
          if ($field_name != $language_specific_field_name) {
            if (Utility::getLangaugeIdFromLanguageSpecificSolrDynamicFieldName($language_specific_field_name) == $language_id) {
              $doc[$field_name] = $doc[$language_specific_field_name];
            }
            unset($doc[$language_specific_field_name]);
          }
        }
      }

      $new_response = new Response(json_encode($data), $result->getResponse()->getHeaders());
      $result = new Result(NULL, $result->getQuery(), $new_response);
    }

    return parent::extractResults($query, $result);
  }

  /**
   * Modify the the solr result set.
   *
   * @param \Drupal\search_api\Query\ResultSetInterface $results
   *   The results array that will be returned for the search.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The \Drupal\search_api\Query\Query object representing the executed
   *   search query.
   * @param object $response
   *   The response object returned by Solr.
   */
  protected function postQuery(ResultSetInterface $results, QueryInterface $query, $response) {
    parent::postQuery($results, $query, $response);
 }

  /**
   * Replaces language unspecific fulltext fields by language specific ones.
   *
   * @param \Solarium\QueryType\Update\Query\Document\Document[] $documents
   *   An array of \Solarium\QueryType\Update\Query\Document\Document objects
   *   ready to be indexed, generated from $items array.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index for which items are being indexed.
   * @param array $items
   *   An array of items being indexed.
   *
   * @see hook_search_api_solr_documents_alter()
   */
  protected function alterSolrDocuments(array &$documents, IndexInterface $index, array $items) {
    parent::alterSolrDocuments($documents, $index, $items);

    $fulltext_fields = $index->getFulltextFields();
    $multiple_field_names = $this->getFieldNames($index);
    $single_field_names = $this->getFieldNames($index, TRUE);
    $fulltext_field_names = array_filter(array_flip($multiple_field_names) + array_flip($single_field_names),
      function($value) use ($fulltext_fields) {
        return in_array($value, $fulltext_fields);
      }
    );

    $field_name_map_per_language = [];
    foreach ($documents as $document) {
      $fields = $document->getFields();
      $language_id = $fields[$single_field_names[SEARCH_API_LANGUAGE_FIELD_NAME]];
      foreach ($fields as $monolingual_solr_field_name => $field_value) {
        if (array_key_exists($monolingual_solr_field_name, $fulltext_field_names)) {
          $multilingual_solr_field_name = Utility::getLanguageSpecificSolrDynamicFieldNameForSolrDynamicFieldName($monolingual_solr_field_name, $language_id);
          $field_name_map_per_language[$language_id][$monolingual_solr_field_name] = $multilingual_solr_field_name;
        }
      }
    }

    foreach ($field_name_map_per_language as $language_id => $map) {
      $solr_field_type_name = 'text' . '_' . $language_id;
      if (!$this->isPartOfSchema('fieldTypes', $solr_field_type_name) &&
        !$this->createSolrFieldType($solr_field_type_name)
      ) {
        if ($this->configuration['sasm_language_unspecific_fallback_on_schema_issues']) {
          unset($field_name_map_per_language[$language_id]);
        }
        else {
          // @todo Check if a non-language-spefific field type could be replaced by
          // a language-specific one that has been missing before or if a concrete
          // one has been assigned by the adminstrator, for example filed type
          // text_de for language de_AT.
          // If the field type is exchanged, trigger a re-index process.

          throw new SearchApiSolrMultilingualException('Missing field type ' . $solr_field_type_name . ' in schema.');
        }
      }

      foreach ($map as $monolingual_solr_field_name => $multilingual_solr_field_name) {
        // Handle dynamic fields for multilingual tm and ts.
        foreach (['ts', 'tm'] as $prefix) {
          $multilingual_solr_field_name = SearchApiSolrUtility::encodeSolrDynamicFieldName(Utility::getLanguageSpecificSolrDynamicFieldPrefix($prefix, $language_id)) . '*';
          if (!$this->isPartOfSchema('dynamicFields', $multilingual_solr_field_name) &&
            !$this->createSolrDynamicField($multilingual_solr_field_name, $solr_field_type_name)
          ) {
            if ($this->configuration['sasm_language_unspecific_fallback_on_schema_issues']) {
              unset($field_name_map_per_language[$language_id][$monolingual_solr_field_name]);
            }
            else {
              throw new SearchApiSolrMultilingualException('Missing dynamic field ' . $multilingual_solr_field_name . ' in schema.');
            }
          }
        }
      }
    }

    foreach ($documents as $document) {
      foreach ($field_name_map_per_language as $language_id => $map) {
        foreach ($map as $monolingual_solr_field_name => $multilingual_solr_field_name) {
          $document->addField($multilingual_solr_field_name, $document->{'get' . $monolingual_solr_field_name}(), $document->getFieldBoost($monolingual_solr_field_name));
          // @todo removal should be configurable
          $document->removeField($monolingual_solr_field_name);
        }
      }
    }
  }

  /**
   * Get all languages a solarium query is filtered by.
   *
   * @param \Solarium\QueryType\Select\Query\Query $solarium_query
   *   The solarium query.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search_api query the solarium query has been built from.
   * @param bool $remove
   *   Wether the language filters should be removed from the solarium query or
   *   not. Default is FALSE.
   * @return array
   *   Array of language ids.
   */
  protected function getLanguageIdFiltersFromQuery(Query $solarium_query, QueryInterface $query, $remove = FALSE) {
    $language_ids = [];
    $multiple_field_names = $this->getFieldNames($query->getIndex());
    $single_field_names = $this->getFieldNames($query->getIndex(), TRUE);
    $filter_queries = $solarium_query->getFilterQueries();
    foreach ($filter_queries as $filter_query_name => $filter_query) {
      $query_string = $filter_query->getQuery();
      foreach ([$single_field_names[SEARCH_API_LANGUAGE_FIELD_NAME], $multiple_field_names[SEARCH_API_LANGUAGE_FIELD_NAME]] as $field_name) {
        if (preg_match_all('@' . preg_quote($field_name, '@') . ':"(.+?)"@', $query_string, $matches)) {
          foreach ($matches[1] as $match) {
            $language_ids[] = trim($match, '"');
          }
          if ($remove) {
            $solarium_query->removeFilterQuery($filter_query_name);
          }
        }
      }
    }
    return array_unique($language_ids);
  }

  protected function isPartOfSchema($kind, $name) {
    static $previous_calls;

    $state_key = 'sasm.' . $this->getServer()->id() . '.schema_parts';
    $state = \Drupal::state();
    $schema_parts = $state->get($state_key);
    // @todo reset that drupal state from time to time

    if (isset($previous_calls[$kind])) {
      return in_array($name, $schema_parts[$kind]);
    }

    $previous_calls[$kind] = TRUE;

    if (!is_array($schema_parts) || !isset($schema_parts[$kind]) || !in_array($name, $schema_parts[$kind])) {
      $schema_parts[$kind] = [];
      $response = $this->solrRestGet('schema/' . strtolower($kind));
      foreach ($response[$kind] as $row) {
        $schema_parts[$kind][] = $row['name'];
      }
      $state->set($state_key, $schema_parts);
    }

    return in_array($name, $schema_parts[$kind]);
  }

  /**
   * Sends a REST GET request and return the result.
   *
   * @param string $path The path to append to the base URI
   * @return string The decoded response
   */
  protected function solrRestGet($path) {
    $uri = $this->solr->getEndpoint()->getBaseUri() . $path;
    $client = \Drupal::service('http_client');
    $result = $client->get($uri, ['Accept' => 'application/json']);
    $output = Json::decode($result->getBody());
    // \Drupal::logger('search_api_solr_multilingual')->info(print_r($output, true));
    if (!empty($output['errors'])) {
      throw new SearchApiSolrMultilingualException("Error trying to send a REST GET request to '$uri'" .
        "\nError message(s):" . print_r($output['errors'], TRUE));
    }
    return $output;
  }

  /**
   * Sends a REST POST request and return the result.
   *
   * @param string $path The path to append to the base URI
   * @param string $command_json The JSON-encoded data.
   * @return string The decoded response
   * @see https://cwiki.apache.org/confluence/display/solr/Schema+API
   */
  protected function solrRestPost($path, $command_json) {
    $uri = $this->solr->getEndpoint()->getBaseUri() . $path;
    /** @var \GuzzleHttp\Client $client */
    $client = \Drupal::service('http_client');
    $result = $client->post($uri, [
      'body' => $command_json,
      'headers' => [
        'Accept' => 'application/json',
        'Content-type' => 'application/json'
      ],
    ]);
    $output = Json::decode($result->getBody());
    // \Drupal::logger('search_api_solr_multilingual')->info(print_r($output, true));
    if (!empty($output['errors'])) {
      throw new SearchApiSolrMultilingualException("Error trying to send the following JSON to Solr (REST POST request to '$uri'): " . $command_json .
          "\nError message(s):" . print_r($output['errors'], TRUE));
    }
    return $output;
  }

}
