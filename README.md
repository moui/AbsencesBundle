# Absences Bundle for Kimai

[![CI](https://github.com/moui/AbsencesBundle/actions/workflows/linting.yaml/badge.svg)](https://github.com/moui/AbsencesBundle/actions/workflows/linting.yaml)

A company holiday calendar and per-person absences (vacation, sick leave, study leave,
other), for full or partial days, that feed Kimai's own working times. Nobody has to log
hours on "leave" projects any more: a holiday lowers everyone's expected hours, and an
absence is credited to the person's actual time, so Kimai's *Working times* page, month
locking and every plugin that reads working times see the right numbers.

## Features

- **Company holiday calendar**, full or half days, with a "copy from the previous year"
  helper that proposes last year's dates for review.
- **Absences per person**: a date range of full days, or a number of hours on one day
  (e.g. 2 h at the doctor), with a comment. No approval workflow.
- **Team view** for team leads and admins: a month grid of everyone in scope, plus the
  same form and list for a chosen person.
- **Yearly vacation days** per user (Kimai's own *Vacation days* profile field), edited
  for everyone on one screen, with days used and left.
- **Absences report** in *Reporting*, with totals per type and per person, the vacation
  balance and a CSV export.
- **Dashboard widget** with the vacation days left this year and the next holiday.
- Translations: English and Spanish.

## How it works

The plugin uses the same mechanism as Kimai's contract (working time) feature, through
Kimai's `WorkingTimeYearEvent` (`EventSubscriber/WorkingTimeSubscriber.php`):

- **Holiday** (company calendar): that day's *expected* hours become 0 for everyone; a
  half-day holiday halves them. Nobody has to log hours to "complete" a holiday.
- **Absence** (per person): credited to *actual* time as a day addon. A full day covers
  what is still expected that day; a partial absence covers its hours, never more than
  what is expected. Partial absences are credited first, so a full day on the same date
  takes only what is left. The person logs their worked hours as usual.
- **Locked (approved) months** keep their stored snapshot: nothing changes there.

Because everything goes through Kimai's working times, it shows up everywhere that uses
them, with no further changes:

- Kimai's **Working times** page shows each day with Kimai's own colours and icons
  (holiday, vacation, sickness, time off, other), and a tooltip such as
  "Sick leave (2:00)".
- Optional companion plugins use the corrected numbers:
  [MissingHoursBundle](https://github.com/moui/MissingHoursBundle) (widget, report,
  reminder emails), [WorkingTimeLockBundle](https://github.com/moui/WorkingTimeLockBundle)
  (month locking) and [WeeklyPlanningBundle](https://github.com/moui/WeeklyPlanningBundle),
  whose capacity drops for holidays and absences, including ones recorded ahead of time.

### Data model

One row is stored per person, day and type (`kimai2_absences`). A range such as
"vacation 3–14 November" is stored as one row per working day: weekends, days the person
does not work (no contract hours for that weekday) and full-day holidays are skipped and
reported. Recording the same day and type again replaces the row. Holidays live in
`kimai2_absences_holidays`, one per date.

The `source` column records how a row was created: `manual` for every entry made in the
UI. Other values (such as `import`) are left for rows created by external import tools,
and `import` is shown as "Imported".

The yearly vacation days are Kimai's own `holidays` user preference (the *Vacation days*
field of the user profile); 0 means "not set".

### Counting days and hours

A full day counts 1 day and the person's contract hours for that weekday. A partial
absence counts its hours, and hours / contract hours as days. Holidays are not absences;
the report only shows how many fall in the range.

## Screens

Main menu → **Absences**:

| Screen | Who | What |
|---|---|---|
| My absences | everyone (`absences_own`) | Record your own absences (range, full day or hours, comment), delete them, see your vacation balance, totals per type and the year's holidays. No approval. |
| Team absences | team leads, admins (`absences_team`) | Month grid of everyone in scope (cell colours per type, "2h" for partial, holidays and weekends shaded), plus the same list and form for a chosen person. |
| Holidays | admins (`absences_admin`) | Add, change and delete holidays per year, full or half day. "Copy holidays from …" proposes last year's dates in this year for review; nothing is saved until you confirm, and movable holidays should be checked. |
| Annual vacation days | admins (`absences_admin`) | Yearly vacation days for every user (half days allowed, empty = not set), with days used and left this year. All values are validated before anything is saved. |

**Scope** (whom a team lead manages): admins see every enabled user; team leads see the
enabled members of the teams they lead. Every POST re-checks the person against that
scope, so users cannot record or delete absences for someone else, and a team lead cannot
for someone outside their teams. When a team lead records an absence for a member, the
team lead is stored as its creator. This is the same rule as in
[WeeklyPlanningBundle](https://github.com/moui/WeeklyPlanningBundle), so team leads see
the same people in both.

### Report: Reporting → Absences

Needs `absences_team` (and Kimai's `view_reporting`). Filters: a year, or a date range
(which takes precedence), plus people and types. It shows:

- totals per type;
- a person × type table in days and hours, with the yearly entitlement and what is left
  (whole year only);
- the detail list;
- a CSV export (person, date, type, full day, hours, days, comment, source).

### Dashboard widget "My leave"

For every user, own data: vacation days left this year (same calculation as *My
absences*, "not set" when the entitlement is empty) and the next company holiday. It is
added to Kimai's default dashboard. Users who already customised their dashboard can add
it themselves, or an admin can run `kimai:absences:add-widget` (see below).

## Requirements

- Kimai 2.66 or later
- PHP 8.2 or later

## Installation

Download and extract the [latest release](https://github.com/moui/AbsencesBundle/releases)
into `var/plugins/` (see the [plugin docs](https://www.kimai.org/documentation/plugin-management.html)).
The file structure needs to look like this afterwards:

```
var/plugins/
├── AbsencesBundle
│   ├── AbsencesBundle.php
│   └ ... more files and directories follow here ...
```

Alternatively, clone the repository:

```bash
cd var/plugins/
git clone https://github.com/moui/AbsencesBundle.git
```

Then rebuild the cache:

```bash
bin/console kimai:reload -n
```

And create the database tables (safe to re-run; run it again after every update):

```bash
bin/console kimai:bundle:absences:install
```

### Docker

With the official Docker image, mount or copy the plugin to
`/opt/kimai/var/plugins/AbsencesBundle` and run the commands inside the container as
`www-data`:

```bash
docker compose exec -u www-data kimai bin/console kimai:reload -n
docker compose exec -u www-data kimai bin/console kimai:bundle:absences:install
```

Running as `www-data` matters: run as root, the commands leave cache files owned by root
that Kimai then cannot write.

## Configuration

There is nothing to configure. After installing:

1. Load the holiday calendar (*Absences → Holidays*).
2. Set each person's yearly vacation days (*Absences → Annual vacation days*, or the
   *Vacation days* field of the user profile).
3. Make sure users have their contract hours per weekday set in their profile (Kimai's
   working time settings): days without contract hours are treated as non-working days.

## Permissions

| Permission | Default roles | Grants |
|---|---|---|
| `absences_own` | `ROLE_USER`, `ROLE_TEAMLEAD`, `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` | *My absences*, the dashboard widget, recording and deleting one's own absences. |
| `absences_team` | `ROLE_TEAMLEAD`, `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` | *Team absences* and the absences report, for the people in scope. |
| `absences_admin` | `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` | *Holidays* and *Annual vacation days*. |

Change the assignment in Kimai's role and permission screen; see the
[permission documentation](https://www.kimai.org/documentation/permissions.html).

## Console commands

| Command | What it does |
|---|---|
| `kimai:bundle:absences:install` | Creates or updates the plugin's tables (runs its migrations). Safe to re-run. |
| `kimai:absences:add-widget [--apply]` | Adds the "My leave" widget to every dashboard that users have customised (Kimai's default dashboard already shows it). Without `--apply` it only reports what it would do. Re-running is harmless. |

## Translations

The plugin ships English (`en`, the fallback) and Spanish (`es`) in
`Resources/translations/` (domains `messages`, `flashmessages` and `reporting`). To add a
language, copy each `*.en.xlf` file to `*.<locale>.xlf`, translate the `<target>`
elements and run `bin/console kimai:reload -n`. Pull requests with new languages are
welcome.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
