<?php

declare(strict_types=1);

namespace Oneup\DeveloperConvenienceBundle;

use Oneup\DeveloperConvenienceBundle\DependencyInjection\Compiler\AddContaoDatabaseUpdatePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class DeveloperConvenienceBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AddContaoDatabaseUpdatePass());
    }
}
