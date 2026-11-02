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

use App\Event\DashboardEvent;
use KimaiPlugin\AbsencesBundle\Widget\AbsencesWidget;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Puts the widget on the default dashboard. Customised dashboards get it
 * through `kimai:absences:add-widget --apply`.
 */
final class DashboardSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            DashboardEvent::class => ['onDashboard', 90],
        ];
    }

    public function onDashboard(DashboardEvent $event): void
    {
        $event->addWidget(AbsencesWidget::ID, 6);
    }
}
