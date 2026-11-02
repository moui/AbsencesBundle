<?php

declare(strict_types=1);

/*
 * This file is part of the "AbsencesBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\AbsencesBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConfigureMainMenuEvent::class => ['onMenuConfigure', 90],
        ];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        if (!$this->security->isGranted('absences_own')) {
            return;
        }

        $section = new MenuItemModel('absences_menu', 'absences.menu', null, [], 'holiday');
        $section->addChild(new MenuItemModel('absences_mine', 'absences.mine.title', 'absences_mine', [], 'holiday'));
        if ($this->security->isGranted('absences_team')) {
            $section->addChild(new MenuItemModel('absences_team', 'absences.team.title', 'absences_team', [], 'users'));
        }
        if ($this->security->isGranted('absences_admin')) {
            $section->addChild(new MenuItemModel('absences_holidays', 'absences.holidays.title', 'absences_holidays', [], 'public-holiday'));
            $section->addChild(new MenuItemModel('absences_entitlements', 'absences.entitlements.title', 'absences_entitlements', [], 'calendar'));
        }

        $event->getMenu()->addChild($section);
    }
}
