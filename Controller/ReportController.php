<?php

declare(strict_types=1);

/*
 * This file is part of the "AbsencesBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\AbsencesBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use KimaiPlugin\AbsencesBundle\Model\AbsenceType;
use KimaiPlugin\AbsencesBundle\Model\Fmt;
use KimaiPlugin\AbsencesBundle\Service\AbsenceStore;
use KimaiPlugin\AbsencesBundle\Service\AbsenceSummary;
use KimaiPlugin\AbsencesBundle\Service\TeamScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Absences report: absences per person and type for a year or a date
 * range, with the vacation balance. Team leads see the people they lead,
 * admins everyone (TeamScope).
 *
 * @phpstan-import-type DescribedRow from AbsenceSummary
 */
#[Route(path: '/reporting/absences')]
#[IsGranted('view_reporting')]
#[IsGranted('absences_team')]
final class ReportController extends AbstractController
{
    public function __construct(
        private readonly AbsenceStore $store,
        private readonly TeamScope $scope,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'absences_report', methods: ['GET'])]
    public function report(Request $request): Response
    {
        $me = $this->currentUser();
        $people = $this->scope->people($me);
        $filter = $this->filter($request, $me, $people);
        $rows = $this->rows($filter, $people);

        $byPerson = [];
        foreach ($rows as $row) {
            $byPerson[$row['user_id']][] = $row;
        }
        $summary = [];
        foreach ($filter['users'] !== [] ? $filter['users'] : array_keys($people) as $id) {
            if (!isset($people[$id]) || (!isset($byPerson[$id]) && $filter['users'] === [])) {
                continue;
            }
            $personRows = $byPerson[$id] ?? [];
            $totalDays = array_sum(array_column($personRows, 'days_value'));
            $summary[] = [
                'person' => $people[$id],
                'by_type' => AbsenceSummary::byType($personRows),
                'total_days' => Fmt::days($totalDays),
                'total_hours' => Fmt::hours(array_sum(array_column($personRows, 'hours_value'))),
                'balance' => $filter['whole_year'] ? AbsenceSummary::vacationBalance($people[$id], $personRows) : null,
            ];
        }

        $holidays = $this->store->holidaysBetween($filter['from'], $filter['to']);

        return $this->render('@Absences/report.html.twig', [
            'report_title' => 'absences.report.title',
            'filter' => $filter,
            'people' => $people,
            'types' => AbsenceType::LABELS,
            'summary' => $summary,
            'totals' => AbsenceSummary::byType($rows),
            'rows' => $rows,
            'holiday_count' => \count($holidays),
            'query' => $this->query($filter),
        ]);
    }

    #[Route(path: '/export', name: 'absences_report_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $me = $this->currentUser();
        $people = $this->scope->people($me);
        $filter = $this->filter($request, $me, $people);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open a temporary stream for the CSV export.');
        }
        $t = fn (string $key): string => $this->translator->trans($key);
        fputcsv($handle, [
            $t('absences.person'), $t('date'), $t('type'), $t('absences.full_day'),
            $t('hours'), $t('absences.days'), $t('comment'), $t('absences.csv.source'),
        ], escape: '');
        foreach ($this->rows($filter, $people) as $row) {
            fputcsv($handle, [
                $row['person'], $row['date'], $t($row['type_label']), $t($row['full_day'] ? 'absences.csv.yes' : 'absences.csv.no'),
                Fmt::plain($row['hours_value']), Fmt::plain($row['days_value']), (string) $row['comment'], $t($row['source_label']),
            ], escape: '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => \sprintf('attachment; filename="absences_%s_%s.csv"', $filter['from']->format('Y-m-d'), $filter['to']->format('Y-m-d')),
        ]);
    }

    /**
     * The 'error' entry is a translation key (flashmessages domain).
     *
     * @param array<int, User> $people
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, year: int, whole_year: bool, users: list<int>, types: list<string>, error: string|null}
     */
    private function filter(Request $request, User $me, array $people): array
    {
        $tz = new \DateTimeZone($me->getTimezone());
        $year = $request->query->getInt('year');
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) (new \DateTimeImmutable('now', $tz))->format('Y');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('from', ''), $tz);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('to', ''), $tz);
        $error = null;
        $wholeYear = true;

        if ($from !== false && $to !== false) {
            if ($from > $to) {
                $error = 'absences.error.range_order';
            } else {
                $wholeYear = false;
            }
        }
        if ($wholeYear) {
            $from = new \DateTimeImmutable($year . '-01-01', $tz);
            $to = new \DateTimeImmutable($year . '-12-31', $tz);
        }

        $query = $request->query->all();
        // Posted ids are intersected with the scope: a team lead cannot ask for other people.
        $users = array_values(array_filter(array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, (array) ($query['users'] ?? [])), static fn (int $id): bool => isset($people[$id])));
        $types = array_values(array_filter((array) ($query['types'] ?? []), static fn ($t): bool => \is_string($t) && AbsenceType::isValid($t)));

        return ['from' => $from, 'to' => $to, 'year' => $year, 'whole_year' => $wholeYear, 'users' => $users, 'types' => $types, 'error' => $error];
    }

    /**
     * @param array{from: \DateTimeImmutable, to: \DateTimeImmutable, year: int, whole_year: bool, users: list<int>, types: list<string>, error: string|null} $filter
     * @param array<int, User> $people
     * @return list<DescribedRow>
     */
    private function rows(array $filter, array $people): array
    {
        $ids = $filter['users'] !== [] ? $filter['users'] : array_keys($people);
        $rows = [];
        foreach ($this->store->absencesBetween($filter['from'], $filter['to'], $ids, $filter['types']) as $row) {
            $rows[] = AbsenceSummary::describe($row, $people[$row['user_id']]);
        }
        usort($rows, static fn (array $a, array $b): int => [$a['person'], $a['date']] <=> [$b['person'], $b['date']]);

        return $rows;
    }

    /**
     * @param array{from: \DateTimeImmutable, to: \DateTimeImmutable, year: int, whole_year: bool, users: list<int>, types: list<string>, error: string|null} $filter
     * @return array<string, mixed>
     */
    private function query(array $filter): array
    {
        $query = $filter['whole_year']
            ? ['year' => $filter['year']]
            : ['from' => $filter['from']->format('Y-m-d'), 'to' => $filter['to']->format('Y-m-d')];

        return $query + ['users' => $filter['users'], 'types' => $filter['types']];
    }

    private function currentUser(): User
    {
        // Kimai's AbstractController::getUser() already denies anonymous access.
        return $this->getUser();
    }
}
