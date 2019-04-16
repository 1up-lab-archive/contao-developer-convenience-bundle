<?php

declare(strict_types=1);

namespace Oneup\DeveloperConvenienceBundle\Database;

use Contao\InstallationBundle\Database\AbstractVersionUpdate;

class ContaoDatabaseUpdateManager
{
    protected $updates;

    public function __construct()
    {
        $this->updates = [];
    }

    public function addUpdate(AbstractVersionUpdate $update): void
    {
        $this->updates[] = $update;
    }

    public function runUpdates(): array
    {
        $messages = [];

        /** @var AbstractVersionUpdate $update */
        foreach ($this->updates as $update) {
            if ($update->shouldBeRun()) {
                $update->run();
            }

            if ($message = $update->getMessage()) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
