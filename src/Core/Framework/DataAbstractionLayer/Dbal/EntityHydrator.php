<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Dbal;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\AssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CustomFields;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Extension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Inherited;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ParentAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslatedField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\VersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommandQueue;
use Shopware\Core\Framework\DataAbstractionLayer\Write\DataStack\KeyValuePair;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteParameterBag;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Allows to hydrate database values into struct objects.
 */
#[Package('framework')]
class EntityHydrator
{
    /**
     * @var array<mixed>
     */
    protected static array $partial = [];

    /**
     * @var array<bool>
     */
    protected static array $partialFullPaths = [];

    /**
     * @var array<mixed>
     */
    private static array $hydrated = [];

    /**
     * @var array<string>
     */
    private static array $manyToOne = [];

    /**
     * @var array<string, array<string, Field>>
     */
    private static array $translatedFields = [];

    /**
     * @internal
     */
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @template TEntityCollection of EntityCollection
     *
     * @param TEntityCollection $collection
     * @param array<mixed> $rows
     * @param array<string|array<string>> $partial
     *
     * @return TEntityCollection
     */
    public function hydrate(EntityCollection $collection, string $entityClass, EntityDefinition $definition, array $rows, string $root, Context $context, array $partial = []): EntityCollection
    {
        self::$hydrated = [];

        self::$partial = $partial;

        self::$partialFullPaths = [];

        if (self::$partial !== []) {
            $this->mapPartialFieldsToHydrate(self::$partial, $root);
        }

        foreach ($rows as $row) {
            $collection->add($this->hydrateEntity($definition, $entityClass, $row, $root, $context));
        }

        return $collection;
    }

    /**
     * @template EntityClass
     *
     * @param class-string<EntityClass> $class
     *
     * @return EntityClass
     */
    final public static function createClass(string $class)
    {
        return new $class();
    }

    /**
     * @param array<mixed> $row
     *
     * @return array<mixed>
     */
    final public static function buildUniqueIdentifier(EntityDefinition $definition, array $row, string $root): array
    {
        $primaryKeyFields = $definition->getPrimaryKeys();
        $primaryKey = [];

        foreach ($primaryKeyFields as $field) {
            if ($field instanceof VersionField || $field instanceof ReferenceVersionField) {
                continue;
            }
            $accessor = $root . '.' . $field->getPropertyName();

            $primaryKey[$field->getPropertyName()] = $field->getSerializer()->decode($field, $row[$accessor]);
        }

        return $primaryKey;
    }

    /**
     * @param array<string> $primaryKey
     *
     * @return array<string>
     */
    final public static function encodePrimaryKey(EntityDefinition $definition, array $primaryKey, Context $context): array
    {
        $fields = $definition->getPrimaryKeys();

        $mapped = [];

        $existence = new EntityExistence($definition->getEntityName(), [], true, false, false, []);

        $params = new WriteParameterBag($definition, WriteContext::createFromContext($context), '', new WriteCommandQueue());

        foreach ($fields as $field) {
            if ($field instanceof VersionField || $field instanceof ReferenceVersionField) {
                $value = $context->getVersionId();
            } else {
                $value = $primaryKey[$field->getPropertyName()];
            }

            $kvPair = new KeyValuePair($field->getPropertyName(), $value, true);

            $encoded = $field->getSerializer()->encode($field, $existence, $kvPair, $params);

            foreach ($encoded as $key => $encodedValue) {
                $mapped[$key] = $encodedValue;
            }
        }

        return $mapped;
    }

    /**
     * Allows simple overwrite for specialized entity hydrators
     *
     * @param array<mixed> $row
     */
    protected function assign(EntityDefinition $definition, Entity $entity, string $root, array $row, Context $context): Entity
    {
        $entity = $this->hydrateFields($definition, $entity, $root, $row, $context, $definition->getFields());

        return $entity;
    }

    /**
     * @param array<mixed> $row
     * @param iterable<Field> $fields
     */
    protected function hydrateFields(EntityDefinition $definition, Entity $entity, string $root, array $row, Context $context, iterable $fields): Entity
    {
        $isPartial = self::$partial !== [];

        foreach ($fields as $field) {
            $property = $field->getPropertyName();

            $key = $root . '.' . $property;
            if ($isPartial && !isset(self::$partialFullPaths[$key])) {
                continue;
            }

            // initialize not loaded associations with null
            if ($field instanceof AssociationField && $entity instanceof ArrayEntity) {
                $entity->set($property, null);
            }

            if ($field instanceof ParentAssociationField) {
                continue;
            }

            if ($field instanceof ManyToManyAssociationField) {
                $this->manyToMany($row, $root, $entity, $field);

                continue;
            }

            if ($field instanceof ManyToOneAssociationField || $field instanceof OneToOneAssociationField) {
                $association = $this->manyToOne($row, $root, $field, $context);

                if ($field->is(Extension::class)) {
                    if ($association) {
                        $this->addExtension($entity, $property, $association);
                    }
                } else {
                    $this->assignValue($entity, $property, $association);
                }

                continue;
            }

            // other association fields are not handled in entity reader query
            if ($field instanceof AssociationField) {
                continue;
            }

            if (!\array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];

            $typed = $field;
            if ($field instanceof TranslatedField) {
                $typed = EntityDefinitionQueryHelper::getTranslatedField($definition, $field);
            }

            if ($typed instanceof CustomFields) {
                $this->customFields($definition, $row, $root, $entity, $field, $context);

                continue;
            }

            if ($field instanceof TranslatedField) {
                // contains the resolved translation chain value
                $decoded = $typed->getSerializer()->decode($typed, $value);
                $this->addTranslated($entity, $property, $decoded);

                $inherited = $definition->isInheritanceAware() && $context->considerInheritance();
                $chain = EntityDefinitionQueryHelper::buildTranslationChain($root, $context, $inherited);

                // assign translated value of the first language
                $key = array_shift($chain) . '.' . $property;

                $decoded = $typed->getSerializer()->decode($typed, $row[$key]);
                $this->assignValue($entity, $property, $decoded);

                continue;
            }

            $decoded = $definition->decode($property, $value);

            if ($field->is(Extension::class)) {
                $foreignKeys = $entity->getExtension(EntityReader::FOREIGN_KEYS);
                \assert($foreignKeys instanceof ArrayStruct);
                $foreignKeys->set($property, $decoded);
            } else {
                $this->assignValue($entity, $property, $decoded);
            }
        }

        return $entity;
    }

    /**
     * @param array<mixed> $row
     */
    protected function manyToMany(array $row, string $root, Entity $entity, ?Field $field): void
    {
        if ($field === null) {
            throw DataAbstractionLayerException::entityHydratorError('No association field for "manyToMany" provided');
        }

        $accessor = $root . '.' . $field->getPropertyName() . '.id_mapping';

        // many to many isn't loaded in case of limited association criterias
        if (!\array_key_exists($accessor, $row)) {
            return;
        }

        // explode hexed ids
        $ids = explode('||', (string) $row[$accessor]);

        $ids = array_map('strtolower', array_filter($ids));

        $mapping = $entity->getExtension(EntityReader::INTERNAL_MAPPING_STORAGE);
        if (!$mapping instanceof ArrayStruct) {
            return;
        }

        $mapping->set($field->getPropertyName(), $ids);
    }

    /**
     * @param array<mixed> $row
     * @param array<string, Field> $fields
     */
    protected function translate(EntityDefinition $definition, Entity $entity, array $row, string $root, Context $context, array $fields): void
    {
        $inherited = $definition->isInheritanceAware() && $context->considerInheritance();

        $chain = EntityDefinitionQueryHelper::buildTranslationChain($root, $context, $inherited);

        $translatedFields = $this->getTranslatedFields($definition, $fields);

        foreach ($translatedFields as $field => $typed) {
            $fieldValue = self::value($row, $root, $field);
            $translation = $fieldValue !== null ? $typed->getSerializer()->decode($typed, $fieldValue) : null;

            $this->addTranslated($entity, $field, $translation);

            $chainFieldValue = self::value($row, $chain[0], $field);
            $this->assignValue($entity, $field, $chainFieldValue !== null ? ($fieldValue === $chainFieldValue ? $translation : $typed->getSerializer()->decode($typed, $chainFieldValue)) : null);
        }
    }

    /**
     * @param array<Field> $fields
     *
     * @return array<string, Field>
     */
    protected function getTranslatedFields(EntityDefinition $definition, array $fields): array
    {
        $key = $definition->getEntityName();
        if (isset(self::$translatedFields[$key])) {
            return self::$translatedFields[$key];
        }

        $translatedFields = [];
        /** @var TranslatedField $field */
        foreach ($fields as $field) {
            $translatedFields[$field->getPropertyName()] = EntityDefinitionQueryHelper::getTranslatedField($definition, $field);
        }

        return self::$translatedFields[$key] = $translatedFields;
    }

    /**
     * @param array<mixed> $row
     */
    protected function manyToOne(array $row, string $root, ?Field $field, Context $context): ?Entity
    {
        if ($field === null) {
            throw DataAbstractionLayerException::entityHydratorError('No association field for "manyToOne" provided');
        }

        if (!$field instanceof AssociationField) {
            throw DataAbstractionLayerException::entityHydratorError(\sprintf('Provided field %s is no association field', $field->getPropertyName()));
        }
        $pk = $this->getManyToOneProperty($field);

        $association = $root . '.' . $field->getPropertyName();

        $key = $association . '.' . $pk;

        if (!isset($row[$key])) {
            return null;
        }

        if (self::$partial !== [] && !isset(self::$partialFullPaths[$pk])) {
            self::$partialFullPaths[$key] = true;
        }

        return $this->hydrateEntity($field->getReferenceDefinition(), $field->getReferenceDefinition()->getEntityClass(), $row, $association, $context);
    }

    /**
     * @param array<mixed> $row
     */
    protected function customFields(EntityDefinition $definition, array $row, string $root, Entity $entity, ?Field $field, Context $context): void
    {
        if ($field === null) {
            return;
        }

        $inherited = $field->is(Inherited::class) && $context->considerInheritance();

        $propertyName = $field->getPropertyName();

        $value = self::value($row, $root, $propertyName);

        if ($field instanceof TranslatedField) {
            $customField = EntityDefinitionQueryHelper::getTranslatedField($definition, $field);

            $chain = EntityDefinitionQueryHelper::buildTranslationChain($root, $context, $inherited);

            $decoded = $customField->getSerializer()->decode($customField, self::value($row, $chain[0], $propertyName));

            $this->assignValue($entity, $propertyName, $decoded);

            $values = [];
            foreach ($chain as $accessor) {
                $values[] = self::value($row, $accessor, $propertyName);
            }

            if ($values === []) {
                return;
            }

            /**
             * `array_merge`s ordering is reversed compared to the translations array.
             * In other terms: The first argument has the lowest 'priority', so we need to reverse the array
             */
            $merged = $this->mergeJson(array_reverse($values, false));
            $decoded = $customField->getSerializer()->decode($customField, $merged);
            $this->addTranslated($entity, $propertyName, $decoded);

            if ($inherited) {
                /*
                 * The translations chains array has the structure: [
                 *      main language,
                 *      parent with main language,
                 *      fallback language,
                 *      parent with fallback language,
                 * ]
                 *
                 * We need to join the first two to get the inherited field value of the main translation
                 */
                $values = [
                    self::value($row, $chain[1], $propertyName),
                    self::value($row, $chain[0], $propertyName),
                ];

                $merged = $this->mergeJson($values);
                $decoded = $customField->getSerializer()->decode($customField, $merged);
                $this->assignValue($entity, $propertyName, $decoded);
            }

            return;
        }

        // field is not inherited or request should work with raw data? decode child attributes and return
        if (!$inherited) {
            $value = $field->getSerializer()->decode($field, $value);
            $this->assignValue($entity, $propertyName, $value);

            return;
        }

        $parentKey = $root . '.' . $propertyName . '.inherited';

        // parent has no attributes? decode only child attributes and return
        if (!isset($row[$parentKey])) {
            $value = $field->getSerializer()->decode($field, $value);

            $this->assignValue($entity, $propertyName, $value);

            return;
        }

        // merge child attributes with parent attributes and assign
        $mergedJson = $this->mergeJson([$row[$parentKey], $value]);

        $merged = $field->getSerializer()->decode($field, $mergedJson);

        $this->assignValue($entity, $propertyName, $merged);
    }

    /**
     * @param array<mixed> $row
     */
    protected static function value(array $row, string $root, string $property): ?string
    {
        $accessor = $root . '.' . $property;

        return $row[$accessor] ?? null;
    }

    protected function getManyToOneProperty(AssociationField $field): string
    {
        $key = $field->getReferenceDefinition()->getEntityName() . '.' . $field->getReferenceField();
        if (isset(self::$manyToOne[$key])) {
            return self::$manyToOne[$key];
        }

        $reference = $field->getReferenceDefinition()->getFields()->getByStorageName(
            $field->getReferenceField()
        );

        if ($reference === null) {
            throw DataAbstractionLayerException::fieldByStorageNameNotFound(
                $field->getReferenceDefinition()->getEntityName(),
                $field->getReferenceField()
            );
        }

        return self::$manyToOne[$key] = $reference->getPropertyName();
    }

    /**
     * @param array<string|null> $jsonStrings
     */
    protected function mergeJson(array $jsonStrings): string
    {
        $merged = [];
        foreach ($jsonStrings as $string) {
            if ($string === null) {
                continue;
            }

            $decoded = json_decode($string, true, 512, \JSON_THROW_ON_ERROR);

            if (!$decoded) {
                continue;
            }

            foreach ($decoded as $key => $value) {
                if ($value === null) {
                    continue;
                }

                $merged[$key] = $value;
            }
        }

        return json_encode($merged, \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function mapPartialFieldsToHydrate(array $fields, string $currentPath): void
    {
        foreach ($fields as $field => $values) {
            self::$partialFullPaths[$currentPath . '.' . $field] = true;

            $this->mapPartialFieldsToHydrate($values, $currentPath . '.' . $field);
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function hydrateEntity(EntityDefinition $definition, string $entityClass, array $row, string $root, Context $context): Entity
    {
        $isPartial = self::$partial !== [];
        $hydratorClass = $definition->getHydratorClass();

        if ($isPartial) {
            $hydratorClass = EntityHydrator::class;
        }

        $hydrator = $this->container->get($hydratorClass);

        if (!$hydrator instanceof self) {
            throw DataAbstractionLayerException::entityHydratorError(\sprintf('Hydrator for entity %s not registered', $definition->getEntityName()));
        }

        $identifier = implode('-', self::buildUniqueIdentifier($definition, $row, $root));

        $cacheKey = $root . '::' . $identifier;

        if (isset(self::$hydrated[$cacheKey])) {
            return self::$hydrated[$cacheKey];
        }

        if (!is_a($entityClass, Entity::class, true)) {
            throw DataAbstractionLayerException::entityHydratorError(\sprintf('Expected entity class to be instance of Entity.php, got %s', $entityClass));
        }

        /** @var class-string<Entity> $entityClass */
        $entity = $this->createEntity($definition, $entityClass, $identifier, $isPartial);

        if (!self::isLazyObject($entity)) {
            $entity->addExtension(EntityReader::FOREIGN_KEYS, new ArrayStruct([], $definition->getEntityName() . '_foreign_keys_extension'));
            $entity->addExtension(EntityReader::INTERNAL_MAPPING_STORAGE, new ArrayStruct());

            $entity->setUniqueIdentifier($identifier);
            $entity->internalSetEntityData($definition->getEntityName(), $definition->getFieldVisibility());
        }

        $entity = $hydrator->assign($definition, $entity, $root, $row, $context);

        return self::$hydrated[$cacheKey] = $entity;
    }

    /**
     * @param class-string<Entity> $entityClass
     */
    private function createEntity(EntityDefinition $definition, string $entityClass, string $identifier, bool $isPartial): Entity
    {
        if (!$isPartial || is_a($entityClass, ArrayEntity::class, true)) {
            return new $entityClass();
        }

        $entityName = $definition->getEntityName();
        $reflection = new \ReflectionClass($entityClass);
        // @phpstan-ignore method.notFound (PHP 8.4 native lazy-object API)
        $entity = $reflection->newLazyGhost(static function (Entity $entity) use ($entityName, $identifier): void {
            throw DataAbstractionLayerException::partialFieldNotLoaded(
                $entityName,
                $entity::class,
                $identifier,
                self::getLoadedFields($entity),
            );
        });

        if (!$entity instanceof Entity) {
            throw DataAbstractionLayerException::entityHydratorError(\sprintf('Expected instance of Entity.php, got %s', $entity::class));
        }

        $this->writeRawProperty($entity, '_uniqueIdentifier', $identifier);
        $this->writeRawProperty($entity, '_entityName', $entityName);
        $this->writeRawProperty($entity, '_fieldVisibility', $definition->getFieldVisibility());
        $this->writeRawProperty($entity, 'translated', []);
        $this->writeRawProperty($entity, 'extensions', [
            EntityReader::FOREIGN_KEYS => new ArrayStruct([], $entityName . '_foreign_keys_extension'),
            EntityReader::INTERNAL_MAPPING_STORAGE => new ArrayStruct(),
        ]);

        return $entity;
    }

    private function assignValue(Entity $entity, string $property, mixed $value): void
    {
        if (!self::isLazyObject($entity)) {
            $entity->assign([$property => $value]);

            return;
        }

        if ($this->writeRawProperty($entity, $property, $value)) {
            return;
        }

        $entity->assign([$property => $value]);
    }

    private function addTranslated(Entity $entity, string $property, mixed $value): void
    {
        if (!self::isLazyObject($entity)) {
            $entity->addTranslated($property, $value);

            return;
        }

        $translated = $this->getRawProperty($entity, 'translated');
        \assert(\is_array($translated));
        $translated[$property] = $value;

        $this->writeRawProperty($entity, 'translated', $translated);
    }

    private function addExtension(Entity $entity, string $property, ArrayStruct|Entity $extension): void
    {
        if (!self::isLazyObject($entity)) {
            $entity->addExtension($property, $extension);

            return;
        }

        $extensions = $this->getRawProperty($entity, 'extensions');
        \assert(\is_array($extensions));
        $extensions[$property] = $extension;

        $this->writeRawProperty($entity, 'extensions', $extensions);
    }

    private function writeRawProperty(object $object, string $property, mixed $value): bool
    {
        $reflection = new \ReflectionClass($object::class);

        do {
            if ($reflection->hasProperty($property)) {
                // @phpstan-ignore method.notFound (PHP 8.4 native lazy-object API)
                $reflection->getProperty($property)->setRawValueWithoutLazyInitialization($object, $value);

                return true;
            }

            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        return false;
    }

    private function getRawProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionClass($object::class);

        do {
            if ($reflection->hasProperty($property)) {
                // @phpstan-ignore method.notFound (PHP 8.4 native lazy-object API)
                return $reflection->getProperty($property)->getRawValue($object);
            }

            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        throw DataAbstractionLayerException::entityHydratorError(\sprintf('Property %s not found on %s', $property, $object::class));
    }

    private static function isLazyObject(Entity $entity): bool
    {
        $reflection = new \ReflectionClass($entity::class);

        // @phpstan-ignore method.notFound (PHP 8.4 native lazy-object API)
        return $reflection->isUninitializedLazyObject($entity);
    }

    /**
     * @return list<string>
     */
    private static function getLoadedFields(Entity $entity): array
    {
        $fields = [];
        $reflection = new \ReflectionClass($entity::class);

        do {
            foreach ($reflection->getProperties() as $property) {
                $name = $property->getName();

                if (str_starts_with($name, '_') || \in_array($name, ['extensions', 'createdAt', 'updatedAt'], true)) {
                    continue;
                }

                // @phpstan-ignore method.notFound (PHP 8.4 native lazy-object API)
                if ($property->isLazy($entity)) {
                    continue;
                }

                if ($name === EntityDefinition::TRANSLATED_FIELD) {
                    // @phpstan-ignore method.notFound (PHP 8.4 native lazy-object API)
                    if ($property->getRawValue($entity) === []) {
                        continue;
                    }
                }

                $fields[] = $name;
            }

            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        sort($fields);

        return $fields;
    }
}
