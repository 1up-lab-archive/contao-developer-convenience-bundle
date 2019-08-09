<?php

declare(strict_types=1);

namespace Oneup\DeveloperConvenienceBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class AddContaoDatabaseUpdatePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('oneup.dca.contao.db_update_manager')) {
            return;
        }

        $definition = $container->findDefinition('oneup.dca.contao.db_update_manager');
        $updates = $container->findTaggedServiceIds('oneup.dca.contao.db_udpate');

        foreach ($updates as $id => $tags) {
            // inject container (AbstractVersionUpdate has ContainerAwareInterface)
            $update = $container->findDefinition($id);
            $update->addMethodCall('setContainer', [new Reference('service_container')]);

            $definition->addMethodCall('addUpdate', [
                new Reference($id),
            ]);
        }
    }
}
