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
 * Number formatting for display, done in PHP on purpose: Twig macros imported
 * in a block are out of scope inside `{% embed %}` and fail silently. Dates,
 * weekdays and month names are formatted in Twig with Kimai's locale-aware
 * filters (date_short, day_name, month_name).
 */
final class Fmt
{
    public static function hours(?float $hours): string
    {
        return $hours === null ? '—' : number_format($hours, 2, '.', '') . ' h';
    }

    public static function days(?float $days): string
    {
        if ($days === null) {
            return '—';
        }
        $rounded = round($days, 2);

        return (floor($rounded) === $rounded ? (string) (int) $rounded : number_format($rounded, 2, '.', '')) . ' d';
    }

    /**
     * Plain number for CSV cells.
     */
    public static function plain(?float $value): string
    {
        return $value === null ? '' : number_format($value, 2, '.', '');
    }
}
