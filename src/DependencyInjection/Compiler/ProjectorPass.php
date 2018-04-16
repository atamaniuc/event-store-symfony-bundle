<?php
/**
 * prooph (http://getprooph.org/)
 *
 * @see       https://github.com/prooph/event-store-symfony-bundle for the canonical source repository
 * @copyright Copyright (c) 2016 prooph software GmbH (http://prooph-software.com/)
 * @license   https://github.com/prooph/event-store-symfony-bundle/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Prooph\Bundle\EventStore\DependencyInjection\Compiler;

use Prooph\Bundle\EventStore\DependencyInjection\ProophEventStoreExtension;
use Prooph\Bundle\EventStore\Exception\RuntimeException;
use Prooph\Bundle\EventStore\Projection\Projection;
use Prooph\Bundle\EventStore\Projection\ReadModelProjection;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class ProjectorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $projectorsIds = array_keys($container->findTaggedServiceIds(ProophEventStoreExtension::TAG_PROJECTION));

        if (! $container->hasDefinition('prooph_event_store.projection_read_models_locator')
            || ! $container->hasDefinition('prooph_event_store.projection_manager_for_projections_locator')
            || ! $container->hasDefinition('prooph_event_store.projections_locator')
        ) {
            return;
        }

        $readModelsLocatorDefinition = $container->getDefinition('prooph_event_store.projection_read_models_locator');
        $readModelsLocator = $readModelsLocatorDefinition->getArgument(0);

        $projectionManagerForProjectionsLocatorDefinition = $container->getDefinition('prooph_event_store.projection_manager_for_projections_locator');
        $projectionManagerForProjectionsLocator = $projectionManagerForProjectionsLocatorDefinition->getArgument(0);

        $projectionsLocatorDefinition = $container->getDefinition('prooph_event_store.projections_locator');
        $projectionsLocator = $projectionsLocatorDefinition->getArgument(0);

        foreach ($projectorsIds as $id) {
            $projectorDefinition = $container->getDefinition($id);
            $projectorClass = new ReflectionClass($projectorDefinition->getClass());

            self::assertProjectionHasAValidClass($id, $projectorClass);

            $isReadModelProjector = $projectorClass->implementsInterface(ReadModelProjection::class);

            $tags = $projectorDefinition->getTag(ProophEventStoreExtension::TAG_PROJECTION);
            foreach ($tags as $tag) {
                self::assertProjectionTagHasAttribute($id, $tag, 'projection_name');
                self::assertProjectionTagHasAttribute($id, $tag, 'projection_manager');
                self::assertProjectionManagerExists($tag['projection_manager'], $id, $container);
                if ($isReadModelProjector) {
                    self::assertProjectionTagHasAttribute($id, $tag, 'read_model');
                    $container->setAlias(
                        sprintf('%s.%s.read_model', ProophEventStoreExtension::TAG_PROJECTION, $tag['projection_name']),
                        $tag['read_model']
                    );

                    $readModelsLocator[$tag['projection_name']] = new Reference($tag['read_model']);
                }

                $projectionManagerForProjectionsLocator[$tag['projection_name']] = new Reference(sprintf('prooph_event_store.projection_manager.%s', $tag['projection_manager']));
                $projectionsLocator[$tag['projection_name']] = new Reference($id);

                //alias definition for using the correct ProjectionManager
                $container->setAlias(
                    sprintf('%s.%s.projection_manager', ProophEventStoreExtension::TAG_PROJECTION, $tag['projection_name']),
                    sprintf('prooph_event_store.projection_manager.%s', $tag['projection_manager'])
                );

                if ($id !== sprintf('%s.%s', ProophEventStoreExtension::TAG_PROJECTION, $tag['projection_name'])) {
                    $container->setAlias(sprintf('%s.%s', ProophEventStoreExtension::TAG_PROJECTION, $tag['projection_name']), $id);
                }
            }
        }

        $projectionManagerForProjectionsLocatorDefinition->replaceArgument(0, $projectionManagerForProjectionsLocator);
        $readModelsLocatorDefinition->replaceArgument(0, $readModelsLocator);
        $projectionsLocatorDefinition->replaceArgument(0, $projectionsLocator);
    }

    /**
     * @param string $serviceId The id of the service that is verified
     * @param ReflectionClass $projectionClass The Reflection of the service that is verified
     * @throws RuntimeException if the service does implement neither ReadModelProjection nor Projection.
     */
    private static function assertProjectionHasAValidClass(string $serviceId, ReflectionClass $projectionClass): void
    {
        if (! $projectionClass->implementsInterface(ReadModelProjection::class)
            && ! $projectionClass->implementsInterface(Projection::class)
        ) {
            throw new RuntimeException(sprintf(
                'Tagged service "%s" must implement either "%s" or "%s" ',
                $serviceId,
                ReadModelProjection::class,
                Projection::class
            ));
        }
    }

    /**
     * @param string $serviceId The id of the service whose tag is verified
     * @param array $tag The actual tag
     * @param string $attributeName The attribute that has to be available in the tag
     * @throws RuntimeException if the attribute is not available in the tag
     */
    private static function assertProjectionTagHasAttribute(string $serviceId, array $tag, string $attributeName): void
    {
        if (! isset($tag[$attributeName])) {
            throw new RuntimeException(sprintf(
                '"%s" argument is missing from on "%s" tagged service "%s"',
                $attributeName,
                ProophEventStoreExtension::TAG_PROJECTION,
                $serviceId
            ));
        }
    }

    /**
     * @param string $name The name of the projection manager
     * @param string $taggedServiceId The projection service which has been tagged
     * @param ContainerBuilder $container
     * @throws RuntimeException if the projection manager does not exist
     */
    private static function assertProjectionManagerExists(
        string $name,
        string $taggedServiceId,
        ContainerBuilder $container
    ): void {
        if (! $container->has("prooph_event_store.projection_manager.$name")) {
            throw new RuntimeException(
                "Projection $taggedServiceId has been tagged as projection for the manager $name, "
                . "but this projection manager does not exist. Please configure a projection manager $name "
                . 'in the prooph_event_store configuration'
            );
        }
    }
}
