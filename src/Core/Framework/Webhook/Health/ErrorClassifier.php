<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Health;

use Shopware\Core\Framework\Log\Package;

/**
 * Pure mapping from an HTTP outcome to an {@see ErrorClassification}: two arguments in, one enum
 * out, no state. `Retry-After` is parsed separately and merged at the failure call site.
 *
 * @internal
 */
#[Package('framework')]
interface ErrorClassifier
{
    public function classify(int $statusCode, ?\Throwable $exception = null): ErrorClassification;
}
