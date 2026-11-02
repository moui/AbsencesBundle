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

use App\Configuration\LocaleService;
use App\Controller\AbstractController;
use App\Entity\User;
use App\Utils\LocaleFormatter;
use App\Utils\PageSetup;
use KimaiPlugin\AbsencesBundle\Model\AbsenceType;
use KimaiPlugin\AbsencesBundle\Model\Fmt;
use KimaiPlugin\AbsencesBundle\Service\AbsenceStore;
use KimaiPlugin\AbsencesBundle\Service\AbsenceSummary;
use KimaiPlugin\AbsencesBundle\Service\ContractHours;
use KimaiPlugin\AbsencesBundle\Service\TeamScope;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "My absences" and "Team absences". Everyone records their own
 * absences without approval; team leads and admins can record and correct
 * them for the people in their scope (TeamScope).
 */
#[Route(path: '/absences')]
#[IsGranted('absences_own')]
final class AbsencesController extends AbstractController
{
    public function __construct(
        private readonly AbsenceStore $store,
        private readonly TeamScope $scope,
        private readonly TranslatorInterface $translator,
        private readonly LocaleService $localeService,
    ) {
    }

    #[Route(path: '', name: 'absences_mine', methods: ['GET'])]
    public function mine(Request $request): Response
    {
        $me = $this->currentUser();
        $year = $this->year($request, $me);

        $page = new PageSetup('absences.mine.title');
        $page->setActionName('absences_mine');

        return $this->render('@Absences/mine.html.twig', [
            'page_setup' => $page,
            'year' => $year,
            'target' => $me,
            'back' => 'mine',
        ] + $this->personData($me, $year));
    }

    #[Route(path: '/team', name: 'absences_team', methods: ['GET'])]
    #[IsGranted('absences_team')]
    public function team(Request $request): Response
    {
        $me = $this->currentUser();
        $people = $this->scope->people($me);
        $year = $this->year($request, $me);
        $first = reset($people);
        $target = $people[$request->query->getInt('user')] ?? ($first === false ? null : $first);

        $tz = new \DateTimeZone($me->getTimezone());
        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->query->get('month', ''), $tz);
        if ($month === false) {
            $month = new \DateTimeImmutable('first day of this month', $tz);
        }
        $month = $month->setTime(0, 0)->modify('first day of this month');

        $page = new PageSetup('absences.team.title');
        $page->setActionName('absences_team');

        return $this->render('@Absences/team.html.twig', [
            'page_setup' => $page,
            'year' => $year,
            'target' => $target,
            'people' => $people,
            'back' => 'team',
            'grid' => $this->grid($people, $month),
            'month' => $month->format('Y-m'),
            'month_date' => $month,
            'prev_month' => $month->modify('-1 month')->format('Y-m'),
            'next_month' => $month->modify('+1 month')->format('Y-m'),
        ] + ($target !== null ? $this->personData($target, $year) : []));
    }

    #[Route(path: '/save', name: 'absences_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $me = $this->currentUser();
        $redirect = $this->backTo($request);

        if (!$this->isCsrfTokenValid('absences_save', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $redirect;
        }

        $target = $this->resolveTarget($me, $request->request->getInt('user', (int) $me->getId()));
        if ($target === null) {
            $this->flashError('absences.error.target_not_allowed');

            return $redirect;
        }

        $tz = new \DateTimeZone($target->getTimezone());
        $from = $this->parseDate((string) $request->request->get('from'), $tz);
        $toRaw = trim((string) $request->request->get('to', ''));
        $to = $toRaw === '' ? $from : $this->parseDate($toRaw, $tz);
        $type = (string) $request->request->get('type');
        $partial = $request->request->get('mode') === 'hours';
        $hours = null;

        if ($from === null || $to === null) {
            $this->flashError('absences.error.invalid_dates');

            return $redirect;
        }
        if ($partial) {
            if ($from->format('Y-m-d') !== $to->format('Y-m-d')) {
                $this->flashError('absences.error.hours_single_day');

                return $redirect;
            }
            $hours = (float) str_replace(',', '.', (string) $request->request->get('hours'));
        }

        try {
            $result = $this->store->saveRange($target, $type, $from, $to, $hours, (string) $request->request->get('comment', ''), $me);
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());

            return $redirect;
        }

        $formatter = new LocaleFormatter($this->localeService, $request->getLocale());
        if (\count($result['saved']) > 0) {
            $this->flashSuccess($this->translator->trans('absences.flash.saved', [
                '%type%' => $this->translator->trans(AbsenceType::label($type)),
                '%name%' => $target->getDisplayName(),
                '%dates%' => implode(', ', array_map(static fn (string $day): string => (string) $formatter->dateShort(new \DateTimeImmutable($day)), $result['saved'])),
            ], 'flashmessages'));
        }
        if (\count($result['skipped']) > 0) {
            $this->flashWarning($this->translator->trans('absences.flash.skipped', [
                '%days%' => implode('; ', array_map(
                    fn (string $day, array $reason): string => $formatter->dateShort(new \DateTimeImmutable($day)) . ' (' . $this->translator->trans($reason[0], $reason[1], 'flashmessages') . ')',
                    array_keys($result['skipped']),
                    array_values($result['skipped'])
                )),
            ], 'flashmessages'));
        }

        return $redirect;
    }

    #[Route(path: '/{id}/delete', name: 'absences_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $me = $this->currentUser();
        $redirect = $this->backTo($request);

        if (!$this->isCsrfTokenValid('absences_delete', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $redirect;
        }

        $absence = $this->store->find($id);
        if ($absence === null || $this->resolveTarget($me, $absence['user_id']) === null) {
            $this->flashError('absences.error.delete_not_allowed');

            return $redirect;
        }

        $this->store->delete($id);
        $formatter = new LocaleFormatter($this->localeService, $request->getLocale());
        $this->flashSuccess($this->translator->trans('absences.flash.deleted', ['%date%' => $formatter->dateShort(new \DateTimeImmutable($absence['date']))], 'flashmessages'));

        return $redirect;
    }

    /**
     * The person whose absences are being changed: oneself, or someone in the
     * viewer's team scope. Re-checked on every POST, never trusted from the form.
     */
    private function resolveTarget(User $me, int $userId): ?User
    {
        if ($userId === (int) $me->getId()) {
            return $me;
        }
        if (!$this->isGranted('absences_team')) {
            return null;
        }

        return $this->scope->people($me)[$userId] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function personData(User $person, int $year): array
    {
        $tz = new \DateTimeZone($person->getTimezone());
        $first = new \DateTimeImmutable($year . '-01-01', $tz);
        $last = new \DateTimeImmutable($year . '-12-31', $tz);

        $rows = array_map(
            static fn (array $row): array => AbsenceSummary::describe($row, $person),
            $this->store->absencesBetween($first, $last, [(int) $person->getId()])
        );
        usort($rows, static fn (array $a, array $b): int => [$b['date'], $a['type']] <=> [$a['date'], $b['type']]);

        $today = (new \DateTimeImmutable('today', $tz))->format('Y-m-d');
        $holidays = [];
        foreach ($this->store->holidaysBetween($first, $last) as $date => $holiday) {
            $holidays[] = [
                'day' => new \DateTimeImmutable($date),
                'name' => $holiday['name'],
                'half_day' => $holiday['half_day'],
                'past' => $date < $today,
            ];
        }

        return [
            'rows' => $rows,
            'by_type' => AbsenceSummary::byType($rows),
            'balance' => AbsenceSummary::vacationBalance($person, $rows),
            'holidays' => $holidays,
            'types' => AbsenceType::LABELS,
            'today' => $today,
        ];
    }

    /**
     * Month overview: one row per person, one cell per day.
     *
     * @param array<int, User> $people
     * @return array<string, mixed>
     */
    private function grid(array $people, \DateTimeImmutable $month): array
    {
        $last = $month->modify('last day of this month');
        $holidays = $this->store->holidaysBetween($month, $last);
        $byUser = [];
        foreach ($this->store->absencesBetween($month, $last, array_keys($people)) as $row) {
            $byUser[$row['user_id']][$row['date']][] = $row;
        }

        $days = [];
        for ($day = $month; $day <= $last; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $days[] = [
                'key' => $key,
                'number' => $day->format('j'),
                'date' => $day,
                'weekend' => (int) $day->format('N') >= 6,
                'holiday' => $holidays[$key] ?? null,
            ];
        }

        $rows = [];
        foreach ($people as $id => $person) {
            $cells = [];
            foreach ($days as $day) {
                $cell = ['class' => '', 'text' => '', 'title' => ''];
                if ($day['holiday'] !== null) {
                    $cell = ['class' => 'bg-public-holiday', 'text' => '', 'title' => $this->translator->trans($day['holiday']['half_day'] ? 'absences.holiday.addon_half_day' : 'absences.holiday.addon', ['%name%' => $day['holiday']['name']])];
                } elseif ($day['weekend'] || ContractHours::secondsFor($person, new \DateTimeImmutable($day['key'])) === 0) {
                    $cell['class'] = 'bg-weekend';
                }
                foreach ($byUser[$id][$day['key']] ?? [] as $absence) {
                    $cell['class'] = 'bg-' . AbsenceType::style($absence['type']);
                    $cell['text'] = $absence['full_day'] ? $this->translator->trans(AbsenceType::INITIALS[$absence['type']] ?? AbsenceType::INITIALS[AbsenceType::OTHER]) : rtrim(rtrim(number_format((float) $absence['hours'], 2, '.', ''), '0'), '.') . 'h';
                    $cell['title'] = trim($cell['title'] . ' ' . $this->translator->trans(AbsenceType::label($absence['type'])) . ($absence['full_day'] ? '' : ' (' . Fmt::hours($absence['hours']) . ')') . ($absence['comment'] !== null && $absence['comment'] !== '' ? ': ' . $absence['comment'] : ''));
                }
                $cells[] = $cell;
            }
            $rows[] = ['person' => $person, 'cells' => $cells];
        }

        return ['days' => $days, 'rows' => $rows];
    }

    private function backTo(Request $request): RedirectResponse
    {
        $year = $request->request->getInt('year');
        $user = $request->request->getInt('user');
        $month = (string) $request->request->get('month');
        $params = array_filter([
            'year' => $year !== 0 ? $year : null,
            'user' => $user !== 0 ? $user : null,
            'month' => preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : null,
        ]);

        if ($request->request->get('back') === 'team' && $this->isGranted('absences_team')) {
            return $this->redirectToRoute('absences_team', $params);
        }
        unset($params['user'], $params['month']);

        return $this->redirectToRoute('absences_mine', $params);
    }

    private function year(Request $request, User $user): int
    {
        $year = $request->query->getInt('year');

        return $year >= 2000 && $year <= 2100 ? $year : (int) (new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())))->format('Y');
    }

    private function parseDate(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $tz);

        return $date === false || $date->format('Y-m-d') !== trim($value) ? null : $date;
    }

    private function currentUser(): User
    {
        // Kimai's AbstractController::getUser() already denies anonymous access.
        return $this->getUser();
    }
}
