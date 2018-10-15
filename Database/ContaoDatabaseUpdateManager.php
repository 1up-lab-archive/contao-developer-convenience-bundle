<?php

declare(strict_types=1);

namespace Oneup\DeveloperConvenienceBundle\Database;

use Contao\InstallationBundle\Database\AbstractVersionUpdate;

class ContaoDatabaseUpdateManager
{
    protected $updates;
    protected $messages;

    public function __construct()
    {
        $this->updates = [];
        $this->messages = [];
    }

    public function addUpdate(AbstractVersionUpdate $update): void
    {
        $this->updates[] = $update;
    }

    public function runUpdates(): void
    {
        /** @var AbstractVersionUpdate $update */
        foreach ($this->updates as $update) {
            if ($update->shouldBeRun()) {
                $update->run();
            }

            if ($message = $update->getMessage()) {
                $messages[] = $message;
            }
        }
    }

    public function getMessages(): string
    {
        return implode('', $this->messages);
    }
}
