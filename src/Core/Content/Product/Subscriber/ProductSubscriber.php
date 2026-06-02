<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Subscriber;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\MeasurementSystem\MeasurementUnits;
use Shopware\Core\Content\MeasurementSystem\MeasurementUnitTypeEnum;
use Shopware\Core\Content\MeasurementSystem\ProductMeasurement\ProductMeasurementEnum;
use Shopware\Core\Content\MeasurementSystem\ProductMeasurement\ProductMeasurementUnitBuilder;
use Shopware\Core\Content\MeasurementSystem\Unit\AbstractMeasurementUnitConverter;
use Shopware\Core\Content\Product\AbstractIsNewDetector;
use Shopware\Core\Content\Product\AbstractProductMaxPurchaseCalculator;
use Shopware\Core\Content\Product\AbstractProductVariationBuilder;
use Shopware\Core\Content\Product\AbstractPropertyGroupSorter;
use Shopware\Core\Content\Product\DataAbstractionLayer\CheapestPrice\CheapestPriceContainer;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Content\Product\SalesChannel\Price\AbstractProductPriceCalculator;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelEntityLoadedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
#[Package('inventory')]
class ProductSubscriber implements EventSubscriberInterface
{
    /**
     * @internal
     */
    public function __construct(
        private readonly AbstractProductVariationBuilder $productVariationBuilder,
        private readonly AbstractProductPriceCalculator $calculator,
        private readonly AbstractPropertyGroupSorter $propertyGroupSorter,
        private readonly AbstractProductMaxPurchaseCalculator $maxPurchaseCalculator,
        private readonly AbstractIsNewDetector $isNewDetector,
        private readonly SystemConfigService $systemConfigService,
        private readonly ProductMeasurementUnitBuilder $measurementUnitBuilder,
        private readonly AbstractMeasurementUnitConverter $measurementUnitConverter,
        private readonly RequestStack $requestStack,
        private readonly Connection $connection
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_LOADED_EVENT => 'loaded',
            'product.partial_loaded' => 'loaded',
            'sales_channel.' . ProductEvents::PRODUCT_LOADED_EVENT => 'salesChannelLoaded',
            'sales_channel.product.partial_loaded' => 'salesChannelLoaded',
            EntityWriteEvent::class => 'beforeWriteProduct',
            EntityDeleteEvent::class => 'beforeDeleteProduct',
        ];
    }

    /**
     * @param EntityLoadedEvent<ProductEntity> $event
     */
    public function loaded(EntityLoadedEvent $event): void
    {
        $isAdminSource = $event->getContext()->getSource() instanceof AdminApiSource;

        foreach ($event->getEntities() as $product) {
            if (!$product instanceof ProductEntity) {
                continue;
            }

            if ($isAdminSource) {
                $this->convertMeasurementUnit($product);
            }

            $this->setDefaultLayout($product);

            $this->productVariationBuilder->build($product);
        }
    }

    /**
     * @param SalesChannelEntityLoadedEvent<SalesChannelProductEntity> $event
     */
    public function salesChannelLoaded(SalesChannelEntityLoadedEvent $event): void
    {
        foreach ($event->getEntities() as $product) {
            if (!$product instanceof SalesChannelProductEntity) {
                continue;
            }

            if ($product->has('cheapestPrice')) {
                $price = $product->getCheapestPrice();

                if ($price instanceof CheapestPriceContainer) {
                    $product->setCheapestPrice($price->resolve($event->getContext()));
                    $product->setCheapestPriceContainer($price);
                }
            }

            if ($product->has('properties') && ($properties = $product->getProperties()) !== null) {
                $product->setSortedProperties($this->propertyGroupSorter->sort($properties));
            }

            $product->setCalculatedMaxPurchase($this->maxPurchaseCalculator->calculate($product, $event->getSalesChannelContext()));
            $product->setIsNew($this->isNewDetector->isNew($product, $event->getSalesChannelContext()));
            $product->setMeasurements($this->measurementUnitBuilder->buildFromContext($product, $event->getSalesChannelContext()));

            $this->setDefaultLayout($product, $event->getSalesChannelContext()->getSalesChannelId());

            $this->productVariationBuilder->build($product);
        }

        $this->calculator->calculate($event->getEntities(), $event->getSalesChannelContext());
    }

    public function beforeWriteProduct(EntityWriteEvent $event): void
    {
        $lengthUnitHeader = $this->requestStack->getCurrentRequest()?->headers->get(PlatformRequest::HEADER_MEASUREMENT_LENGTH_UNIT);
        $weightUnitHeader = $this->requestStack->getCurrentRequest()?->headers->get(PlatformRequest::HEADER_MEASUREMENT_WEIGHT_UNIT);

        if (!$lengthUnitHeader && !$weightUnitHeader) {
            return;
        }

        $commands = $event->getCommandsForEntity(ProductDefinition::ENTITY_NAME);

        foreach ($commands as $command) {
            $payload = $command->getPayload();

            foreach (ProductMeasurementEnum::DIMENSIONS_MAPPING as $dimension => $type) {
                if (!$command->hasField($dimension) || !\is_float($payload[$dimension] ?? null)) {
                    continue;
                }

                $fromUnit = $type === MeasurementUnitTypeEnum::WEIGHT
                    ? $weightUnitHeader
                    : $lengthUnitHeader;

                $toUnit = $type === MeasurementUnitTypeEnum::WEIGHT
                    ? MeasurementUnits::DEFAULT_WEIGHT_UNIT
                    : MeasurementUnits::DEFAULT_LENGTH_UNIT;

                if ($fromUnit) {
                    $command->addPayload($dimension, $this->measurementUnitConverter->convert(
                        $payload[$dimension],
                        $fromUnit,
                        $toUnit,
                    )->value);
                }
            }
        }
    }

    public function beforeDeleteProduct(EntityDeleteEvent $event): void
    {
        $deletedProductIds = $event->getIds(ProductDefinition::ENTITY_NAME);

        if ($deletedProductIds === []) {
            return;
        }

        $deletedIds = [];
        foreach ($deletedProductIds as $id) {
            $deletedIds[] = \is_string($id) ? $id : $id['id'];
        }

        $parentIds = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT parent_id
             FROM product
             WHERE id IN (:ids) AND parent_id IS NOT NULL',
            ['ids' => Uuid::fromHexToBytesList($deletedIds)],
            ['ids' => ArrayParameterType::BINARY]
        );

        if ($parentIds === []) {
            return;
        }

        $versionBytes = Uuid::fromHexToBytes($event->getContext()->getVersionId());

        $event->addSuccess(function () use ($parentIds, $versionBytes): void {
            $this->cleanupConfiguratorSettings($parentIds, $versionBytes);
        });
    }

    /**
     * @param array<string> $parentIds
     */
    private function cleanupConfiguratorSettings(array $parentIds, string $versionBytes): void
    {
        if ($parentIds === []) {
            return;
        }

        // Clean up configurator settings for parents that no longer have variants using those options
        $this->connection->executeStatement(
            'DELETE FROM product_configurator_setting
             WHERE product_configurator_setting.product_id IN (:parentIds)
             AND product_configurator_setting.product_version_id = :versionId
             AND NOT EXISTS (
                 SELECT 1
                 FROM product_option po
                 INNER JOIN product p ON p.id = po.product_id AND p.version_id = po.product_version_id
                 WHERE p.parent_id = product_configurator_setting.product_id
                     AND p.version_id = :versionId
                     AND po.property_group_option_id = product_configurator_setting.property_group_option_id
                     AND po.product_version_id = :versionId
             )',
            [
                'parentIds' => $parentIds,
                'versionId' => $versionBytes,
            ],
            [
                'parentIds' => ArrayParameterType::BINARY,
            ]
        );
    }

    private function setDefaultLayout(ProductEntity $product, ?string $salesChannelId = null): void
    {
        if (!$product->has('cmsPageId')) {
            return;
        }

        if ($product->getCmsPageId() !== null) {
            return;
        }

        $cmsPageId = $this->systemConfigService->get(ProductDefinition::CONFIG_KEY_DEFAULT_CMS_PAGE_PRODUCT, $salesChannelId);

        if (!\is_string($cmsPageId) || $cmsPageId === '') {
            return;
        }

        $product->setCmsPageId($cmsPageId);
    }

    private function convertMeasurementUnit(ProductEntity $product): void
    {
        $lengthUnitHeader = $this->requestStack->getCurrentRequest()?->headers->get(PlatformRequest::HEADER_MEASUREMENT_LENGTH_UNIT);
        $weightUnitHeader = $this->requestStack->getCurrentRequest()?->headers->get(PlatformRequest::HEADER_MEASUREMENT_WEIGHT_UNIT);

        if (!$lengthUnitHeader && !$weightUnitHeader) {
            return;
        }

        $toLengthUnit = $lengthUnitHeader ?? MeasurementUnits::DEFAULT_LENGTH_UNIT;
        $toWeightUnit = $weightUnitHeader ?? MeasurementUnits::DEFAULT_WEIGHT_UNIT;

        $converted = $this->measurementUnitBuilder->build($product, $toLengthUnit, $toWeightUnit);

        $assigns = [];

        foreach ($converted->getUnits() as $unit => $convertedUnit) {
            $assigns[$unit] = $convertedUnit->value;
        }

        if ($assigns !== []) {
            $product->assign($assigns);
        }
    }
}
