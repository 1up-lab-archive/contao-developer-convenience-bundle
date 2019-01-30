<?php

declare(strict_types=1);

namespace Oneup\DeveloperConvenienceBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class SyncProjectCommand extends ContainerAwareCommand
{
    public function configure(): void
    {
        $this
            ->setName('dev:sync')
            ->setDescription('Synchronise Database and Files from a remote installation')
            ->addArgument('environment', InputArgument::REQUIRED, 'Where do you want to synchronise from?')
            ->addArgument('timeout', InputArgument::OPTIONAL, 'What timeout should the commands have?')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);

        // Read configuration
        $config = $this->getConfigurationForEnvironment($input->getArgument('environment'));

        $timeout = (int) $input->getArgument('timeout');

        if (!$io->confirm('Are you sure to synchronise from a remote installation? This will overwrite your local data!', true)) {
            $io->error('Abort synchronisation.');
            $io->newLine();

            return;
        }

        $start = microtime(true);

        if (!$timeout) {
            $timeout = 60;
        }

        try {
            $this->prepareSync($config, $io);
            $this->syncFilesystem($config, $io, $timeout);
            $this->syncDatabase($config, $io, $timeout);
            $this->createSymlinks($output);
        } catch (ProcessFailedException $exception) {
            $io->error(sprintf(
                "Synchronisation failed after %s seconds.\n\nMessage:\n%s\n\nCommand:\n%s",
                number_format(microtime(true) - $start, 2),
                trim($exception->getProcess()->getErrorOutput()),
                trim($exception->getProcess()->getCommandLine())
            ));

            $io->newLine();

            return;
        }

        $io->success(sprintf('Synchronisation completed in %s seconds.', number_format(microtime(true) - $start, 2)));
        $io->newLine();
    }

    protected function createSymlinks(OutputInterface $output): void
    {
        $command = $this->getApplication()->find('contao:symlinks');

        $input = new ArrayInput([
            'command' => 'contao:symlinks',
            'target' => $this->getContainer()->getParameter('contao.web_dir'),
        ]);

        $command->run($input, $output);
    }

    protected function prepareSync(array $config, SymfonyStyle $io): void
    {
        $this->runSubTask($io, 'Created temporary sync directory.', sprintf('mkdir -p %s', $config['tmp']));
    }

    protected function syncFilesystem(array $config, SymfonyStyle $io, int $timeout): void
    {
        $io->title('Synchronising remote filesystem');

        $this->runSubTask($io, 'Removed existing synced-files folder.', sprintf('rm -rf %s/files', $config['tmp']), $timeout);
        $this->runSubTask($io, 'Synchronised files to a new synced-files folder.', sprintf('scp -r %s@%s:%s/shared/files %s/files', $config['user'], $config['host'], $config['directory'], $config['tmp']), $timeout);
        $this->runSubTask($io, 'Removed existing files folder.', 'rm -rf files', $timeout);
        $this->runSubTask($io, 'Renamed synced-files folder to files.', sprintf('mv %s/files files', $config['tmp']), $timeout);
    }

    protected function syncDatabase(array $config, SymfonyStyle $io, int $timeout): void
    {
        $io->title('Synchronising remote database');

        $remoteConfig = $config['db']['remote'];
        $localConfig = $config['db']['local'];

        $passwordMask = null === $remoteConfig['pass'] ? '' : sprintf('-p\"%s\"', $remoteConfig['pass']);
        $this->runSubTask($io, 'Fetch a MySQL dump from the remote server.', sprintf(
            'ssh %s@%s "mysqldump -h%s --port=%s -u%s %s %s" > %s/dump.sql',
            $config['user'],
            $config['host'],
            $remoteConfig['host'],
            $remoteConfig['port'],
            $remoteConfig['user'],
            $passwordMask,
            $remoteConfig['name'],
            $config['tmp']
        ), $timeout);

        $passwordMask = null === $localConfig['pass'] ? '' : sprintf('-p\"%s\"', $remoteConfig['pass']);
        $this->runSubTask($io, 'Import dump from temporary file.', sprintf(
            'mysql -h%s --port=%s -u%s %s %s < %s/dump.sql',
            $localConfig['host'],
            $localConfig['port'],
            $localConfig['user'],
            $passwordMask,
            $localConfig['name'],
            $config['tmp']
        ), $timeout);

        $this->runSubTask($io, 'Clean up temporary files.', sprintf('rm %s/dump.sql', $config['tmp']), $timeout);
    }

    /**
     * @param string $environment
     *
     * @return array
     */
    private function getConfigurationForEnvironment(string $environment)
    {
        $projectDir = $this->getContainer()->getParameter('kernel.project_dir');
        $syncDir = sprintf('%s/var/sync', $projectDir);

        $file = sprintf('%s/.mage.yml', $projectDir);
        $config = Yaml::parse(file_get_contents($file));

        if (!array_key_exists($environment, $config['magephp']['environments'])) {
            throw new LogicException('Environment does not exist');
        }

        $envConfig = $config['magephp']['environments'][$environment];

        // Search for correct parameters file
        $parametersEnv = 'dev';

        foreach (['pre-deploy', 'on-deploy', 'on-release', 'post-release', 'post-deploy'] as $stage) {
            if (!array_key_exists($stage, $envConfig)) {
                continue;
            }

            if (null === $envConfig[$stage]) {
                continue;
            }

            /** @var array|string $command */
            foreach ($envConfig[$stage] as $command) {
                $commandName = $command;
                if (\is_array($command)) {
                    $commandName = array_keys($command)[0];
                }

                if ('custom/copy-parameters' !== $commandName && 'custom/copy-env' !== $commandName) {
                    continue;
                }

                if (\is_array($command) && \is_array($command['custom/copy-parameters']) && array_key_exists('env', $command['custom/copy-parameters'])) {
                    $parametersEnv = $command['custom/copy-parameters']['env'];
                    break 2;
                }

                if (\is_array($command) && \is_array($command['custom/copy-env']) && array_key_exists('env', $command['custom/copy-env'])) {
                    $parametersEnv = $command['custom/copy-env']['env'];
                    break 2;
                }

                break 2;
            }
        }

        $dbConfig = $this->getDatabaseConfig($parametersEnv, $this->hasEnvFileSupport($parametersEnv));

        return [
            'host' => $envConfig['hosts'][0],
            'user' => $envConfig['user'],
            'directory' => rtrim($envConfig['host_path'], '/'),
            'tmp' => $syncDir,
            'db' => [
                'remote' => $this->getDatabaseConfig($parametersEnv, $this->hasEnvFileSupport($parametersEnv)),
                'local' => $this->getDatabaseConfig('local', $this->hasEnvFileSupport('local')),
            ],
        ];
    }

    private function hasEnvFileSupport($env = 'local')
    {
        if ('local' === $env) {
            return file_exists('.env');
        }

        return file_exists(sprintf('.env.%s.dist', $env));
    }

    private function getDatabaseConfig($environment, $hasEnvFileSupport)
    {
        $rootDir = $this->getContainer()->getParameter('kernel.root_dir');

        if (!$hasEnvFileSupport) {
            $file = 'local' === $environment ?
                sprintf('%s/config/parameters.yml', $rootDir) :
                sprintf('%s/config/parameters.%s.yml.dist', $rootDir, $environment)
            ;

            if (!file_exists($file)) {
                throw new LogicException(sprintf('No parameters file for environment "%s" found.', $environment));
            }

            $config = Yaml::parse(file_get_contents($file));

            return [
                'host' => $config['parameters']['database_host'],
                'user' => $config['parameters']['database_user'],
                'pass' => $config['parameters']['database_password'],
                'port' => $config['parameters']['database_port'],
                'name' => $config['parameters']['database_name'],
            ];
        }

        // Env-File Support
        $file = 'local' === $environment ?
            sprintf('%s/../.env', $rootDir) :
            sprintf('%s/../.env.%s.dist', $rootDir, $environment)
        ;

        if (!file_exists($file)) {
            throw new LogicException(sprintf('No .env file for environment "%s" found.', $environment));
        }

        $dotenv = new Dotenv();
        $dotenv->load($file);

        $url = getenv('DATABASE_URL');

        $components = parse_url($url);

        $dotenv = new Dotenv();
        $dotenv->load($rootDir.'/../.env');

        return [
            'host' => $components['host'],
            'user' => $components['user'],
            'pass' => $components['pass'],
            'port' => $components['port'],
            'name' => ltrim($components['path'], '/'),
        ];
    }

    private function runSubTask(SymfonyStyle $io, string $text, string $task, int $timeout = 60): void
    {
        $process = new Process($task, null, null, null, $timeout);
        $process->run();

        $success = $process->isSuccessful();
        $mask = sprintf('<fg=%s>%s</>', $success ? 'green' : 'red', $success ? '✔' : '✗');

        $io->text(sprintf('%s %s', $mask, $text));

        if (!$success) {
            throw new ProcessFailedException($process);
        }
    }
}
