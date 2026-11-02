<?php

declare(strict_types=1);

/*
 * This file is part of the "AbsencesBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\AbsencesBundle\Service;

use App\Entity\User;

/**
 * A person's contract hours for a weekday, from the work_monday … work_sunday
 * preferences (what Kimai's "day" contract mode uses). Used to tell working
 * days from days off and to turn partial absences into fractions of a day.
 */
final class ContractHours
{
    private const DAY_PREFERENCES = [
        1 => 'work_monday', 2 => 'work_tuesday', 3 => 'work_wednesday', 4 => 'work_thursday',
        5 => 'work_friday', 6 => 'work_saturday', 7 => 'work_sunday',
    ];

    public static function secondsFor(User $user, \DateTimeInterface $day): int
    {
        return (int) $user->getPreferenceValue(self::DAY_PREFERENCES[(int) $day->format('N')], 0, false);
    }

    public static function hoursFor(User $user, \DateTimeInterface $day): float
    {
        return round(self::secondsFor($user, $day) / 3600, 2);
    }
}
