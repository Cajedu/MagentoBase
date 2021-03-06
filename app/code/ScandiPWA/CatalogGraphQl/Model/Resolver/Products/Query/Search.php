<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ScandiPWA\CatalogGraphQl\Model\Resolver\Products\Query;

use Exception;
use Magento\CatalogGraphQl\DataProvider\Product\SearchCriteriaBuilder;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\ProductSearch;
use Magento\CatalogGraphQl\Model\Resolver\Products\Query\FieldSelection;
use Magento\CatalogGraphQl\Model\Resolver\Products\Query\Search as CoreSearch;
use Magento\CatalogGraphQl\Model\Resolver\Products\SearchResult;
use Magento\CatalogGraphQl\Model\Resolver\Products\SearchResultFactory;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchCriteriaInterfaceFactory;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Search\Api\SearchInterface;
use Magento\Search\Model\Search\PageSizeProvider;
use ScandiPWA\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product\CriteriaCheck;
use ScandiPWA\Performance\Model\Resolver\Products\DataPostProcessor;

/**
 * Full text search for catalog using given search criteria.
 */
class Search extends CoreSearch
{
    /**
     * @var SearchInterface
     */
    private $search;

    /**
     * @var SearchResultFactory
     */
    private $searchResultFactory;

    /**
     * @var PageSizeProvider
     */
    private $pageSizeProvider;

    /**
     * @var SearchCriteriaInterfaceFactory
     */
    private $searchCriteriaFactory;

    /**
     * @var FieldSelection
     */
    private $fieldSelection;

    /**
     * @var ProductSearch
     */
    private $productsProvider;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var DataPostProcessor
     */
    protected $productPostProcessor;

    /**
     * @param SearchInterface $search
     * @param SearchResultFactory $searchResultFactory
     * @param PageSizeProvider $pageSize
     * @param SearchCriteriaInterfaceFactory $searchCriteriaFactory
     * @param FieldSelection $fieldSelection
     * @param ProductSearch $productsProvider
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param DataPostProcessor $productPostProcessor
     */
    public function __construct(
        SearchInterface $search,
        SearchResultFactory $searchResultFactory,
        PageSizeProvider $pageSize,
        SearchCriteriaInterfaceFactory $searchCriteriaFactory,
        FieldSelection $fieldSelection,
        ProductSearch $productsProvider,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        DataPostProcessor $productPostProcessor
    )
    {
        parent::__construct(
            $search,
            $searchResultFactory,
            $pageSize,
            $searchCriteriaFactory,
            $fieldSelection,
            $productsProvider,
            $searchCriteriaBuilder
        );

        $this->search = $search;
        $this->searchResultFactory = $searchResultFactory;
        $this->pageSizeProvider = $pageSize;
        $this->searchCriteriaFactory = $searchCriteriaFactory;
        $this->fieldSelection = $fieldSelection;
        $this->productsProvider = $productsProvider;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->productPostProcessor = $productPostProcessor;
    }

    /**
     * Return product search results using Search API
     *
     * @param array $args
     * @param ResolveInfo $info
     * @return SearchResult
     * @throws Exception
     */
    public function getResult(
        array $args,
        ResolveInfo $info
    ): SearchResult
    {
        $queryFields = $this->fieldSelection->getProductsFieldSelection($info);
        $searchCriteria = $this->buildSearchCriteria($args, $info);

        $realPageSize = $searchCriteria->getPageSize();
        $realCurrentPage = $searchCriteria->getCurrentPage();
        //Because of limitations of sort and pagination on search API we will query all IDS
        $pageSize = $this->pageSizeProvider->getMaxPageSize();
        $searchCriteria->setPageSize($pageSize);
        $searchCriteria->setCurrentPage(0);
        $itemsResults = $this->search->search($searchCriteria);

        //Address limitations of sort and pagination on search API apply original pagination from GQL query
        $searchCriteria->setPageSize($realPageSize);
        $searchCriteria->setCurrentPage($realCurrentPage);
        $searchResults = $this->productsProvider->getList($searchCriteria, $itemsResults, $queryFields);

        $totalPages = $realPageSize ? ((int)ceil($searchResults->getTotalCount() / $realPageSize)) : 0;

        $searchCriteria->setPageSize($realPageSize);
        $searchCriteria->setCurrentPage($realCurrentPage);

        // Following lines are changed
        if (count($queryFields) > 0) {
            $productArray = $this->productPostProcessor->process(
                $searchResults->getItems(),
                'products/items',
                $info,
                ['isSingleProduct' => CriteriaCheck::isSingleProductFilter($searchCriteria)]
            );
        } else {
            $productArray = array_map(static function ($product) {
                return $product->getData() + ['model' => $product];
            }, $searchResults->getItems());
        }

        return $this->searchResultFactory->create(
            [
                'totalCount' => $searchResults->getTotalCount(),
                'productsSearchResult' => $productArray,
                'searchAggregation' => $itemsResults->getAggregations(),
                'pageSize' => $realPageSize,
                'currentPage' => $realCurrentPage,
                'totalPages' => $totalPages,
            ]
        );
    }

    /**
     * Build search criteria from query input args
     *
     * @param array $args
     * @param ResolveInfo $info
     * @return SearchCriteriaInterface
     */
    private function buildSearchCriteria(array $args, ResolveInfo $info): SearchCriteriaInterface
    {
        $productFields = (array)$info->getFieldSelection(1);
        $includeAggregations = isset($productFields['filters']) || isset($productFields['aggregations']);
        return $this->searchCriteriaBuilder->build($args, $includeAggregations);
    }
}
