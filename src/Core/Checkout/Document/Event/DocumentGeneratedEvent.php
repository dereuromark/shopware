<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Document\Event;

use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\OrderAware;
use Shopware\Core\Framework\Log\Package;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * The payload deliberately excludes the document deepLinkCode: scalar-only business
 * events derive no app privilege requirement, so the download secret would reach apps
 * that never obtained document:read. Consumers needing the file fetch it through the
 * ACL-gated document API.
 */
#[Package('after-sales')]
class DocumentGeneratedEvent extends Event implements OrderAware, ScalarValuesAware, FlowEventAware
{
    final public const EVENT_NAME = 'after_sales.document.generated';

    public function __construct(
        private readonly Context $context,
        private readonly string $documentId,
        private readonly string $orderId,
        private readonly string $documentTypeId,
        private readonly ?string $documentNumber = null
    ) {
    }

    public function getName(): string
    {
        return self::EVENT_NAME;
    }

    public static function getAvailableData(): EventDataCollection
    {
        return (new EventDataCollection())
            ->add('documentId', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add(OrderAware::ORDER_ID, new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('documentTypeId', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('documentNumber', new ScalarValueType(ScalarValueType::TYPE_STRING));
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getDocumentId(): string
    {
        return $this->documentId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getDocumentTypeId(): string
    {
        return $this->documentTypeId;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    /**
     * @return array<string, scalar|array<mixed>|null>
     */
    public function getValues(): array
    {
        return [
            'documentId' => $this->documentId,
            OrderAware::ORDER_ID => $this->orderId,
            'documentTypeId' => $this->documentTypeId,
            'documentNumber' => $this->documentNumber,
        ];
    }
}
