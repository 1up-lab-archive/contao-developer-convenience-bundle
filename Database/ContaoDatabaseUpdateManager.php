<?php

declare(strict_types=1);

namespace Oneup\DeveloperConvenienceBundle\Database;

use Contao\CoreBundle\Migration\AbstractMigration;

class ContaoDatabaseUpdateManager
{
    protected $migrations;

    public function __construct()
    {
        $this->migrations = [];
    }

    public function addMigration(AbstractMigration $migration): void
    {
        $this->migrations[] = $migration;
    }

    public function runMigrations(): array
    {
        $results = [];

        /** @var AbstractMigration $migration */
        foreach ($this->migrations as $migration) {
            if ($migration->shouldRun()) {
                $results[] = $migration->run();
            }
        }

        return $results;
    }
}
