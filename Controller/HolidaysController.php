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
use KimaiPlugin\AbsencesBundle\Service\AbsenceStore;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Company holiday calendar. A holiday empties everyone's expected hours that
 * day (half-day: halves them); see WorkingTimeSubscriber.
 */
#[Route(path: '/absences/holidays')]
#[IsGranted('absences_admin')]
final class HolidaysController extends AbstractController
{
    public function __construct(
        private readonly AbsenceStore $store,
        private readonly TranslatorInterface $translator,
        private readonly LocaleService $localeService,
    ) {
    }

    #[Route(path: '', name: 'absences_holidays', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $year = $this->year($request);
        $tz = new \DateTimeZone($this->currentUser()->getTimezone());

        $page = new PageSetup('absences.holidays.title');
        $page->setActionName('absences_holidays');

        return $this->render('@Absences/holidays.html.twig', [
            'page_setup' => $page,
            'year' => $year,
            'holidays' => $this->list($year, $tz),
            // "Copy from previous year": last year's dates moved to this year,
            // only proposed — nothing is saved until the admin confirms.
            'proposals' => $request->query->get('copy') === '1' ? $this->proposals($year, $tz) : null,
        ]);
    }

    #[Route(path: '/save', name: 'absences_holidays_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $year = $request->request->getInt('year');
        $year = $year !== 0 ? $year : (int) date('Y');
        if (!$this->isCsrfTokenValid('absences_holidays', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('absences_holidays', ['year' => $year]);
        }

        // Single form posts one row; the copy helper posts several.
        $rows = $request->request->all()['holidays'] ?? [[
            'date' => $request->request->get('date'),
            'name' => $request->request->get('name'),
            'half_day' => $request->request->get('half_day'),
            'selected' => '1',
        ]];

        $formatter = new LocaleFormatter($this->localeService, $request->getLocale());
        $saved = [];
        $errors = [];
        foreach ((array) $rows as $row) {
            if (!\is_array($row) || ($row['selected'] ?? '') !== '1') {
                continue;
            }
            $dateRaw = trim(\is_scalar($row['date'] ?? null) ? (string) $row['date'] : '');
            $name = trim(\is_scalar($row['name'] ?? null) ? (string) $row['name'] : '');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw);
            if ($date === false || $date->format('Y-m-d') !== $dateRaw) {
                $errors[] = $this->translator->trans('absences.error.holiday_invalid_date', ['%date%' => $dateRaw], 'flashmessages');
                continue;
            }
            if ($name === '' || mb_strlen($name) > 150) {
                $errors[] = $this->translator->trans('absences.error.holiday_missing_name', ['%date%' => $formatter->dateShort($date)], 'flashmessages');
                continue;
            }
            $halfDay = $row['half_day'] ?? null;
            $this->store->saveHoliday($date, $name, $halfDay !== null && $halfDay !== '' && $halfDay !== '0' && $halfDay !== []);
            $saved[] = (string) $formatter->dateShort($date);
            $year = (int) $date->format('Y');
        }

        if ($saved !== []) {
            $this->flashSuccess($this->translator->trans('absences.flash.holidays_saved', ['%dates%' => implode(', ', $saved)], 'flashmessages'));
        }
        if ($errors !== []) {
            $this->flashError($this->translator->trans('absences.error.holidays_not_saved', ['%errors%' => implode('; ', $errors)], 'flashmessages'));
        }

        return $this->redirectToRoute('absences_holidays', ['year' => $year]);
    }

    #[Route(path: '/{id}/delete', name: 'absences_holidays_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $year = $request->request->getInt('year');
        $year = $year !== 0 ? $year : (int) date('Y');
        if (!$this->isCsrfTokenValid('absences_holidays_delete', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('absences_holidays', ['year' => $year]);
        }

        $this->store->deleteHoliday($id);
        $this->flashSuccess('absences.flash.holiday_deleted');

        return $this->redirectToRoute('absences_holidays', ['year' => $year]);
    }

    /**
     * @return list<array{id: int, name: string, half_day: bool, date: string, day: \DateTimeImmutable}>
     */
    private function list(int $year, \DateTimeZone $tz): array
    {
        $holidays = [];
        foreach ($this->store->holidaysBetween(new \DateTimeImmutable($year . '-01-01', $tz), new \DateTimeImmutable($year . '-12-31', $tz)) as $date => $holiday) {
            $holidays[] = $holiday + ['date' => $date, 'day' => new \DateTimeImmutable($date)];
        }

        return $holidays;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function proposals(int $year, \DateTimeZone $tz): array
    {
        $existing = array_column($this->list($year, $tz), 'date');
        $proposals = [];
        foreach ($this->list($year - 1, $tz) as $holiday) {
            $date = $year . substr($holiday['date'], 4);
            if (!checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), $year)) {
                continue;
            }
            $proposals[] = [
                'date' => $date,
                'day' => new \DateTimeImmutable($date),
                'name' => $holiday['name'],
                'half_day' => $holiday['half_day'],
                'exists' => \in_array($date, $existing, true),
                'weekend' => (int) (new \DateTimeImmutable($date))->format('N') >= 6,
            ];
        }

        return $proposals;
    }

    private function year(Request $request): int
    {
        $year = $request->query->getInt('year');

        return $year >= 2000 && $year <= 2100 ? $year : (int) (new \DateTimeImmutable('now', new \DateTimeZone($this->currentUser()->getTimezone())))->format('Y');
    }

    private function currentUser(): User
    {
        // Kimai's AbstractController::getUser() already denies anonymous access.
        return $this->getUser();
    }
}
