<?php

declare(strict_types=1);

namespace Oneup\DeveloperConvenienceBundle\Command;

use Oneup\DeveloperConvenienceBundle\Database\ContaoDatabaseUpdateManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Container;

class ContaoDbUpdateCommand extends ContainerAwareCommand
{
    public function configure(): void
    {
        $this
            ->setName('dev:contao:db-update')
            ->setDescription('Runs contao database updates')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Running Contao database updates');

        /** @var Container $container */
        $container = $this->getContainer();

        /** @var ContaoDatabaseUpdateManager $updateManager */
        $updateManager = $container->get('oneup.dca.contao.db_update_manager');

        $updateManager->runUpdates();

        $io->success('Contao database updates successfully excuted.');
    }
}
