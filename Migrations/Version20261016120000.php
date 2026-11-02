<?php

declare(strict_types=1);

/*
 * This file is part of the "AbsencesBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\AbsencesBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Company holidays and per-person absences.
 *
 * One absence row per person, day and type: a range ("vacations from the 3rd
 * to the 14th") is stored as one row per working day, so partial days, edits
 * and reports never have to split ranges.
 */
final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Absences: holidays calendar and per-day absences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE kimai2_absences_holidays (
                id INT AUTO_INCREMENT NOT NULL,
                date DATE NOT NULL,
                name VARCHAR(150) NOT NULL,
                half_day TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX UNIQ_ABS_HOLIDAY_DATE (date),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE kimai2_absences (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                date DATE NOT NULL,
                type VARCHAR(20) NOT NULL,
                full_day TINYINT(1) NOT NULL DEFAULT 1,
                hours NUMERIC(5, 2) DEFAULT NULL,
                comment TEXT DEFAULT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                legacy_timesheet_ids TEXT DEFAULT NULL,
                created_by_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX UNIQ_ABS_USER_DATE_TYPE (user_id, date, type),
                INDEX IDX_ABS_DATE (date),
                PRIMARY KEY(id),
                CONSTRAINT FK_ABS_USER FOREIGN KEY (user_id) REFERENCES kimai2_users (id) ON DELETE CASCADE,
                CONSTRAINT FK_ABS_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES kimai2_users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kimai2_absences');
        $this->addSql('DROP TABLE kimai2_absences_holidays');
    }
}
