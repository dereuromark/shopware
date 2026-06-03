<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Document\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\DocumentDefinition;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Checkout\Document\Event\DocumentDeletedEvent;
use Shopware\Core\Checkout\Document\Event\DocumentGeneratedEvent;
use Shopware\Core\Checkout\Document\Subscriber\DocumentBusinessEventSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * @internal
 */
#[Package('after-sales')]
#[CoversClass(DocumentBusinessEventSubscriber::class)]
class DocumentBusinessEventSubscriberTest extends TestCase
{
    public function testDocumentInsertDispatchesGeneratedEventWithNumberFromConfig(): void
    {
        $documentId = Uuid::randomHex();
        $orderId = Uuid::randomHex();
        $documentTypeId = Uuid::randomHex();
        $context = Context::createDefaultContext();

        $dispatcher = new EventDispatcher();
        $subscriber = new DocumentBusinessEventSubscriber($this->createDocumentRepository(), $dispatcher);

        /** @var list<DocumentGeneratedEvent> $caught */
        $caught = [];
        $dispatcher->addListener(DocumentGeneratedEvent::class, static function (DocumentGeneratedEvent $event) use (&$caught): void {
            $caught[] = $event;
        });

        // the real producer payload shape: DocumentGenerator carries the number inside
        // config — document_number is a DB-generated column, never a top-level write key
        $subscriber->onEntityWritten($this->createWrittenContainerEvent($context, new EntityWriteResult(
            $documentId,
            [
                'id' => $documentId,
                'orderId' => $orderId,
                'documentTypeId' => $documentTypeId,
                'deepLinkCode' => 'deep-link-code',
                'config' => ['documentNumber' => '1000', 'documentDate' => '2024-01-01'],
            ],
            DocumentDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_INSERT
        )));

        static::assertCount(1, $caught);
        static::assertSame($documentId, $caught[0]->getDocumentId());
        static::assertSame($orderId, $caught[0]->getOrderId());
        static::assertSame($documentTypeId, $caught[0]->getDocumentTypeId());
        static::assertSame('1000', $caught[0]->getDocumentNumber());
    }

    public function testBatchGenerationDispatchesOneEventPerInsertedDocument(): void
    {
        $firstId = Uuid::randomHex();
        $secondId = Uuid::randomHex();
        $context = Context::createDefaultContext();

        $dispatcher = new EventDispatcher();
        $subscriber = new DocumentBusinessEventSubscriber($this->createDocumentRepository(), $dispatcher);

        /** @var list<DocumentGeneratedEvent> $caught */
        $caught = [];
        $dispatcher->addListener(DocumentGeneratedEvent::class, static function (DocumentGeneratedEvent $event) use (&$caught): void {
            $caught[] = $event;
        });

        $subscriber->onEntityWritten(new EntityWrittenContainerEvent(
            $context,
            new NestedEventCollection([
                new EntityWrittenEvent(DocumentDefinition::ENTITY_NAME, [
                    $this->createInsertResult($firstId),
                    $this->createInsertResult($secondId),
                    new EntityWriteResult(
                        Uuid::randomHex(),
                        ['documentMediaFileId' => Uuid::randomHex()],
                        DocumentDefinition::ENTITY_NAME,
                        EntityWriteResult::OPERATION_UPDATE
                    ),
                ], $context),
            ]),
            []
        ));

        static::assertCount(2, $caught);
        static::assertSame($firstId, $caught[0]->getDocumentId());
        static::assertSame($secondId, $caught[1]->getDocumentId());
    }

    public function testDocumentUpdateDoesNotDispatchGeneratedEvent(): void
    {
        $context = Context::createDefaultContext();

        $dispatcher = new EventDispatcher();
        $subscriber = new DocumentBusinessEventSubscriber($this->createDocumentRepository(), $dispatcher);

        $caught = 0;
        $dispatcher->addListener(DocumentGeneratedEvent::class, static function () use (&$caught): void {
            ++$caught;
        });

        $subscriber->onEntityWritten($this->createWrittenContainerEvent($context, new EntityWriteResult(
            Uuid::randomHex(),
            ['documentMediaFileId' => Uuid::randomHex()],
            DocumentDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_UPDATE
        )));

        static::assertSame(0, $caught);
    }

    public function testNonLiveVersionContextWritesAreIgnored(): void
    {
        $versionContext = Context::createDefaultContext()->createWithVersionId(Uuid::randomHex());

        $dispatcher = new EventDispatcher();
        $subscriber = new DocumentBusinessEventSubscriber($this->createDocumentRepository(), $dispatcher);

        $caught = 0;
        $dispatcher->addListener(DocumentGeneratedEvent::class, static function () use (&$caught): void {
            ++$caught;
        });

        $subscriber->onEntityWritten($this->createWrittenContainerEvent($versionContext, new EntityWriteResult(
            Uuid::randomHex(),
            [
                'orderId' => Uuid::randomHex(),
                'documentTypeId' => Uuid::randomHex(),
                'deepLinkCode' => 'deep-link-code',
            ],
            DocumentDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_INSERT
        )));

        static::assertSame(0, $caught);
    }

    public function testDocumentDeleteDispatchesDeletedEventOnlyAfterDeleteSucceeds(): void
    {
        $documentId = Uuid::randomHex();
        $orderId = Uuid::randomHex();
        $context = Context::createDefaultContext();

        $document = (new DocumentEntity())->assign([
            'id' => $documentId,
            'orderId' => $orderId,
            'documentNumber' => '1000',
        ]);

        $documentDefinition = $this->createDocumentDefinition();

        /** @var StaticEntityRepository<DocumentCollection> $documentRepository */
        $documentRepository = new StaticEntityRepository([
            new EntitySearchResult(
                DocumentEntity::class,
                1,
                new DocumentCollection([$document]),
                null,
                new Criteria([$documentId]),
                $context,
            ),
        ], $documentDefinition);

        $dispatcher = new EventDispatcher();
        $subscriber = new DocumentBusinessEventSubscriber($documentRepository, $dispatcher);

        /** @var list<DocumentDeletedEvent> $caught */
        $caught = [];
        $dispatcher->addListener(DocumentDeletedEvent::class, static function (DocumentDeletedEvent $event) use (&$caught): void {
            $caught[] = $event;
        });

        $deleteEvent = $this->createEntityDeleteEvent($documentDefinition, $documentId);
        $subscriber->beforeDelete($deleteEvent);

        static::assertCount(0, $caught, 'deleted event must not fire before the delete succeeded');

        $deleteEvent->success();

        static::assertCount(1, $caught);
        static::assertSame($documentId, $caught[0]->getDocumentId());
        static::assertSame($orderId, $caught[0]->getOrderId());
        static::assertSame('1000', $caught[0]->getDocumentNumber());
        static::assertNotSame('', $caught[0]->getDeletedAt());
    }

    public function testNonLiveVersionContextDeletesAreIgnored(): void
    {
        $versionContext = Context::createDefaultContext()->createWithVersionId(Uuid::randomHex());
        $documentDefinition = $this->createDocumentDefinition();

        $dispatcher = new EventDispatcher();
        // empty repository: a search would fail loudly, proving no lookup happens
        $subscriber = new DocumentBusinessEventSubscriber(
            new StaticEntityRepository([], $documentDefinition),
            $dispatcher
        );

        $caught = 0;
        $dispatcher->addListener(DocumentDeletedEvent::class, static function () use (&$caught): void {
            ++$caught;
        });

        $deleteEvent = EntityDeleteEvent::create(
            WriteContext::createFromContext($versionContext),
            [$this->createDeleteCommand($documentDefinition, Uuid::randomHex())]
        );

        $subscriber->beforeDelete($deleteEvent);
        $deleteEvent->success();

        static::assertSame(0, $caught);
    }

    private function createInsertResult(string $documentId): EntityWriteResult
    {
        return new EntityWriteResult(
            $documentId,
            [
                'id' => $documentId,
                'orderId' => Uuid::randomHex(),
                'documentTypeId' => Uuid::randomHex(),
                'deepLinkCode' => 'deep-link-code',
                'config' => ['documentNumber' => '1000'],
            ],
            DocumentDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_INSERT
        );
    }

    /**
     * @return StaticEntityRepository<DocumentCollection>
     */
    private function createDocumentRepository(): StaticEntityRepository
    {
        /** @var StaticEntityRepository<DocumentCollection> $repository */
        $repository = new StaticEntityRepository([], $this->createDocumentDefinition());

        return $repository;
    }

    private function createDocumentDefinition(): DocumentDefinition
    {
        $definition = new DocumentDefinition();
        $definition->compile($this->createMock(DefinitionInstanceRegistry::class));

        return $definition;
    }

    private function createWrittenContainerEvent(Context $context, EntityWriteResult $writeResult): EntityWrittenContainerEvent
    {
        return new EntityWrittenContainerEvent(
            $context,
            new NestedEventCollection([
                new EntityWrittenEvent(DocumentDefinition::ENTITY_NAME, [$writeResult], $context),
            ]),
            []
        );
    }

    private function createEntityDeleteEvent(DocumentDefinition $definition, string $documentId): EntityDeleteEvent
    {
        return EntityDeleteEvent::create(
            WriteContext::createFromContext(Context::createDefaultContext()),
            [$this->createDeleteCommand($definition, $documentId)]
        );
    }

    private function createDeleteCommand(DocumentDefinition $definition, string $documentId): DeleteCommand
    {
        $primaryKey = ['id' => Uuid::fromHexToBytes($documentId)];

        return new DeleteCommand(
            $definition,
            $primaryKey,
            new EntityExistence(
                DocumentDefinition::ENTITY_NAME,
                ['id' => $documentId],
                true,
                false,
                false,
                ['exists' => true, 'id' => $documentId]
            )
        );
    }
}
