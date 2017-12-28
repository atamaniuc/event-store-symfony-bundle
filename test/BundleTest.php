<?php
/**
 * prooph (http://getprooph.org/)
 *
 * @see       https://github.com/prooph/event-store-symfony-bundle for the canonical source repository
 * @copyright Copyright (c) 2016 prooph software GmbH (http://prooph-software.com/)
 * @license   https://github.com/prooph/event-store-symfony-bundle/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ProophTest\Bundle\EventStore;

use PHPUnit\Framework\TestCase;
use Prooph\Bundle\EventStore\DependencyInjection\Compiler\MetadataEnricherPass;
use Prooph\Bundle\EventStore\DependencyInjection\Compiler\PluginsPass;
use Prooph\Bundle\EventStore\DependencyInjection\ProophEventStoreExtension;
use Prooph\Bundle\EventStore\ProophEventStoreBundle;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Metadata\MetadataEnricherAggregate;
use Prooph\EventStore\Metadata\MetadataEnricherPlugin;
use ProophTest\Bundle\EventStore\DependencyInjection\Fixture\Plugin\BlackHole;
use ProophTest\Bundle\EventStore\DependencyInjection\Fixture\Plugin\GlobalBlackHole;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\ResolveDefinitionTemplatesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\XmlDumper;
use Symfony\Component\DependencyInjection\Dumper\YamlDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class BundleTest extends TestCase
{
    /**
     * @test
     */
    public function it_builds_compiler_pass()
    {
        $container = new ContainerBuilder();
        $bundle = new ProophEventStoreBundle();
        $bundle->build($container);

        $config = $container->getCompilerPassConfig();
        $passes = $config->getBeforeOptimizationPasses();

        $foundPluginPass = false;
        $foundMetadataEnricherPass = false;

        foreach ($passes as $pass) {
            if ($pass instanceof PluginsPass) {
                $foundPluginPass = true;
            } elseif ($pass instanceof MetadataEnricherPass) {
                $foundMetadataEnricherPass = true;
            }
        }

        self::assertTrue($foundPluginPass, 'PluginsPass was not found');
        self::assertTrue($foundMetadataEnricherPass, 'MetadataEnricherPass was not found');
    }

    /**
     * @test
     */
    public function it_creates_an_event_store_with_plugins()
    {
        $container = $this->loadContainer('event_store', 'plugins');

        $eventStore = $container->get('prooph_event_store.main_store');
        self::assertInstanceOf(EventStore::class, $eventStore);

        $plugin = $container->get(BlackHole::class);
        self::assertInstanceOf(BlackHole::class, $plugin);
        self::assertTrue($plugin->valid);

        $plugin = $container->get(GlobalBlackHole::class);
        self::assertInstanceOf(GlobalBlackHole::class, $plugin);
        self::assertTrue($plugin->valid);
    }

    /**
     * @test
     */
    public function it_creates_an_event_store_with_metadata_enrichers()
    {
        $container = $this->loadContainer('event_store', 'metadata_enricher');

        $eventStore = $container->get('prooph_event_store.main_store');
        self::assertInstanceOf(EventStore::class, $eventStore);

        $metadataEnricherPlugin = $container->get('prooph_event_store.metadata_enricher_plugin.main_store');
        self::assertInstanceOf(MetadataEnricherPlugin::class, $metadataEnricherPlugin);

        $metadataEnricherAggregate = $container->get('prooph_event_store.metadata_enricher_aggregate.main_store');
        self::assertInstanceOf(MetadataEnricherAggregate::class, $metadataEnricherAggregate);

        $plugin = $container->get(DependencyInjection\Fixture\Metadata\BlackHole::class);
        self::assertInstanceOf(DependencyInjection\Fixture\Metadata\BlackHole::class, $plugin);
        self::assertTrue($plugin->valid);

        $plugin = $container->get(DependencyInjection\Fixture\Metadata\GlobalBlackHole::class);
        self::assertInstanceOf(DependencyInjection\Fixture\Metadata\GlobalBlackHole::class, $plugin);
        self::assertTrue($plugin->valid);
    }

    /**
     * @test
     */
    public function it_dumps_an_event_stores_with_plugins()
    {
        $this->dump('event_store', 'plugins');
    }

    /**
     * @test
     */
    public function it_dumps_an_event_stores_with_metadata_enrichers()
    {
        $this->dump('event_store', 'metadata_enricher');
    }

    private function loadContainer($fixture, $services)
    {
        $container = $this->getContainer();

        $this->loadFromFile($container, $fixture);

        $loadYaml = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/Resources/config')
        );
        $loadYaml->load($services . '.yml');
        $loadYaml->load('services.yml');

        $this->compileContainer($container);

        return $container;
    }

    private function getContainer()
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.debug' => false,
            'kernel.cache_dir' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sf_cache',
            'kernel.environment' => 'test',
            'kernel.root_dir' => __DIR__ . '/../src',
        ]));
        $container->registerExtension(new ProophEventStoreExtension());
        $bundle = new ProophEventStoreBundle();
        $bundle->build($container);

        return $container;
    }

    private function compileContainer(ContainerBuilder $container)
    {
        $container->getCompilerPassConfig()->setOptimizationPasses([new ResolveDefinitionTemplatesPass()]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);

        $container->compile();
    }

    private function loadFromFile(ContainerBuilder $container, string $configConfigFile)
    {
        $loadYaml = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/DependencyInjection/Fixture/config/yml')
        );
        $loadYaml->load($configConfigFile . '.yml');
    }

    private function dump(string $configFile, string $servicesFile)
    {
        $container = $this->loadContainer($configFile, $servicesFile);
        $dumper = null;

        $dumper = new XmlDumper($container);
        self::assertNotEmpty($dumper->dump());

        $dumper = new YamlDumper($container);
        self::assertNotEmpty($dumper->dump());
    }
}
