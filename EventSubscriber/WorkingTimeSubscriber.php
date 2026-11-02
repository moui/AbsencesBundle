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

use App\Event\WorkingTimeYearEvent;
use App\WorkingTime\Model\DayAddon;
use KimaiPlugin\AbsencesBundle\Model\AbsenceType;
use KimaiPlugin\AbsencesBundle\Service\AbsenceStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Feeds holidays and absences into Kimai's working times — the numbers behind
 * the "Working times" page, month locking and any plugin that reads working
 * times — the way Kimai's own contract plugin does it:
 *
 * - a company holiday empties (or halves) that day's expected time;
 * - an absence is a day "addon" credited to actual time: the whole remaining
 *   expected time for a full day, or its hours for a partial one, never more
 *   than what was still expected that day.
 *
 * Locked (approved) days keep their stored snapshot and are not changed.
 */
final class WorkingTimeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AbsenceStore $store,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkingTimeYearEvent::class => ['onYear', 100],
        ];
    }

    public function onYear(WorkingTimeYearEvent $event): void
    {
        $year = $event->getYear();
        $user = $year->getUser();
        $first = \DateTimeImmutable::createFromInterface($year->getYear())->modify('first day of january this year');
        $last = $first->modify('last day of december this year');

        $holidays = $this->store->holidaysBetween($first, $last);
        $absences = [];
        foreach ($this->store->absencesBetween($first, $last, [(int) $user->getId()]) as $absence) {
            $absences[$absence['date']][] = $absence;
        }
        if ($holidays === [] && $absences === []) {
            return;
        }

        foreach ($year->getMonths() as $month) {
            foreach ($month->getDays() as $day) {
                $key = $day->getDay()->format('Y-m-d');
                $workingTime = $day->getWorkingTime();
                if ($workingTime === null || (!isset($holidays[$key]) && !isset($absences[$key]))) {
                    continue;
                }

                $locked = $day->isLocked();

                if (isset($holidays[$key])) {
                    $holiday = $holidays[$key];
                    if (!$locked) {
                        $holiday['half_day'] ? $workingTime->halveExpectedTime() : $workingTime->emptyExpectedTime();
                    }
                    $day->addAddon(new DayAddon(
                        $this->translator->trans($holiday['half_day'] ? 'absences.holiday.addon_half_day' : 'absences.holiday.addon', ['%name%' => $holiday['name']]),
                        0,
                        0,
                        AbsenceType::HOLIDAY_STYLE
                    ));
                }

                // Full days first would swallow partial ones; credit partial
                // absences first, then let a full day take what is left.
                $rows = $absences[$key] ?? [];
                usort($rows, static fn (array $a, array $b): int => $a['full_day'] <=> $b['full_day']);
                $remaining = $locked ? 0 : max(0, $workingTime->getExpectedTime());

                foreach ($rows as $absence) {
                    $wanted = $absence['full_day'] ? $remaining : (int) round(($absence['hours'] ?? 0) * 3600);
                    $credit = min($wanted, $remaining);
                    $remaining -= $credit;
                    $visible = $absence['full_day'] ? $credit : (int) round(($absence['hours'] ?? 0) * 3600);

                    // On locked days DayAddon does not touch actual time; it
                    // only shows up as a label. The title is a translation key;
                    // Kimai's Working times page translates addon titles.
                    $day->addAddon(new DayAddon(AbsenceType::label($absence['type']), $credit, $visible, AbsenceType::style($absence['type'])));
                }
            }
        }
    }
}
