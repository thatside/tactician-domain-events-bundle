<?php

namespace BornFree\TacticianDomainEventBundle\DependencyInjection;

use BornFree\TacticianDoctrineDomainEvent\EventListener\CollectsEventsFromAllEntitiesManagedByUnitOfWork;
use BornFree\TacticianDoctrineDomainEvent\EventListener\CollectsEventsFromEntities;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

class TacticianDomainEventExtension extends Extension implements CompilerPassInterface
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $this->registerEventCollector($container, $config['collect_from_all_managed_entities']);
    }

    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition('tactician_domain_events.dispatcher')) {
            return;
        }

        $this->addListeners($container);
        $this->addSubscribers($container);
    }

    private function addListeners(ContainerBuilder $container)
    {
        $definition = $container->getDefinition('tactician_domain_events.dispatcher');
        $taggedServices = $container->findTaggedServiceIds('tactician.event_listener');

        foreach ($taggedServices as $id => $tags) {
            foreach ($tags as $attributes) {
                if (!isset($attributes['event'])) {
                    throw new \Exception('The tactician.event_listener tag must always have an event attribute');
                }

                if (!class_exists($attributes['event'])) {
                    throw new \Exception(
                        sprintf(
                            'Class %s registered as an event class in %s does not exist',
                            $attributes['event'],
                            $id
                        )
                    );
                }

                $listener = array_key_exists('method', $attributes)
                    ? [new Reference($id), $attributes['method']]
                    : new Reference($id);

                $definition->addMethodCall('addListener', [
                    $attributes['event'],
                    $listener
                ]);
            }
        }
    }

    private function addSubscribers(ContainerBuilder $container)
    {
        $definition = $container->getDefinition('tactician_domain_events.dispatcher');
        $taggedServices = $container->findTaggedServiceIds('tactician.event_subscriber');

        foreach ($taggedServices as $id => $tags) {
            $definition->addMethodCall('addSubscriber', [
                new Reference($id)
            ]);
        }
    }

    private function registerEventCollector(ContainerBuilder $container, $collectFromAllManagedEntities)
    {
        $class = CollectsEventsFromEntities::class;
        if ($collectFromAllManagedEntities) {
            $class = CollectsEventsFromAllEntitiesManagedByUnitOfWork::class;
        }

        $eventCollector = new Definition($class);
        $eventCollector->addTag('doctrine.event_subscriber', ['connection' => 'default']);

        $container->setDefinition('tactician_domain_events.doctrine.event_collector', $eventCollector);
    }
}
