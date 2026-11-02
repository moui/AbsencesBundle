<?php

declare(strict_types=1);

/*
 * This file is part of the "AbsencesBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\AbsencesBundle\Widget;

use App\Widget\Type\AbstractWidget;
use App\Widget\WidgetInterface;
use KimaiPlugin\AbsencesBundle\Model\AbsenceType;
use KimaiPlugin\AbsencesBundle\Service\AbsenceStore;
use KimaiPlugin\AbsencesBundle\Service\AbsenceSummary;

/**
 * "My leave": vacation days left this year (same calculation as the
 * "My absences" page) and the next company holiday. Every user, own data only.
 */
final class AbsencesWidget extends AbstractWidget
{
    public const ID = 'AbsencesWidget';

    public function __construct(private readonly AbsenceStore $store)
    {
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function getTitle(): string
    {
        return 'absences.widget.title';
    }

    public function getWidth(): int
    {
        return WidgetInterface::WIDTH_SMALL;
    }

    public function getHeight(): int
    {
        return WidgetInterface::HEIGHT_MEDIUM;
    }

    public function getTemplateName(): string
    {
        return '@Absences/widget.html.twig';
    }

    public function getPermissions(): array
    {
        return ['absences_own'];
    }

    /**
     * @param array<string, string|bool|int|null|array<string, mixed>> $options
     * @return array<string, mixed>
     */
    public function getData(array $options = []): mixed
    {
        $user = $this->getUser();
        $today = new \DateTimeImmutable('today', $this->getTimezone());
        $year = $today->format('Y');

        $vacations = array_map(
            static fn (array $row): array => AbsenceSummary::describe($row, $user),
            $this->store->absencesBetween(new \DateTimeImmutable($year . '-01-01'), new \DateTimeImmutable($year . '-12-31'), [(int) $user->getId()], [AbsenceType::VACATION])
        );

        $next = null;
        foreach ($this->store->holidaysBetween($today, $today->modify('+2 years')) as $date => $holiday) {
            $next = $holiday + ['day' => new \DateTimeImmutable($date), 'is_today' => $date === $today->format('Y-m-d')];
            break;
        }

        return [
            'year' => $year,
            'balance' => AbsenceSummary::vacationBalance($user, $vacations),
            'next_holiday' => $next,
        ];
    }
}
