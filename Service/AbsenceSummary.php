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
use KimaiPlugin\AbsencesBundle\Model\AbsenceType;
use KimaiPlugin\AbsencesBundle\Model\Fmt;

/**
 * Turns absence rows into display rows and per-person totals.
 *
 * A full day counts as 1 day and as the person's contract hours for that
 * weekday; a partial absence counts its hours and hours / contract hours of
 * that weekday as days (0 when the person does not work that weekday).
 *
 * @phpstan-import-type AbsenceRow from AbsenceStore
 * @phpstan-type DescribedRow array{id: int, user_id: int, date: string, type: string, full_day: bool, hours: float|null, comment: string|null, source: string, person: string, type_label: string, style: string, day: \DateTimeImmutable, amount: string|null, hours_value: float, days_value: float, source_label: string}
 */
final class AbsenceSummary
{
    /**
     * @param AbsenceRow $row
     * @return DescribedRow
     */
    public static function describe(array $row, User $user): array
    {
        $day = new \DateTimeImmutable($row['date']);
        $contract = ContractHours::hoursFor($user, $day);

        if ($row['full_day']) {
            $hours = $contract;
            $days = 1.0;
        } else {
            $hours = (float) $row['hours'];
            $days = $contract > 0 ? round($hours / $contract, 4) : 0.0;
        }

        // Labels are translation keys (messages domain); dates are formatted in
        // Twig from 'day'. 'amount' is null for a full day.
        return $row + [
            'person' => $user->getDisplayName(),
            'type_label' => AbsenceType::label($row['type']),
            'style' => AbsenceType::style($row['type']),
            'day' => $day,
            'amount' => $row['full_day'] ? null : Fmt::hours($hours),
            'hours_value' => $hours,
            'days_value' => $days,
            'source_label' => $row['source'] === 'import' ? 'absences.source.import' : 'absences.source.manual',
        ];
    }

    /**
     * Totals per type for a list of described rows ('label' is a translation key).
     *
     * @param list<DescribedRow> $described
     * @return array<string, array{label: string, style: string, entries: int, days: float, hours: float, days_formatted: string, hours_formatted: string}>
     */
    public static function byType(array $described): array
    {
        $totals = [];
        foreach (AbsenceType::LABELS as $type => $label) {
            $totals[$type] = ['label' => $label, 'style' => AbsenceType::style($type), 'entries' => 0, 'days' => 0.0, 'hours' => 0.0];
        }
        foreach ($described as $row) {
            if (!isset($totals[$row['type']])) {
                continue;
            }
            $totals[$row['type']]['entries']++;
            $totals[$row['type']]['days'] += $row['days_value'];
            $totals[$row['type']]['hours'] += $row['hours_value'];
        }
        foreach ($totals as $type => $total) {
            $totals[$type]['days_formatted'] = Fmt::days($total['days']);
            $totals[$type]['hours_formatted'] = Fmt::hours($total['hours']);
        }

        return $totals;
    }

    /**
     * Vacation balance: yearly entitlement (Kimai "holidays" preference)
     * against vacation days used in the given rows.
     *
     * @param list<DescribedRow> $described
     * @return array{entitled: string, used: string, remaining: string, has_entitlement: bool, over: bool}
     */
    public static function vacationBalance(User $user, array $described): array
    {
        $entitled = AbsenceStore::vacationDays($user);
        $used = 0.0;
        foreach ($described as $row) {
            if ($row['type'] === AbsenceType::VACATION) {
                $used += $row['days_value'];
            }
        }
        $remaining = $entitled === null ? null : $entitled - $used;

        return [
            'entitled' => $entitled === null ? '—' : Fmt::days($entitled),
            'used' => Fmt::days($used),
            'remaining' => $remaining === null ? '—' : Fmt::days($remaining),
            'has_entitlement' => $entitled !== null,
            'over' => $remaining !== null && $remaining < 0,
        ];
    }
}
