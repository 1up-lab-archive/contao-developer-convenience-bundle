<?php

declare(strict_types=1);

namespace Oneup\DeveloperConvenienceBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class SyncProjectCommand extends Command
{
    /** @var string */
    protected $contaoWebDir;

    /** @var string */
    protected $projectDir;

    public function __construct(string $contaoWebDir, string $projectDir, string $name = null)
    {
        $this->contaoWebDir = $contaoWebDir;
        $this->projectDir = $projectDir;

        parent::__construct($name);
    }

    public function configure(): void
    {
        $this
            ->setName('dev:sync')
            ->setDescription('Synchronise Database and Files from a remote installation')
            ->addArgument('environment', InputArgument::REQUIRED, 'Where do you want to synchronise from?')
            ->addArgument('timeout', InputArgument::OPTIONAL, 'What timeout should the commands have?')
            ->addOption('database-only', null, InputOption::VALUE_OPTIONAL, 'Only sync database?', false)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);

        // Read configuration
        $config = $this->getConfigurationForEnvironment((string) $input->getArgument('environment'));

        $timeout = (int) $input->getArgument('timeout');
        $databaseOnly = (bool) $input->getOption('database-only');

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

            if (false === $databaseOnly) {
                $this->syncFilesystem($config, $io, $timeout);
            }

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
            'target' => $this->contaoWebDir,
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

    private function getConfigurationForEnvironment(string $environment): array
    {
        $syncDir = sprintf('%s/var/sync', $this->projectDir);

        $file = sprintf('%s/.mage.yml', $this->projectDir);
        $config = Yaml::parse(file_get_contents($file));

        if (!\array_key_exists($environment, $config['magephp']['environments'])) {
            throw new LogicException('Environment does not exist');
        }

        $envConfig = $config['magephp']['environments'][$environment];

        // Search for correct parameters file
        $parametersEnv = 'dev';

        foreach (['pre-deploy', 'on-deploy', 'on-release', 'post-release', 'post-deploy'] as $stage) {
            if (!\array_key_exists($stage, $envConfig)) {
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

                if ('custom/copy-parameters' !== $commandName && 'custom/copy-env' !== $commandName && 'custom/copy-config' !== $commandName) {
                    continue;
                }

                if (\is_array($command) && \is_array($command['custom/copy-parameters']) && \array_key_exists('env', $command['custom/copy-parameters'])) {
                    $parametersEnv = $command['custom/copy-parameters']['env'];
                    break 2;
                }

                if (\is_array($command) && \is_array($command['custom/copy-env']) && \array_key_exists('env', $command['custom/copy-env'])) {
                    $parametersEnv = $command['custom/copy-env']['env'];
                    break 2;
                }

                if (\is_array($command) && \is_array($command['custom/copy-config']) && \array_key_exists('env', $command['custom/copy-config'])) {
                    $parametersEnv = $command['custom/copy-config']['env'];
                    break 2;
                }

                break 2;
            }
        }

        return [
            'host' => $envConfig['hosts'][0],
            'user' => $envConfig['user'],
            'directory' => rtrim($envConfig['host_path'], '/'),
            'tmp' => $syncDir,
            'db' => [
                'remote' => $this->getDatabaseConfig($parametersEnv),
                'local' => $this->getDatabaseConfig('local'),
            ],
        ];
    }

    private function hasEnvFileSupport(string $env = 'local'): bool
    {
        if ('local' === $env) {
            return file_exists('.env');
        }

        return file_exists(sprintf('.env.%s.dist', $env));
    }

    private function hasHostsConfigSupport(string $env = 'local'): bool
    {
        if ('local' === $env) {
            return file_exists(sprintf('%s/config/config.local.yaml', $this->projectDir));
        }

        return file_exists(sprintf('%s/config/hosts/config.%s.yaml', $this->projectDir, $env));
    }

    private function getDatabaseConfig(string $environment): array
    {
        $file = sprintf('%s/.env.local', $this->projectDir);

        if (file_exists($file)) {
            return $this->getDatabaseConfigFlex($environment);
        }

        if ($this->hasEnvFileSupport($environment)) {
            return $this->getDatabaseConfigEnv($environment);
        }

        if ($this->hasHostsConfigSupport($environment)) {
            return $this->getDatabaseConfigHosts($environment);
        }

        return $this->getDatabaseConfigDefault($environment);
    }

    private function getDatabaseConfigEnv(string $environment): array
    {
        // Env-File Support
        $file = 'local' === $environment ?
            sprintf('%s/.env', $this->projectDir) :
            sprintf('%s/.env.%s.dist', $this->projectDir, $environment)
        ;

        if (!file_exists($file)) {
            throw new LogicException(sprintf('No .env file for environment "%s" found.', $environment));
        }

        return $this->parseDatabaseEnv($file);
    }

    private function getDatabaseConfigFlex(string $environment): array
    {
        $file = 'local' === $environment ?
            sprintf('%s/.env.%s', $this->projectDir, $environment) :
            sprintf('%s/config/hosts/.env.%s.dist', $this->projectDir, $environment);

        if (!file_exists($file)) {
            throw new LogicException(sprintf('File %s not found', $file));
        }

        return $this->parseDatabaseEnv($file);
    }

    private function getDatabaseConfigHosts(string $environment): array
    {
        $file = 'local' === $environment ?
            sprintf('%s/config/config.local.yaml', $this->projectDir) :
            sprintf('%s/config/hosts/config.%s.yaml', $this->projectDir, $environment)
        ;

        return $this->parseConfigYaml($file, $environment);
    }

    private function getDatabaseConfigDefault(string $environment): array
    {
        $file = 'local' === $environment ?
            sprintf('%s/config/parameters.yml', $this->projectDir) :
            sprintf('%s/config/parameters.%s.yml.dist', $this->projectDir, $environment)
        ;

        return $this->parseConfigYaml($file, $environment);
    }

    private function parseConfigYaml(string $configFile, string $environment): array
    {
        if (!file_exists($configFile)) {
            throw new LogicException(sprintf('No parameters file for environment "%s" found.', $environment));
        }

        $baseConfigFile = sprintf('%s/config/config.yaml', $this->projectDir);

        if (!file_exists($baseConfigFile)) {
            $baseConfigFile = sprintf('%s/config/config.yml', $this->projectDir);
        }

        $baseConfig = ['parameters' => []];

        if (file_exists($baseConfigFile)) {
            $baseConfig = Yaml::parse(file_get_contents($baseConfigFile));
        }

        $config = Yaml::parse(file_get_contents($configFile));

        $parameters = $config['parameters'];
        $parameters += $baseConfig['parameters'];

        return [
            'host' => $parameters['database_host'],
            'user' => $parameters['database_user'],
            'pass' => $parameters['database_password'],
            'port' => $parameters['database_port'],
            'name' => $parameters['database_name'],
        ];
    }

    private function parseDatabaseEnv(string $envFile): array
    {
        $dotenv = new Dotenv();
        $dotenv->load($envFile);

        $url = getenv('DATABASE_URL');
        $components = parse_url($url);

        return [
            'host' => $components['host'],
            'user' => $components['user'],
            'pass' => $components['pass'],
            'port' => $components['port'],
            'name' => ltrim($components['path'], '/'),
        ];
    }

    private function runSubTask(SymfonyStyle $io, string $text, string $task, float $timeout = 60): void
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
