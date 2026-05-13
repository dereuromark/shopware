<?php declare(strict_types=1);

namespace Shopware\Core\Content\Seo;

use Shopware\Core\Framework\Log\Package;

#[Package('inventory')]
final readonly class ResolvedSeoUrl
{
    public function __construct(
        public string $pathInfo,
        public bool $isCanonical,
        public ?string $id = null,
        public ?string $canonicalPathInfo = null,
        public ?string $seoPathInfo = null,
    ) {
    }

    /**
     * @param array{id?: string, pathInfo: string, isCanonical: bool|string, canonicalPathInfo?: string, seoPathInfo?: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pathInfo: $data['pathInfo'],
            isCanonical: (bool) $data['isCanonical'],
            id: $data['id'] ?? null,
            canonicalPathInfo: $data['canonicalPathInfo'] ?? null,
            seoPathInfo: $data['seoPathInfo'] ?? null,
        );
    }

    /**
     * @return array{id?: string, pathInfo: string, isCanonical: bool, canonicalPathInfo?: string, seoPathInfo?: string}
     */
    public function toArray(): array
    {
        $data = [
            'pathInfo' => $this->pathInfo,
            'isCanonical' => $this->isCanonical,
        ];

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }
        if ($this->canonicalPathInfo !== null) {
            $data['canonicalPathInfo'] = $this->canonicalPathInfo;
        }
        if ($this->seoPathInfo !== null) {
            $data['seoPathInfo'] = $this->seoPathInfo;
        }

        return $data;
    }
}
