<?php

declare(strict_types=1);

/*
 * This file is part of the "AbsencesBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\AbsencesBundle\DependencyInjection;

use App\Plugin\AbstractPluginExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class AbsencesExtension extends AbstractPluginExtension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    /**
     * absences_own   — everyone records their own absences (no approval).
     * absences_team  — team leads manage their team members' absences and see
     *                  the absences report for them; admins see everyone.
     * absences_admin — the company holiday calendar and yearly vacation days.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_USER' => ['absences_own'],
                    'ROLE_TEAMLEAD' => ['absences_own', 'absences_team'],
                    'ROLE_ADMIN' => ['absences_own', 'absences_team', 'absences_admin'],
                    'ROLE_SUPER_ADMIN' => ['absences_own', 'absences_team', 'absences_admin'],
                ],
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'absences';
    }
}
