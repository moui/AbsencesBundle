# Contributing

Thanks for helping! Bug reports, translations and pull requests are welcome.

## Reporting bugs and ideas

Open an issue using one of the templates. Please include your plugin, Kimai and PHP
versions; most problems depend on them.

## Development setup

The plugin is a Symfony bundle that lives inside a Kimai installation:

```bash
cd /path/to/kimai/var/plugins
git clone https://github.com/moui/AbsencesBundle.git
cd /path/to/kimai && bin/console kimai:reload -n
```

To run the linters you only need the plugin directory:

```bash
composer install
composer linting          # composer validate + php-cs-fixer (dry run) + PHPStan
composer codestyle-fix    # apply the code style
```

## Guidelines

- Target the minimum Kimai version in `composer.json` (`extra.kimai.require`) and PHP 8.2.
- All user-facing text goes through translations. Add the English string to
  `Resources/translations/*.en.xlf`; other locales are welcome but optional.
- Keep pull requests focused on one change and add a line to `CHANGELOG.md` under
  *Unreleased*.
- By contributing you agree that your contribution is licensed under the MIT license of this
  project.

## Translations

Translations are XLIFF 1.2 files in `Resources/translations/`. To add a language, copy the
`.en.xlf` file to `.<locale>.xlf` and translate the `<target>` elements. Check them with
`bin/console lint:xliff var/plugins/AbsencesBundle/Resources/translations` from your Kimai
directory.
