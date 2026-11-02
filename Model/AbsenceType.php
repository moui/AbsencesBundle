<?php

declare(strict_types=1);

/*
 * This file is part of the "AbsencesBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\AbsencesBundle\Model;

/**
 * Absence types. The style is the CSS/icon key Kimai's own Working times page
 * already understands (bg-holiday, bg-sickness, …), so absences show with the
 * familiar colour and icon there.
 */
final class AbsenceType
{
    public const VACATION = 'vacation';
    public const SICKNESS = 'sickness';
    public const STUDY = 'study';
    public const OTHER = 'other';

    /**
     * Translation keys (messages domain).
     */
    public const LABELS = [
        self::VACATION => 'absences.type.vacation',
        self::SICKNESS => 'absences.type.sickness',
        self::STUDY => 'absences.type.study',
        self::OTHER => 'absences.type.other',
    ];

    /**
     * Translation keys (messages domain) of the one- or two-letter codes shown
     * in the team month grid.
     */
    public const INITIALS = [
        self::VACATION => 'absences.type.vacation.initial',
        self::SICKNESS => 'absences.type.sickness.initial',
        self::STUDY => 'absences.type.study.initial',
        self::OTHER => 'absences.type.other.initial',
    ];

    private const STYLES = [
        self::VACATION => 'holiday',
        self::SICKNESS => 'sickness',
        self::STUDY => 'time-off',
        self::OTHER => 'other',
    ];

    public const HOLIDAY_STYLE = 'public-holiday';

    public static function isValid(string $type): bool
    {
        return \array_key_exists($type, self::LABELS);
    }

    /**
     * Translation key of the type's label, or the raw type when unknown.
     */
    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    public static function style(string $type): string
    {
        return self::STYLES[$type] ?? 'other';
    }
}
