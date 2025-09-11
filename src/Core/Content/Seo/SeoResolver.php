<?php declare(strict_types=1);

namespace Shopware\Core\Content\Seo;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\QueryBuilder;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * @phpstan-import-type ResolvedSeoUrl from AbstractSeoResolver
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
     * @return ResolvedSeoUrl
     */
    public function resolve(string $languageId, string $salesChannelId, string $pathInfo): array
    {
        return $this->resolveWithQueryString($languageId, $salesChannelId, $pathInfo, null);
    }

    /**
     * @return ResolvedSeoUrl
     */
    public function resolveWithQueryString(string $languageId, string $salesChannelId, string $pathInfo, ?string $queryString): array
    {
        $seoPathInfo = trim($pathInfo, '/');
        $normalizedQueryString = self::normalizeQueryString($queryString);

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

        $query->setParameter('language_id', Uuid::fromHexToBytes($languageId))
            ->setParameter('sales_channel_id', Uuid::fromHexToBytes($salesChannelId))
            ->setParameter('seoPath', $seoPathInfo)
            ->setParameter('seoPathWithSlash', $seoPathInfo . '/');

        if ($normalizedQueryString !== null) {
            $seoPathConditions[] = 'seo_path_info = :seoPathWithQuery';
            $seoPathConditions[] = 'seo_path_info = :seoPathWithSlashAndQuery';
            $seoPathConditions[] = 'seo_path_info LIKE :seoPathLikeQuery';
            $seoPathConditions[] = 'seo_path_info LIKE :seoPathWithSlashLikeQuery';
            $query->setParameter('seoPathWithQuery', $seoPathInfo . '?' . $normalizedQueryString)
                ->setParameter('seoPathWithSlashAndQuery', $seoPathInfo . '/?' . $normalizedQueryString)
                ->setParameter('seoPathLikeQuery', $seoPathInfo . '?%')
                ->setParameter('seoPathWithSlashLikeQuery', $seoPathInfo . '/?%');
        }

        $query->andWhere('(' . implode(' OR ', $seoPathConditions) . ')');

        $query->setTitle('seo-url::resolve');

        $seoPaths = $query->executeQuery()->fetchAllAssociative();
        $requestQueryParams = null;
        if ($normalizedQueryString !== null) {
            $requestQueryParams = self::parseQueryParameters($normalizedQueryString);

            $seoPaths = array_values(array_filter($seoPaths, static function (array $seoPath) use ($seoPathInfo, $normalizedQueryString, $requestQueryParams): bool {
                [$priority] = self::calculateQueryMatch($seoPathInfo, $normalizedQueryString, $requestQueryParams, (string) ($seoPath['seoPathInfo'] ?? ''));

                return $priority > 0;
            }));
        }

        // Prefer exact query-string matches first, then controlled query fallback matches,
        // then plain path matches. Afterwards sort by canonical and sales-channel specificity.
        usort($seoPaths, static function ($a, $b) use ($seoPathInfo, $normalizedQueryString, $requestQueryParams) {
            if ($normalizedQueryString !== null && $requestQueryParams !== null) {
                [$aPriority, $aSpecificity] = self::calculateQueryMatch($seoPathInfo, $normalizedQueryString, $requestQueryParams, (string) ($a['seoPathInfo'] ?? ''));
                [$bPriority, $bSpecificity] = self::calculateQueryMatch($seoPathInfo, $normalizedQueryString, $requestQueryParams, (string) ($b['seoPathInfo'] ?? ''));

                if ($aPriority !== $bPriority) {
                    return $bPriority <=> $aPriority;
                }

                if ($aSpecificity !== $bSpecificity) {
                    return $bSpecificity <=> $aSpecificity;
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

        if ($normalizedQueryString !== null && $seoPath['isCanonical'] && isset($seoPath['seoPathInfo']) && \is_string($seoPath['seoPathInfo'])) {
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
                ->setParameter('language_id', Uuid::fromHexToBytes($languageId))
                ->setParameter('sales_channel_id', Uuid::fromHexToBytes($salesChannelId))
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

        return $seoPath;
    }

    /**
     * @param array<string, mixed> $requestQueryParams
     *
     * @return array{int, int}
     */
    private static function calculateQueryMatch(string $seoPathInfo, string $queryString, array $requestQueryParams, string $storedSeoPathInfo): array
    {
        $storedQueryString = parse_url($storedSeoPathInfo, \PHP_URL_QUERY);
        if (!\is_string($storedQueryString) || $storedQueryString === '') {
            return [1, 0];
        }

        $normalizedStoredQueryString = self::normalizeQueryString($storedQueryString);
        $storedPathInfo = trim((string) parse_url($storedSeoPathInfo, \PHP_URL_PATH), '/');

        if ($normalizedStoredQueryString === $queryString && rtrim($storedPathInfo, '/') === rtrim($seoPathInfo, '/')) {
            return [3, \strlen($normalizedStoredQueryString)];
        }

        $storedQueryParams = self::parseQueryParameters($storedQueryString);

        $specificity = 0;
        foreach ($storedQueryParams as $key => $storedValue) {
            if (!\array_key_exists($key, $requestQueryParams)) {
                return [0, 0];
            }

            $requestValue = $requestQueryParams[$key];
            if (!\is_string($storedValue) || !\is_string($requestValue)) {
                return [0, 0];
            }

            if ($storedValue !== $requestValue) {
                return [0, 0];
            }

            $specificity += \strlen($storedValue);
        }

        return [2, $specificity];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseQueryParameters(string $queryString): array
    {
        parse_str($queryString, $rawQueryParams);

        $queryParams = [];
        foreach ($rawQueryParams as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            $queryParams[$key] = $value;
        }

        return $queryParams;
    }

    private static function normalizeQueryString(?string $queryString): ?string
    {
        $normalizedQueryString = Request::normalizeQueryString($queryString);

        return $normalizedQueryString === '' ? null : $normalizedQueryString;
    }
}
