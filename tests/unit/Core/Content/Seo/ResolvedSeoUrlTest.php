<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Seo\ResolvedSeoUrl;
use Shopware\Core\Test\Annotation\DisabledFeatures;

/**
 * @internal
 */
#[CoversClass(ResolvedSeoUrl::class)]
class ResolvedSeoUrlTest extends TestCase
{
    public function testFromArrayPopulatesAllFields(): void
    {
        $resolved = ResolvedSeoUrl::fromArray([
            'id' => 'binaryId',
            'pathInfo' => '/detail/1234',
            'isCanonical' => true,
            'canonicalPathInfo' => '/awesome-product',
            'seoPathInfo' => 'awesome-product',
        ]);

        static::assertSame('binaryId', $resolved->id);
        static::assertSame('/detail/1234', $resolved->pathInfo);
        static::assertTrue($resolved->isCanonical);
        static::assertSame('/awesome-product', $resolved->canonicalPathInfo);
        static::assertSame('awesome-product', $resolved->seoPathInfo);
    }

    public function testFromArrayMinimalFields(): void
    {
        $resolved = ResolvedSeoUrl::fromArray([
            'pathInfo' => '/',
            'isCanonical' => false,
        ]);

        static::assertSame('/', $resolved->pathInfo);
        static::assertFalse($resolved->isCanonical);
        static::assertNull($resolved->id);
        static::assertNull($resolved->canonicalPathInfo);
        static::assertNull($resolved->seoPathInfo);
    }

    #[DisabledFeatures(['v6.8.0.0'])]
    public function testToArrayOmitsNullableNulls(): void
    {
        $resolved = new ResolvedSeoUrl(pathInfo: '/', isCanonical: false);

        static::assertSame(['pathInfo' => '/', 'isCanonical' => false], $resolved->toArray());
    }

    #[DisabledFeatures(['v6.8.0.0'])]
    public function testToArrayIncludesAllNonNullFields(): void
    {
        $resolved = new ResolvedSeoUrl(
            pathInfo: '/detail/1234',
            isCanonical: true,
            id: 'binaryId',
            canonicalPathInfo: '/awesome-product',
            seoPathInfo: 'awesome-product',
        );

        static::assertSame([
            'pathInfo' => '/detail/1234',
            'isCanonical' => true,
            'id' => 'binaryId',
            'canonicalPathInfo' => '/awesome-product',
            'seoPathInfo' => 'awesome-product',
        ], $resolved->toArray());
    }

    #[DisabledFeatures(['v6.8.0.0'])]
    public function testFromArrayToArrayRoundTrip(): void
    {
        $data = [
            'id' => 'binaryId',
            'pathInfo' => '/detail/1234',
            'isCanonical' => true,
            'canonicalPathInfo' => '/awesome-product',
            'seoPathInfo' => 'awesome-product',
        ];

        $resolved = ResolvedSeoUrl::fromArray($data);
        static::assertEquals($data, $resolved->toArray());
    }

    public function testIsCanonicalCoercesStringToBool(): void
    {
        $resolved = ResolvedSeoUrl::fromArray([
            'pathInfo' => '/detail/1234',
            'isCanonical' => '1',
        ]);
        static::assertTrue($resolved->isCanonical);

        $resolved = ResolvedSeoUrl::fromArray([
            'pathInfo' => '/detail/1234',
            'isCanonical' => '0',
        ]);
        static::assertFalse($resolved->isCanonical);
    }
}
