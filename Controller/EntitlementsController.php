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
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use KimaiPlugin\AbsencesBundle\Model\AbsenceType;
use KimaiPlugin\AbsencesBundle\Service\AbsenceStore;
use KimaiPlugin\AbsencesBundle\Service\AbsenceSummary;
use KimaiPlugin\AbsencesBundle\Service\TeamScope;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Yearly vacation days per person, stored in Kimai's own "holidays" user
 * preference (the "Vacation days" field of the user profile).
 */
#[Route(path: '/absences/entitlements')]
#[IsGranted('absences_admin')]
final class EntitlementsController extends AbstractController
{
    public function __construct(
        private readonly TeamScope $scope,
        private readonly AbsenceStore $store,
        private readonly UserRepository $users,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'absences_entitlements', methods: ['GET'])]
    public function index(): Response
    {
        $me = $this->currentUser();
        $year = (int) (new \DateTimeImmutable('now', new \DateTimeZone($me->getTimezone())))->format('Y');
        $people = $this->scope->everyone();

        $used = [];
        foreach ($this->store->absencesBetween(new \DateTimeImmutable($year . '-01-01'), new \DateTimeImmutable($year . '-12-31'), array_keys($people), [AbsenceType::VACATION]) as $row) {
            $used[$row['user_id']][] = AbsenceSummary::describe($row, $people[$row['user_id']]);
        }

        $rows = [];
        foreach ($people as $id => $person) {
            $rows[] = [
                'person' => $person,
                'value' => AbsenceStore::vacationDays($person),
                'balance' => AbsenceSummary::vacationBalance($person, $used[$id] ?? []),
            ];
        }

        $page = new PageSetup('absences.entitlements.title');
        $page->setActionName('absences_entitlements');

        return $this->render('@Absences/entitlements.html.twig', ['page_setup' => $page, 'rows' => $rows, 'year' => $year]);
    }

    #[Route(path: '', name: 'absences_entitlements_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('absences_entitlements', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('absences_entitlements');
        }

        $people = $this->scope->everyone();
        $posted = (array) ($request->request->all()['days'] ?? []);
        $errors = [];
        $changes = [];

        // Validate everything first, then save: a typo never half-applies.
        foreach ($posted as $id => $raw) {
            $person = $people[(int) $id] ?? null;
            if ($person === null) {
                continue;
            }
            $raw = str_replace(',', '.', trim(\is_scalar($raw) ? (string) $raw : ''));
            if ($raw === '') {
                $value = null;
            } elseif (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 60 || fmod((float) $raw * 2, 1.0) !== 0.0) {
                $errors[] = $person->getDisplayName() . ' ("' . $raw . '")';
                continue;
            } else {
                $value = (float) $raw;
            }
            if ($value !== AbsenceStore::vacationDays($person)) {
                $changes[] = [$person, $value];
            }
        }

        if ($errors !== []) {
            $this->flashError($this->translator->trans('absences.error.entitlements_invalid', ['%values%' => implode(', ', $errors)], 'flashmessages'));

            return $this->redirectToRoute('absences_entitlements');
        }

        foreach ($changes as [$person, $value]) {
            // Kimai's own default for "not set" is 0, so store that rather than null.
            $person->setPreferenceValue('holidays', $value === null ? '0' : (string) $value);
            $this->users->saveUser($person);
        }

        if (\count($changes) === 0) {
            $this->flashSuccess('absences.flash.entitlements_unchanged');
        } else {
            $this->flashSuccess($this->translator->trans('absences.flash.entitlements_saved', ['%count%' => \count($changes)], 'flashmessages'));
        }

        return $this->redirectToRoute('absences_entitlements');
    }

    private function currentUser(): User
    {
        // Kimai's AbstractController::getUser() already denies anonymous access.
        return $this->getUser();
    }
}
