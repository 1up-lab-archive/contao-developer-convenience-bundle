<?php

declare(strict_types=1);

namespace Oneup\DeveloperConvenienceBundle\Command;

use Contao\InstallationBundle\Database\Installer;
use Oneup\DeveloperConvenienceBundle\Database\ContaoDatabaseUpdateManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            ->addOption('complete', null, InputOption::VALUE_NONE, 'If defined, all assets of the database which are not relevant to the current metadata will be dropped.')
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Dumps the generated SQL statements to the screen (does not execute them).')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Causes the generated SQL statements to be physically executed against your database.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dumpSql = true === $input->getOption('dump-sql');
        $force = true === $input->getOption('force');
        $saveMode = !$input->getOption('complete');

        $io->title('Running Contao database updates');

        /** @var Container $container */
        $container = $this->getContainer();

        /** @var ContaoDatabaseUpdateManager $updateManager */
        $updateManager = $container->get('oneup.dca.contao.db_update_manager');

        // Run version updates
        $messages = $updateManager->runUpdates();

        if (\count($messages)) {
            $io->block($messages);
        }

        /** @var Installer $installer */
        $installer = $container->get('contao.installer');

        // Get adjusting contao database commands
        $sqlCommands = $installer->getCommands();

        $defaultAnswers = [
            'CREATE' => true,
            'ALTER_TABLE' => true,
            'ALTER_CHANGE' => true,
            'ALTER_ADD' => true,
            'DROP' => !$saveMode,
            'ALTER_DROP' => !$saveMode,
        ];

        if (empty($sqlCommands)) {
            $io->success('Nothing to update - your database is already in sync with the current entity metadata.');

            return 0;
        }

        $sqls = 0;

        foreach ($sqlCommands as $category => $commands) {
            $sqls += \count($commands);

            if ($dumpSql) {
                $io->text(sprintf('The following SQL %s statements will be executed:', $category));
                $io->newLine();

                foreach ($commands as $command) {
                    $io->text(sprintf('    %s;', $command));
                }

                $io->newLine();
            }

            if ($force) {
                if (!$io->confirm(sprintf('Do you wanna run the %s statements?', $category), \array_key_exists($category, $defaultAnswers) ? $defaultAnswers[$category] : false)) {
                    $io->text('Skipping these statements...');
                    $io->newLine();
                    continue;
                }

                $io->text('Updating database schema...');

                foreach ($commands as $hash => $command) {
                    $installer->execCommand($hash);
                }

                $pluralization = (1 === \count($commands)) ? 'query was' : 'queries were';

                $io->text(sprintf('    <info>%s</info> %s executed', \count($commands), $pluralization));
                $io->success('Database schema updated successfully!');
            }
        }

        if ($force) {
            $io->success('Contao database updates successfully excuted.');
        }

        if ($dumpSql || $force) {
            return 0;
        }

        $io->caution(
            [
                'This operation should not be executed in a production environment!',
                '',
                'Use the incremental update to detect changes during development and use',
                'the SQL DDL provided to manually update your database in production.',
            ]
        );

        $io->text(
            [
                sprintf('The Contao Updater would execute <info>"%s"</info> queries to update the database.', $sqls),
                '',
                'Please run the operation by passing one - or both - of the following options:',
                '',
                sprintf('    <info>%s --force</info> to execute the command', $this->getName()),
                sprintf('    <info>%s --dump-sql</info> to dump the SQL statements to the screen', $this->getName()),
            ]
        );

        $io->newLine();

        return 1;
    }
}
