<?php
/**
 * SimpleSearch
 *
 * Copyright 2010-11 by Shaun McCormick <shaun+sisea@modx.com>
 *
 * This file is part of SimpleSearch, a simple search component for MODx
 * Revolution. It is loosely based off of AjaxSearch for MODx Evolution by
 * coroico/kylej, minus the ajax.
 *
 * SimpleSearch is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * SimpleSearch is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with
 * SimpleSearch; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package simplesearch
 */
require_once dirname(__FILE__) . '/simplesearchdriver.class.php';
/**
 * ElasticSearch search driver for SimpleSearch.
 * @package simplesearch
 */
class SimpleSearchDriverElastic extends SimpleSearchDriver {
    /** @var array An array of connection properties for our SolrClient */
    private $_connectionOptions = array();

    /** @var \Elastica\Client $var */
    public $client;
    /** @var \Elastica\Index $index */
    public $index;

    /**
     * Initialize the ElasticSearch client, and setup settings for the client.
     * 
     * @return void
     */
    public function initialize() {

        spl_autoload_register(function($class){

            $file = $this->modx->getOption('sisea.core_path', null, $this->modx->getOption('core_path').'components/simplesearch/');
            $file .= 'model/simplesearch/driver/libs/' . $class . '.php';

            $file = str_replace('\\', '/', $file);

            if (file_exists($file)) {
                require_once($file);
            }

        });

        $this->_connectionOptions = array(
            'hostname' => $this->modx->getOption('sisea.elastic.hostname', null, '127.0.0.1'),
            'port' => $this->modx->getOption('sisea.elastic.port', null, 9200),
        );

        try {
            $this->client = new \Elastica\Client($this->_connectionOptions);
            $this->index = $this->client->getIndex($this->modx->getOption('sisea.elastic.index', null, 'SimpleSearchIndex'));
            if(!$this->index->exists()){
                $this->index->create(
                    array(
                         'number_of_shards' => 5,
                         'number_of_replicas' => 1,
                         'analysis' => array(
                             'analyzer' => array(
                                 'default_index' => array(
                                     "type" => "custom",
                                     "tokenizer" => "whitespace",
                                     "filter" => array("asciifolding", "standard", "lowercase", "haystack_edgengram")
                                 ),
                                 'default_search' => array(
                                     "type" => "custom",
                                     "tokenizer" => "whitespace",
                                     "filter" => array("asciifolding", "standard", "lowercase", "haystack_edgengram")
                                 )
                             ),
                             "filter" => array(
                                 "haystack_ngram"=> array(
                                    "type" => "nGram",
                                    "min_gram" => 2,
                                    "max_gram" => 30,
                                 ),
                                "haystack_edgengram" => array(
                                    "type" => "edgeNGram",
                                    "min_gram" => 2,
                                    "max_gram" => 30,
                                )
                            )
                        )
                    ),
                    true
                );
            }
        } catch (Exception $e) {
            $this->modx->log(xPDO::LOG_LEVEL_ERROR,'Error connecting to ElasticSearch server: '.$e->getMessage());
        }
    }

    /**
     * Run the search against a sanitized query string via ElasticSearch.
     *
     * @param string $string
     * @param array $scriptProperties The scriptProperties array from the SimpleSearch snippet
     * @return array
     */
    public function search($string,array $scriptProperties = array()) {
        /** @var \Elastica\Query\QueryString $query */
        $query = new \Elastica\Query\QueryString();
        $query->setDefaultOperator('AND');
        $query->setQuery($string);

        /** @var \Elastica\Query $elasticaQuery */
        $elasticaQuery = new \Elastica\Query();
        $elasticaQuery->setQuery($query);

    	/* set limit */
        $perPage = $this->modx->getOption('perPage',$scriptProperties,10);
    	if (!empty($perPage)) {
            $offset = $this->modx->getOption('start',$scriptProperties,0);
            $offsetIndex = $this->modx->getOption('offsetIndex',$scriptProperties,'sisea_offset');
            if (isset($_REQUEST[$offsetIndex])) $offset = (int)$_REQUEST[$offsetIndex];
            $elasticaQuery->setFrom($offset);
            $elasticaQuery->setSize($perPage);
    	}

        $elasticaFilterAnd = new \Elastica\Filter\BoolAnd();

        /* handle hidemenu option */
        $hideMenu = $this->modx->getOption('hideMenu',$scriptProperties,2);
        if ($hideMenu != 2) {
            $elasticaFilterHideMenu  = new \Elastica\Filter\Term();
            $elasticaFilterHideMenu->setTerm('hidemenu', ($hideMenu ? 1 : 0));
            $elasticaFilterAnd->addFilter($elasticaFilterHideMenu);
        }

        /* handle contexts */
        $contexts = $this->modx->getOption('contexts',$scriptProperties,'');
        $contexts = !empty($contexts) ? $contexts : $this->modx->context->get('key');
        $contexts = explode(',',$contexts);
        $elasticaFilterContext  = new \Elastica\Filter\Term();
        $elasticaFilterContext->setTerm('context_key', $contexts);
        $elasticaFilterAnd->addFilter($elasticaFilterContext);

        /* handle restrict search to these IDs */
        $ids = $this->modx->getOption('ids',$scriptProperties,'');
    	if (!empty($ids)) {
            $idType = $this->modx->getOption('idType',$this->config,'parents');
            $depth = $this->modx->getOption('depth',$this->config,10);
            $ids = $this->processIds($ids,$idType,$depth);
            $elasticaFilterId  = new \Elastica\Filter\Term();
            $elasticaFilterId->setTerm('id', $ids);
            $elasticaFilterAnd->addFilter($elasticaFilterId);

        }

        /* handle exclude IDs from search */
        $exclude = $this->modx->getOption('exclude',$scriptProperties,'');
        if (!empty($exclude)) {
            $exclude = $this->cleanIds($exclude);
            $exclude = explode(',', $exclude);
            $elasticaFilterExcludeId  = new \Elastica\Filter\Term();
            $elasticaFilterExcludeId->setTerm('id', $exclude);
            $elasticaFilterNotId = new \Elastica\Filter\BoolNot($elasticaFilterExcludeId);
            $elasticaFilterAnd->addFilter($elasticaFilterNotId);
        }

        /* basic always-on conditions */
        $elasticaFilterPublished  = new \Elastica\Filter\Term();
        $elasticaFilterPublished->setTerm('published', 1);
        $elasticaFilterAnd->addFilter($elasticaFilterPublished);

        $elasticaFilterSearchable  = new \Elastica\Filter\Term();
        $elasticaFilterSearchable->setTerm('searchable', 1);
        $elasticaFilterAnd->addFilter($elasticaFilterSearchable);

        $elasticaFilterDeleted  = new \Elastica\Filter\Term();
        $elasticaFilterDeleted->setTerm('deleted', 0);
        $elasticaFilterAnd->addFilter($elasticaFilterDeleted);

        $elasticaQuery->setFilter($elasticaFilterAnd);

        /* sorting */
        if (!empty($scriptProperties['sortBy'])) {
            $sortDir = $this->modx->getOption('sortDir',$scriptProperties,'desc');
            $sortDirs = explode(',',$sortDir);
            $sortBys = explode(',',$scriptProperties['sortBy']);
            $dir = 'desc';
            $sortArray = array();
            for ($i=0;$i<count($sortBys);$i++) {
                if (isset($sortDirs[$i])) {
                    $dir= $sortDirs[$i];
                }
                $sortArray[] = array($sortBys[$i] => $dir);

            }

            $elasticaQuery->setSort($sortArray);
        }

        /* prepare response array */
        $response = array(
            'total' => 0,
            'start' => !empty($offset) ? $offset : 0,
            'limit' => $perPage,
            'status' => 0,
            'query_time' => 0,
            'results' => array(),
        );

        $elasticaResultSet = $this->index->search($elasticaQuery);

        $elasticaResults  = $elasticaResultSet->getResults();
        $totalResults         = $elasticaResultSet->getTotalHits();

        if ($totalResults > 0) {
            $response['total'] = $totalResults;
            $response['query_time'] = $elasticaResultSet->getTotalTime();
            $response['status'] = 1;
            $response['results'] = array();
            foreach ($elasticaResults as $doc) {
                $d = $doc->getData();

                /** @var modResource $resource */
                $resource = $this->modx->newObject($d['class_key']);
                if ($resource->checkPolicy('list')) {
                    $response['results'][] = $d;
                }
            }
        }

        return $response;
    }

    /**
     * Index a Resource.
     *
     * @param array $fields
     * @return boolean
     */
    public function index(array $fields = array()) {
        if (isset($fields['searchable']) && empty($fields['searchable'])) return false;
        if (isset($fields['published']) && empty($fields['published'])) return false;
        if (isset($fields['deleted']) && !empty($fields['deleted'])) return false;

        $type = $this->index->getType('resource');
        $document = new \Elastica\Document();
        $dateFields = array('createdon','editedon','deletedon','publishedon');
        foreach ($fields as $fieldName => $value) {
            if (is_string($fieldName) && !is_array($value) && !is_object($value)) {
                if (in_array($fieldName,$dateFields)) {
                    $value = ''.strftime('%Y-%m-%dT%H:%M:%SZ',strtotime($value));
                    $fields[$fieldName] = $value;
                }
                if($fieldName == 'id'){
                    $document->setId($value);
                }
                $document->set($fieldName,$value);
            }
        }
        $this->modx->log(modX::LOG_LEVEL_DEBUG,'[SimpleSearch] Indexing Resource: '.print_r($fields,true));

        $response = $type->addDocument($document);

        $type->getIndex()->refresh();
        return $response->isOk();
    }

    /**
     * Remove a Resource from the ElasticSearch index.
     *
     * @param string|int $id
     * @return boolean
     */
    public function removeIndex($id) {
        $this->modx->log(modX::LOG_LEVEL_DEBUG,'[SimpleSearch] Removing Resource From Index: '.$id);
        $type = $this->index->getType('resource');
        $type->deleteById($id);
        $type->getIndex()->refresh();
    }
}
