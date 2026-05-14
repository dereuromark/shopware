<?php declare(strict_types=1);

namespace Shopware\Core\Content\Seo;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\QueryBuilder;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * @phpstan-import-type ResolvedSeoUrlArray from AbstractSeoResolver
 */
#[Package('inventory')]
class SeoResolver extends AbstractSeoResolver
{
    /**
     * @internal
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getDecorated(): AbstractSeoResolver
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @deprecated tag:v6.8.0 - will be removed in v6.8.0, use {@see resolveUrl()} instead
     *
     * @return ResolvedSeoUrlArray
     */
    public function resolve(string $languageId, string $salesChannelId, string $pathInfo): array
    {
        Feature::triggerDeprecationOrThrow(
            'v6.8.0.0',
            Feature::deprecatedMethodMessage(self::class, __METHOD__, 'v6.8.0.0', self::class . '::resolveUrl()')
        );

        return $this->resolveUrl(new SeoUrlRequestContext($languageId, $salesChannelId, $pathInfo))->toArray();
    }

    public function resolveUrl(SeoUrlRequestContext $context): ResolvedSeoUrl
    {
        $seoPathInfo = trim($context->pathInfo, '/');
        $normalizedQueryString = self::normalizeQueryString($context->queryString);

        $query = (new QueryBuilder($this->connection))
            ->select('id', 'path_info pathInfo', 'seo_path_info seoPathInfo', 'is_canonical isCanonical', 'sales_channel_id salesChannelId')
            ->from('seo_url')
            ->where('language_id = :language_id')
            ->andWhere('(sales_channel_id = :sales_channel_id OR sales_channel_id IS NULL)')
            ->andWhere('seo_url.is_deleted = 0');

        $seoPathConditions = [
            'seo_path_info = :seoPath',
            'seo_path_info = :seoPathWithSlash',
        ];

        $query->setParameter('language_id', Uuid::fromHexToBytes($context->languageId))
            ->setParameter('sales_channel_id', Uuid::fromHexToBytes($context->salesChannelId))
            ->setParameter('seoPath', $seoPathInfo)
            ->setParameter('seoPathWithSlash', $seoPathInfo . '/');

        if ($normalizedQueryString !== null) {
            $seoPathConditions[] = 'seo_path_info = :seoPathWithQuery';
            $seoPathConditions[] = 'seo_path_info = :seoPathWithSlashAndQuery';
            $query->setParameter('seoPathWithQuery', $seoPathInfo . '?' . $normalizedQueryString)
                ->setParameter('seoPathWithSlashAndQuery', $seoPathInfo . '/?' . $normalizedQueryString);
        }

        $query->andWhere('(' . implode(' OR ', $seoPathConditions) . ')');
        $query->setTitle('seo-url::resolve');

        $seoPaths = $query->executeQuery()->fetchAllAssociative();

        usort($seoPaths, static function ($a, $b) use ($normalizedQueryString) {
            // If a request query string is present, prefer the row whose stored
            // seo_path_info has a query that matches it. This makes
            // `path?test=5.2` win over plain `path` when the request also has
            // `?test=5.2`, while preserving the existing plain-vs-plain tie
            // breakers below.
            if ($normalizedQueryString !== null) {
                $aMatches = self::storedQueryMatches($a['seoPathInfo'] ?? null, $normalizedQueryString);
                $bMatches = self::storedQueryMatches($b['seoPathInfo'] ?? null, $normalizedQueryString);
                if ($aMatches !== $bMatches) {
                    return $aMatches ? -1 : 1;
                }
            }

            if ($a['isCanonical'] === null) {
                return 1;
            }

            if ($b['isCanonical'] === null) {
                return -1;
            }

            if ($a['salesChannelId'] === null) {
                return 1;
            }

            if ($b['salesChannelId'] === null) {
                return -1;
            }

            return 0;
        });

        $seoPath = ['pathInfo' => $seoPathInfo, 'isCanonical' => false];

        foreach ($seoPaths as $path) {
            $seoPath = $path;
            if ($path['isCanonical']) {
                break;
            }
        }

        if ($seoPath['isCanonical'] && isset($seoPath['seoPathInfo']) && \is_string($seoPath['seoPathInfo'])) {
            $storedQueryString = parse_url($seoPath['seoPathInfo'], \PHP_URL_QUERY);
            $normalizedStoredQueryString = self::normalizeQueryString(\is_string($storedQueryString) ? $storedQueryString : null);

            if ($normalizedStoredQueryString !== null && $normalizedStoredQueryString !== $normalizedQueryString) {
                $seoPath['canonicalPathInfo'] = '/' . ltrim($seoPath['seoPathInfo'], '/');
            }
        }

        if (!$seoPath['isCanonical']) {
            $query = (new QueryBuilder($this->connection))
                ->select('path_info pathInfo', 'seo_path_info seoPathInfo')
                ->from('seo_url')
                ->where('language_id = :language_id')
                ->andWhere('sales_channel_id = :sales_channel_id')
                ->andWhere('path_info = :pathInfo')
                ->andWhere('is_canonical = 1')
                ->andWhere('is_deleted = 0')
                ->setMaxResults(1)
                ->setParameter('language_id', Uuid::fromHexToBytes($context->languageId))
                ->setParameter('sales_channel_id', Uuid::fromHexToBytes($context->salesChannelId))
                ->setParameter('pathInfo', '/' . ltrim((string) $seoPath['pathInfo'], '/'));

            $query->setTitle('seo-url::resolve-fallback');

            // we only have an id when the hit seo url was not a canonical url, save the one filter condition
            if (isset($seoPath['id'])) {
                $query->andWhere('id != :id')
                    ->setParameter('id', $seoPath['id']);
            }

            $canonicalQueryResult = $query->executeQuery()->fetchAssociative();
            if ($canonicalQueryResult) {
                $seoPath['canonicalPathInfo'] = '/' . ltrim((string) $canonicalQueryResult['seoPathInfo'], '/');
            }
        }

        $seoPath['pathInfo'] = '/' . ltrim((string) $seoPath['pathInfo'], '/');

        return ResolvedSeoUrl::fromArray($seoPath);
    }

    private static function normalizeQueryString(?string $queryString): ?string
    {
        $normalizedQueryString = Request::normalizeQueryString($queryString);

        return $normalizedQueryString === '' ? null : $normalizedQueryString;
    }

    private static function storedQueryMatches(mixed $storedSeoPathInfo, string $normalizedQueryString): bool
    {
        if (!\is_string($storedSeoPathInfo)) {
            return false;
        }

        $storedQuery = parse_url($storedSeoPathInfo, \PHP_URL_QUERY);
        if (!\is_string($storedQuery)) {
            return false;
        }

        return self::normalizeQueryString($storedQuery) === $normalizedQueryString;
    }
}
