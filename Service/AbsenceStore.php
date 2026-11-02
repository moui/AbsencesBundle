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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use KimaiPlugin\AbsencesBundle\Model\AbsenceType;

/**
 * All reads and writes of the two plugin tables (parameterized DBAL).
 *
 * Absence rows are one per person, day and type. hours is null for a full
 * day, otherwise the partial duration in decimal hours.
 *
 * @phpstan-type AbsenceRow array{id: int, user_id: int, date: string, type: string, full_day: bool, hours: float|null, comment: string|null, source: string}
 */
final class AbsenceStore
{
    /** @var array<string, array<string, array{id: int, name: string, half_day: bool}>> */
    private array $holidayCache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    // ------------------------------------------------------------------ holidays

    /**
     * @return array<string, array{id: int, name: string, half_day: bool}> keyed by Y-m-d
     */
    public function holidaysBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $key = $from->format('Y-m-d') . '_' . $to->format('Y-m-d');
        if (isset($this->holidayCache[$key])) {
            return $this->holidayCache[$key];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, date, name, half_day FROM kimai2_absences_holidays WHERE date BETWEEN :from AND :to ORDER BY date',
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );

        $holidays = [];
        foreach ($rows as $row) {
            $holidays[self::str($row['date'])] = ['id' => self::int($row['id']), 'name' => self::str($row['name']), 'half_day' => (bool) $row['half_day']];
        }

        return $this->holidayCache[$key] = $holidays;
    }

    /**
     * Adds or updates the holiday on that date.
     */
    public function saveHoliday(\DateTimeInterface $date, string $name, bool $halfDay): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO kimai2_absences_holidays (date, name, half_day, created_at)
                VALUES (:date, :name, :half, NOW())
                ON DUPLICATE KEY UPDATE name = VALUES(name), half_day = VALUES(half_day)
                SQL,
            ['date' => $date->format('Y-m-d'), 'name' => $name, 'half' => $halfDay ? 1 : 0]
        );
        $this->holidayCache = [];
    }

    public function deleteHoliday(int $id): void
    {
        $this->connection->executeStatement('DELETE FROM kimai2_absences_holidays WHERE id = :id', ['id' => $id]);
        $this->holidayCache = [];
    }

    // ------------------------------------------------------------------ absences

    /**
     * @param list<int>|null $userIds null = everyone
     * @param list<string> $types empty = all
     * @return list<AbsenceRow>
     */
    public function absencesBetween(\DateTimeInterface $from, \DateTimeInterface $to, ?array $userIds = null, array $types = []): array
    {
        if ($userIds === []) {
            return [];
        }

        $sql = 'SELECT id, user_id, date, type, full_day, hours, comment, source FROM kimai2_absences WHERE date BETWEEN :from AND :to';
        $params = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
        $types_ = [];
        if ($userIds !== null) {
            $sql .= ' AND user_id IN (:users)';
            $params['users'] = $userIds;
            $types_['users'] = ArrayParameterType::INTEGER;
        }
        if ($types !== []) {
            $sql .= ' AND type IN (:types)';
            $params['types'] = $types;
            $types_['types'] = ArrayParameterType::STRING;
        }
        $sql .= ' ORDER BY date, user_id, type';

        return array_map(self::absenceRow(...), $this->connection->fetchAllAssociative($sql, $params, $types_));
    }

    /**
     * @return AbsenceRow|null
     */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, user_id, date, type, full_day, hours, comment, source FROM kimai2_absences WHERE id = :id',
            ['id' => $id]
        );
        if ($row === false) {
            return null;
        }

        return self::absenceRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return AbsenceRow
     */
    private static function absenceRow(array $row): array
    {
        return [
            'id' => self::int($row['id']),
            'user_id' => self::int($row['user_id']),
            'date' => self::str($row['date']),
            'type' => self::str($row['type']),
            'full_day' => (bool) $row['full_day'],
            'hours' => $row['hours'] === null ? null : self::float($row['hours']),
            'comment' => $row['comment'] === null ? null : self::str($row['comment']),
            'source' => self::str($row['source']),
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Records an absence for a range of days, one row per working day.
     *
     * Days the person does not work (no contract hours that weekday) and
     * full-day company holidays are skipped. An existing row for the same
     * person, day and type is replaced, so re-entering a day corrects it.
     *
     * Validation errors are thrown as \InvalidArgumentException whose message is
     * a translation key (flashmessages domain). Skip reasons are a translation
     * key (flashmessages domain) plus its parameters.
     *
     * @return array{saved: list<string>, skipped: array<string, array{0: string, 1: array<string, string>}>} dates saved / skipped => reason
     */
    public function saveRange(User $user, string $type, \DateTimeImmutable $from, \DateTimeImmutable $to, ?float $hours, ?string $comment, ?User $actor, string $source = 'manual'): array
    {
        if (!AbsenceType::isValid($type)) {
            throw new \InvalidArgumentException('absences.error.invalid_type');
        }
        if ($from > $to) {
            throw new \InvalidArgumentException('absences.error.range_order');
        }
        if ($from->diff($to)->days > 366) {
            throw new \InvalidArgumentException('absences.error.range_too_long');
        }
        if ($hours !== null && ($hours <= 0 || $hours > 24)) {
            throw new \InvalidArgumentException('absences.error.hours_range');
        }

        $holidays = $this->holidaysBetween($from, $to);
        $saved = [];
        $skipped = [];

        $this->connection->beginTransaction();
        try {
            for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
                $key = $day->format('Y-m-d');
                if (ContractHours::secondsFor($user, $day) === 0) {
                    $skipped[$key] = ['absences.skipped.not_working', []];
                    continue;
                }
                if (isset($holidays[$key]) && !$holidays[$key]['half_day']) {
                    $skipped[$key] = ['absences.skipped.holiday', ['%name%' => $holidays[$key]['name']]];
                    continue;
                }

                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO kimai2_absences (user_id, date, type, full_day, hours, comment, source, created_by_id, created_at, updated_at)
                        VALUES (:user, :date, :type, :full, :hours, :comment, :source, :actor, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE full_day = VALUES(full_day), hours = VALUES(hours),
                            comment = VALUES(comment), updated_at = NOW()
                        SQL,
                    [
                        'user' => $user->getId(), 'date' => $key, 'type' => $type,
                        'full' => $hours === null ? 1 : 0, 'hours' => $hours === null ? null : round($hours, 2),
                        'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
                        'source' => $source, 'actor' => $actor?->getId(),
                    ]
                );
                $saved[] = $key;
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    public function delete(int $id): void
    {
        $this->connection->executeStatement('DELETE FROM kimai2_absences WHERE id = :id', ['id' => $id]);
    }

    // ------------------------------------------------------------------ entitlement

    /**
     * Yearly vacation days, stored in Kimai's own "holidays" user preference
     * (the "Vacation days" field of the user profile), or null when unset.
     * Kimai writes 0 for every new user, so 0 also means "not set".
     */
    public static function vacationDays(User $user): ?float
    {
        $value = $user->getPreferenceValue('holidays', null, false);

        return $value === null || $value === '' || (float) $value <= 0 ? null : (float) $value;
    }
}
