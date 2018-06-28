<?php

namespace Oneup\DeveloperConvenienceBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('developer_convenience');

        $rootNode
            ->children()
                ->arrayNode('imageoptim')
                ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('jpeg')
                        ->addDefaultsIfNotSet()
                            ->children()
                            ->integerNode('quality')->defaultValue(85)->end()
                            ->end()
                        ->end() // jpeg
                        ->arrayNode('png')
                        ->addDefaultsIfNotSet()
                            ->children()
                            ->scalarNode('quality')->defaultValue('65-80')->end()
                            ->integerNode('speed')->defaultValue(7)->end()
                            ->end()
                        ->end()
                    ->end() // png
                ->end()
            ->end()
        ;


        return $treeBuilder;
    }
}
