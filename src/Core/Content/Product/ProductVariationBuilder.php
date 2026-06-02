<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product;

use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

#[Package('inventory')]
class ProductVariationBuilder extends AbstractProductVariationBuilder
{
    public function getDecorated(): AbstractProductVariationBuilder
    {
        throw new DecorationPatternException(self::class);
    }

    public function build(Entity $product): void
    {
        if (!$product instanceof ProductEntity || !$product->has('options')) {
            return;
        }

        $options = $product->getOptions();
        if ($options === null) {
            $product->setVariation([]);

            return;
        }

        $options = $options->getElements();

        uasort($options, static function (PropertyGroupOptionEntity $a, PropertyGroupOptionEntity $b) {
            $aGroup = $a->getGroup();
            $bGroup = $b->getGroup();
            if (!$aGroup instanceof PropertyGroupEntity || !$bGroup instanceof PropertyGroupEntity) {
                return $a->getGroupId() <=> $b->getGroupId();
            }

            if ($aGroup->getPosition() === $bGroup->getPosition()) {
                return $aGroup->getTranslation('name') <=> $bGroup->getTranslation('name');
            }

            return $aGroup->getPosition() <=> $bGroup->getPosition();
        });

        // fallback - simply take all option names unordered
        $names = array_map(static function (PropertyGroupOptionEntity $option) {
            if (!$option->getGroup() instanceof PropertyGroupEntity) {
                return [];
            }

            return [
                'group' => $option->getGroup()->getTranslation('name'),
                'option' => $option->getTranslation('name'),
            ];
        }, $options);

        $product->setVariation(\array_values(\array_filter($names)));
    }
}
