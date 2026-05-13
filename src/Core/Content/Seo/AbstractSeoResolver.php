<?php declare(strict_types=1);

namespace Shopware\Core\Content\Seo;

use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;

/**
 * @phpstan-type ResolvedSeoUrlArray = array{id?: string, pathInfo: string, isCanonical: bool|string, canonicalPathInfo?: string, seoPathInfo?: string}
 */
#[Package('inventory')]
abstract class AbstractSeoResolver
{
    abstract public function getDecorated(): AbstractSeoResolver;

    /**
     * @deprecated tag:v6.8.0 - reason:becomes-abstract - will be removed in v6.8.0, use {@see resolveUrl()} instead
     *
     * @return ResolvedSeoUrlArray
     */
    abstract public function resolve(string $languageId, string $salesChannelId, string $pathInfo): array;

    /**
     * @deprecated tag:v6.8.0 - will be removed in v6.8.0, use {@see resolveUrl()} instead
     *
     * @return ResolvedSeoUrlArray
     */
    public function resolveWithQueryString(string $languageId, string $salesChannelId, string $pathInfo, ?string $queryString): array
    {
        Feature::triggerDeprecationOrThrow(
            'v6.8.0.0',
            Feature::deprecatedMethodMessage(self::class, __METHOD__, 'v6.8.0.0', self::class . '::resolveUrl()')
        );

        return $this->resolveUrl(new SeoUrlRequestContext(
            languageId: $languageId,
            salesChannelId: $salesChannelId,
            pathInfo: $pathInfo,
            queryString: $queryString,
        ))->toArray();
    }

    /**
     * Default implementation delegates to {@see resolve()} for backward compatibility with existing
     * decorators that only override resolve(). Subclasses should override this method directly to
     * benefit from query-string-aware resolution.
     *
     * In v6.8.0 this method becomes abstract and {@see resolve()} will be removed.
     */
    public function resolveUrl(SeoUrlRequestContext $context): ResolvedSeoUrl
    {
        return ResolvedSeoUrl::fromArray(
            $this->resolve($context->languageId, $context->salesChannelId, $context->pathInfo)
        );
    }
}
