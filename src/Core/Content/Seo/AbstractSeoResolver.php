<?php declare(strict_types=1);

namespace Shopware\Core\Content\Seo;

use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;

/**
 * @phpstan-type ResolvedSeoUrl = array{id?: string, pathInfo: string, isCanonical: bool|string, canonicalPathInfo?: string, seoPathInfo?: string}
 */
#[Package('inventory')]
abstract class AbstractSeoResolver
{
    abstract public function getDecorated(): AbstractSeoResolver;

    /**
     * @return ResolvedSeoUrl
     */
    abstract public function resolve(string $languageId, string $salesChannelId, string $pathInfo): array;

    /**
     * @deprecated tag:v6.8.0 - reason:becomes-abstract - will become abstract in v6.8.0
     *
     * @return ResolvedSeoUrl
     */
    public function resolveWithQueryString(string $languageId, string $salesChannelId, string $pathInfo, ?string $queryString): array
    {
        Feature::triggerDeprecationOrThrow(
            'v6.8.0.0',
            Feature::deprecatedMethodMessage(self::class, __METHOD__, 'v6.8.0.0')
        );

        return $this->resolve($languageId, $salesChannelId, $pathInfo);
    }
}
