<?php
/**
 * ScandiPWA_CatalogGraphQl
 *
 * @category ScandiPWA
 * @package ScandiPWA_CatalogGraphQl
 * @author Daniels Puzina <info@scandiweb.com>
 * @copyright Copyright (c) 2020 Scandiweb, Ltd (https://scandiweb.com)
 */

namespace ScandiPWA\CatalogGraphQl\SearchAdapter\Query\Builder;

use Magento\Framework\Search\Adapter\Preprocessor\PreprocessorInterface;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\AttributeProvider;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\FieldProvider\FieldType\ResolverInterface as TypeResolver;
use Magento\Elasticsearch\Model\Config;
use Magento\Elasticsearch\SearchAdapter\Query\ValueTransformerPool;
use Magento\Elasticsearch\Model\Adapter\FieldMapperInterface;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\Match as CoreMatch;
use function spl_object_hash;

/**
 * Class Match
 * @package ScandiPWA\CatalogGraphQl\SearchAdapter\Query\Builder
 */
class Match extends CoreMatch
{
    /**
     * Define fuzziness level of search query
     */
    public const FUZZINESS_LEVEL = 'AUTO';

    /**
     * @var FieldMapperInterface
     */
    private $fieldMapper;

    /**
     * @deprecated 100.3.2
     * @see \Magento\Elasticsearch\SearchAdapter\Query\ValueTransformer\TextTransformer
     * @var PreprocessorInterface[]
     */
    protected $preprocessorContainer;

    /**
     * @var AttributeProvider
     */
    private $attributeProvider;

    /**
     * @var TypeResolver
     */
    private $fieldTypeResolver;

    /**
     * @var ValueTransformerPool
     */
    private $valueTransformerPool;
    /**
     * @var Config
     */
    private $config;

    /**
     * @param FieldMapperInterface $fieldMapper
     * @param PreprocessorInterface[] $preprocessorContainer
     * @param AttributeProvider|null $attributeProvider
     * @param TypeResolver|null $fieldTypeResolver
     * @param ValueTransformerPool|null $valueTransformerPool
     * @param Config $config
     */
    public function __construct(
        FieldMapperInterface $fieldMapper,
        array $preprocessorContainer,
        AttributeProvider $attributeProvider,
        TypeResolver $fieldTypeResolver,
        ValueTransformerPool $valueTransformerPool,
        Config $config
    )
    {
        $this->fieldMapper = $fieldMapper;
        $this->preprocessorContainer = $preprocessorContainer;
        $this->attributeProvider = $attributeProvider;
        $this->fieldTypeResolver = $fieldTypeResolver;
        $this->valueTransformerPool = $valueTransformerPool;
        $this->config = $config;

        parent::__construct(
            $fieldMapper,
            $preprocessorContainer,
            $attributeProvider,
            $fieldTypeResolver,
            $valueTransformerPool
        );
    }

    /**
     * Creates valid ElasticSearch search conditions from Match queries.
     *
     * The purpose of this method is to create a structure which represents valid search query
     * for a full-text search.
     * It sets search query condition, the search query itself, and sets the search query boost.
     *
     * The search query boost is an optional in the search query and therefore it will be set to 1 by default
     * if none passed with a match query.
     *
     * @param array $matches
     * @param array $queryValue
     * @return array
     */
    protected function buildQueries(array $matches, array $queryValue): array
    {
        $conditions = [];

        // Checking for quoted phrase \"phrase test\", trim escaped surrounding quotes if found
        $count = 0;
        $value = preg_replace('#^"(.*)"$#m', '$1', $queryValue['value'], -1, $count);
        $condition = ($count) ? 'match_phrase' : 'match';

        $transformedTypes = [];
        foreach ($matches as $match) {
            $resolvedField = $this->fieldMapper->getFieldName(
                $match['field'],
                ['type' => FieldMapperInterface::TYPE_QUERY]
            );

            $attributeAdapter = $this->attributeProvider->getByAttributeCode($resolvedField);
            $fieldType = $this->fieldTypeResolver->getFieldType($attributeAdapter);
            $valueTransformer = $this->valueTransformerPool->get($fieldType ?? 'text');
            $valueTransformerHash = spl_object_hash($valueTransformer);
            if (!isset($transformedTypes[$valueTransformerHash])) {
                $transformedTypes[$valueTransformerHash] = $valueTransformer->transform($value);
            }
            $transformedValue = $transformedTypes[$valueTransformerHash];
            if (null === $transformedValue) {
                //Value is incompatible with this field type.
                continue;
            }
            $matchCondition = $match['matchCondition'] ?? $condition;
            $conditions[] = [
                'condition' => $queryValue['condition'],
                'body' => [
                    $matchCondition => [
                        $resolvedField => [
                            'query' => $transformedValue,
                            'boost' => $match['boost'] ?? 1,
                            'fuzziness' => self::FUZZINESS_LEVEL
                        ],
                    ],
                ],
            ];
        }

        return $conditions;
    }
}
