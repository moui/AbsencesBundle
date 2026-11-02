<?php

declare(strict_types=1);

/*
 * This file is part of the "AbsencesBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\AbsencesBundle\Command;

use App\Command\AbstractBundleInstallerCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `bin/console kimai:bundle:absences:install` — runs this bundle's migrations
 * against its own version table. Safe to re-run.
 */
#[AsCommand(name: 'kimai:bundle:absences:install')]
final class InstallCommand extends AbstractBundleInstallerCommand
{
    protected function getBundleCommandNamePart(): string
    {
        return 'absences';
    }

    protected function getMigrationConfigFilename(): string
    {
        return __DIR__ . '/../Resources/config/doctrine_migrations.yaml';
    }
}
