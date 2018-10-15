<?php

declare(strict_types=1);

namespace Oneup\DeveloperConvenienceBundle\Command;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class ImageOptimCommand extends ContainerAwareCommand
{
    public function configure(): void
    {
        $this
            ->setName('dev:imageoptim')
            ->setDescription('This command optimizes all JPEG & PNG images within the files directory of a remote installation')
            ->addArgument('environment', InputArgument::REQUIRED, 'Please provide the environment you wish to optimize')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);

        $contaoFramework = $this->getContainer()->get('contao.framework');
        $contaoFramework->initialize();

        $config = $this->getConfigurationForEnvironment($input->getArgument('environment'));

        if (!$io->confirm("Are you sure you want to optimize all JPEG & PNG images? This will replace the original images!\n\n This commands creates a backup in the remote's shared directory", true)) {
            $io->error('Abort command.');
            $io->newLine();

            return;
        }

        $start = microtime(true);

        try {
            $this->checkForImagemin($io);
            $this->getFilesFromRemote($config, $io);
            $this->optimizeImages($io);
            $this->createRemoteBackup($config, $io);
            $this->moveNewFilesToRemote($config, $io);
            $this->resyncFilesOnRemote($config, $io);
            $this->removeTemporaryFolder($config, $io);
        } catch (ProcessFailedException $exception) {
            $io->error(sprintf(
                "Optimization failed after %s seconds.\n\nMessage:\n%s\n\nCommand:\n%s",
                number_format(microtime(true) - $start, 2),
                trim($exception->getProcess()->getErrorOutput()),
                trim($exception->getProcess()->getCommandLine())
            ));

            $io->newLine();

            return;
        }

        $io->success(sprintf('Optimization completed in %s seconds.', number_format(microtime(true) - $start, 2)));
        $io->newLine();
    }

    protected function checkForImagemin(SymfonyStyle $io): void
    {
        $projectDir = $this->getContainer()->getParameter('kernel.project_dir');
        if (!file_exists($projectDir.'/node_modules/imagemin')) {
            $io->error('Imagemin node-modules not found. Please see readme.md for detailed information.');
            $io->newLine();

            die();
        }
    }

    protected function removeTemporaryFolder(array $config, SymfonyStyle $io): void
    {
        $imgOptFolder = $this->getContainer()->getParameter('kernel.project_dir').'/var/imgOpt';

        if (file_exists($imgOptFolder)) {
            $this->runSubTask($io, 'Removed temporary folder.', sprintf('rm -rf %s', $imgOptFolder));
        }
    }

    protected function getFilesFromRemote(array $config, SymfonyStyle $io): void
    {
        $imgOptFolder = $this->getContainer()->getParameter('kernel.project_dir').'/var/imgOpt';

        if (file_exists($config['tmp'])) {
            $this->runSubTask($io, 'Removed previous folder.', sprintf('rm -rf %s', $config['tmp']));
        }

        if (!file_exists($imgOptFolder)) {
            $this->runSubTask($io, 'Created temporary folder.', sprintf('mkdir  %s', $imgOptFolder));
        }

        $this->runSubTask(
            $io,
            'Remote files have been downloaded and are ready to be optimized!',
            sprintf(
                'scp -r %s@%s:%s/shared/files %s',
                $config['user'],
                $config['host'],
                $config['directory'],
                $config['tmp']
            )
        );
    }

    protected function createRemoteBackup(array $config, SymfonyStyle $io): void
    {
        $timestamp = time();

        $this->runSubTask(
            $io,
            'Remote backup has been created.',
            sprintf(
                "ssh %s@%s 'cp -r %s/shared/files %s/shared/backup_%s'",
                $config['user'],
                $config['host'],
                $config['directory'],
                $config['directory'],
                $timestamp
            )
        );
    }

    protected function moveNewFilesToRemote($config, $io): void
    {
        $this->runSubTask(
            $io,
            'Files have been uploaded.',
            sprintf(
                'scp -r %s %s@%s:%s/shared',
                $config['tmp'],
                $config['user'],
                $config['host'],
                $config['directory']
            )
        );
    }

    protected function optimizeImages(SymfonyStyle $io): void
    {
        $root = $this->getContainer()->getParameter('kernel.project_dir').'/var/imgOpt/files';

        $dirIter = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterIter = new RecursiveIteratorIterator($dirIter, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);

        $paths = [$root];
        foreach ($iterIter as $path => $dir) {
            if ($dir->isDir()) {
                $paths[] = $path;
            }
        }

        $io->note($paths);

        $configOpt = $this->getContainer()->getParameter('developer_convenience.imageoptim.config');
        $jpegOpt = $configOpt['imageoptim']['jpeg'];
        $pngOpt = $configOpt['imageoptim']['png'];

        foreach ($paths as $pathItem) {
            $this->runSubTask(
                $io,
                'Optimize JPEG & PNG images in directory "'.$pathItem.'"',
                sprintf(
                    'node %s/../Resources/dev/app.js "%s" %s %s %s',
                    __DIR__,
                    $pathItem,
                    $jpegOpt['quality'],
                    $pngOpt['quality'],
                    $pngOpt['speed']
                )
            );
        }
    }

    protected function resyncFilesOnRemote(array $config, SymfonyStyle $io): void
    {
        $this->runSubTask(
            $io,
            'Remote filesync invoked.',
            sprintf(
                "ssh %s@%s 'cd %s/current/; %s contao:filesync'",
                $config['user'],
                $config['host'],
                $config['directory'],
                $config['console']
            )
        );
    }

    private function getConfigurationForEnvironment(string $environment)
    {
        $projectDir = $this->getContainer()->getParameter('kernel.project_dir');
        $syncDir = sprintf('%s/var/imgOpt/files', $projectDir);

        $file = sprintf('%s/.mage.yml', $projectDir);
        $config = Yaml::parse(file_get_contents($file));

        if (!array_key_exists($environment, $config['magephp']['environments'])) {
            throw new LogicException('Environment does not exist');
        }

        $envConfig = $config['magephp']['environments'][$environment];
        $console = $config['magephp']['symfony']['console'];

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

                if ('custom/copy-parameters' !== $commandName) {
                    continue;
                }

                if (\is_array($command) && \is_array($command['custom/copy-parameters']) && array_key_exists('env', $command['custom/copy-parameters'])) {
                    $parametersEnv = $command['custom/copy-parameters']['env'];
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
            'console' => $console,
        ];
    }

    private function runSubTask(SymfonyStyle $io, string $text, string $task): void
    {
        $process = new Process($task);
        $process->run();

        $success = $process->isSuccessful();
        $mask = sprintf('<fg=%s>%s</>', $success ? 'green' : 'red', $success ? '✔' : '✗');

        $io->text(sprintf('%s %s', $mask, $text));

        if (!$success) {
            throw new ProcessFailedException($process);
        }
    }
}
