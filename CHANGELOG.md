# Changelog

## Unreleased

## 0.2.0

First public release.

- Translations (English, Spanish): every screen, menu entry, report, widget, flash
  message, CSV header, absence type and the working-time tooltips go through Kimai's
  translator (domains `messages`, `flashmessages` and `reporting`).
- Dates, weekdays and month names follow the user's locale (Kimai's `date_short`,
  `day_name` and `month_name`) instead of a fixed `d/m/Y` format and Spanish names.
- The CSV export is named `absences_<from>_<to>.csv` and its headers and values (type,
  yes/no, source) are translated.
- Removed the one-off `kimai:absences:import-timesheets` command, which converted
  timesheets on site-specific "leave" projects into absences. The `source` and
  `legacy_timesheet_ids` columns stay in the schema; existing rows are untouched.
- Generic examples in the holiday form (placeholder, movable-holiday hint).
- MIT license, code style header, CI (php-cs-fixer, PHPStan), contributing guide.

## 0.1.0

- First release, targeting Kimai 2.66 (`extra.kimai.require` 26600) and PHP 8.2+.
- Company holiday calendar (full or half day) that empties or halves everyone's expected
  hours, and per-person absences (full day or N hours) credited as time — the same model
  as Kimai's own contract plugin. Locked months keep their snapshot.
- Screens: *My absences*, *Team absences* (with a month grid), *Holidays* (with "copy
  from the previous year"), *Annual vacation days*. Everyone records their own absences
  without approval; team leads and admins manage their scope.
- *Absences* report in Reporting, with the yearly vacation balance and CSV.
- Dashboard widget *My leave* (vacation days left, next holiday), on the default
  dashboard; `kimai:absences:add-widget [--apply]` for customised dashboards.
- Absences and holidays also lower the weekly planning capacity (WeeklyPlanningBundle).
