<?php

namespace Marmelab\SonataElasticaBundle\Repository;

use Elastica\Filter\Range;
use Elastica\Query;
use Elastica\Query\Bool as QueryBool;
use Elastica\Query\Filtered;
use Elastica\Query\QueryString as QueryText;
use Elastica\Query\AbstractQuery;
use Elastica\Query\Wildcard;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Sonata\AdminBundle\Admin\AdminInterface;

class ElasticaProxyRepository
{
    const MINIMUM_SEARCH_TERM_LENGTH = 2;

    /** @var  FinderInterface */
    protected $finder;

    /** @var  string */
    protected $modelIdentifier;

    /** @var  array */
    protected $fieldsMapping;

    /** @var AdminInterface */
    protected $admin;

    /**
     * @param FinderInterface $finder
     * param  array           $fieldsMapping
     *
     * @return $this
     */
    public function __construct(FinderInterface $finder, array $fieldsMapping)
    {
        $this->finder = $finder;
        $this->fieldsMapping = $fieldsMapping;
    }

    /**
     * @param AdminInterface $admin
     */
    public function setAdmin(AdminInterface $admin)
    {
        $this->admin = $admin;
    }

    /**
     * @param string $modelIdentifier
     *
     * @return $this
     */
    public function setModelIdentifier($modelIdentifier)
    {
        $this->modelIdentifier = $modelIdentifier;

        return $this;
    }

    /**
     * @param int $start
     * @param int $limit
     * @param string $sortBy
     * @param string $sortOrder
     * @param array $params
     *
     * @return int
     */
    public function getTotalResult($start, $limit, $sortBy, $sortOrder, $params)
    {
        $results = $this->findAll($start, $limit, $sortBy, $sortOrder, $params);

        return count($results);
    }

    /**
     * @param int $start
     * @param int $limit
     * @param string $sortBy
     * @param string $sortOrder
     * @param array $params
     *
     * @return int
     */
    public function findAll($start, $limit, $sortBy, $sortOrder = 'ASC', $params)
    {
        $query = count($params) ? $this->createFilterQuery($params) : new Query();
        $query->setFrom($start);

        // Sort & order
        $fieldName = (isset($sortBy['fieldName'])) ? $sortBy['fieldName'] : null;

        if ($fieldName !== null && $fieldName !== $this->modelIdentifier) {

            if (isset($this->fieldsMapping[$fieldName])) {
                $fieldName = $this->fieldsMapping[$fieldName];
            }
            $query->setSort(array($fieldName => array('order' => strtolower($sortOrder))));
        } else {
            $query->setSort(array(
                $this->modelIdentifier => array('order' => strtolower($sortOrder)),
            ))
            ;
        }

        // Custom filter for admin
        if (method_exists($this->admin, 'getExtraFilter')) {
            $query->setFilter($this->admin->getExtraFilter());
        }

        // Limit
        if ($limit === null) {
            $limit = 10000; // set to 0 does not seem to work
        }

        return $this->finder->find($query, $limit);
    }

    /**
     * Useful for debugging with elastic head plugin
     *
     * @param \Elastica\Query $query
     *
     * @return string
     */
    protected function getQueryString($query)
    {
        $wrapper = ($query instanceof AbstractQuery) ? array('query' => $query->toArray()) : $query->toArray();

        return json_encode($wrapper);
    }

    /**
     * @param array $params
     *
     * @return Query
     */
    protected function createFilterQuery(array $params)
    {
        $mainQuery = new QueryBool();
        foreach ($params as $name => $value) {
            if ((is_string($value) && strlen($value) < self::MINIMUM_SEARCH_TERM_LENGTH) || !$value || $value == '') {
                continue;
            }

            $fieldName = str_replace('_elastica', '', $name);

            $filters = $this->admin->getFilterFieldDescriptions();
            $type = $filters[$fieldName]->getFieldMapping()['type'];

            //$type = $this->admin->getForm()->get($fieldName)->getConfig()->getType()->getName();

            if ($type === 'datetime') {
                $rangeLower = new Filtered(
                    $mainQuery,
                    new Range($fieldName, array(
                        'gte' => $this->parseDate($params[$fieldName]['start'])
                    ))
                );

                $rangeUpper = new Filtered(
                    $rangeLower,
                    new Range($fieldName, array(
                        'lte' => $this->parseDate($params[$fieldName]['end'])
                    ))
                );

                $mainQuery->addMust($rangeUpper);

                continue;
            }

            $fieldQuery = new QueryText($value);
            $fieldQuery->setFields([$fieldName]);
            $mainQuery->addMust($fieldQuery);
        }

        if (!count($params)) {
            $all = new QueryText('*');
            $all->setFields(['_all']);
            $all->setAllowLeadingWildcard();
            $mainQuery->addMust($all);
        }

        return new Query($mainQuery);
    }

    private function parseDate($date)
    {
        return $date['date']['year'] . '-' . $date['date']['month'] . '-' . $date['date']['day'];
    }
}
