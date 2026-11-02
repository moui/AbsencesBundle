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
use App\Repository\UserRepository;

/**
 * Whose absences a viewer may manage and report on. Same rule as the weekly
 * planning plugin (WeeklyPlanningBundle ScopeResolver), so team leads see the
 * same people everywhere:
 *
 * - admins (ROLE_ADMIN / ROLE_SUPER_ADMIN): every enabled user;
 * - team leads: the enabled members of the teams they lead (themselves
 *   included, as members of those teams);
 * - anyone else: nobody.
 */
final class TeamScope
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @return array<int, User> id => user, sorted by display name
     */
    public function people(User $viewer): array
    {
        $people = [];

        if ($viewer->isAdmin(false)) {
            foreach ($this->users->findAll() as $user) {
                if ($user->isEnabled() && !$user->isSystemAccount()) {
                    $people[(int) $user->getId()] = $user;
                }
            }
        } else {
            foreach ($viewer->getTeams() as $team) {
                if (!$viewer->isTeamleadOf($team)) {
                    continue;
                }
                foreach ($team->getUsers() as $user) {
                    if ($user->isEnabled()) {
                        $people[(int) $user->getId()] = $user;
                    }
                }
            }
        }

        uasort($people, static fn (User $a, User $b): int => strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return $people;
    }

    /**
     * Enabled, non-system users — for the yearly vacation days screen.
     *
     * @return array<int, User>
     */
    public function everyone(): array
    {
        $people = [];
        foreach ($this->users->findAll() as $user) {
            if ($user->isEnabled() && !$user->isSystemAccount()) {
                $people[(int) $user->getId()] = $user;
            }
        }
        uasort($people, static fn (User $a, User $b): int => strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return $people;
    }
}
