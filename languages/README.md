# Translations

This directory holds the GNU gettext translation files for Ligase.

## Status

**Source strings are currently a mix of Polish and English.** Before submitting to the WordPress.org plugin directory, source strings must be normalised to English and Polish translations moved into `ligase-pl_PL.po`/`.mo`.

## How translations work

- Source language: **English** (the string inside `__('...', 'ligase')` calls)
- Translations: per-locale `.po` files compiled to `.mo`, named `ligase-{locale}.mo` (e.g. `ligase-pl_PL.mo`)
- WordPress loads the right `.mo` automatically based on site locale via `load_plugin_textdomain('ligase', ...)`

## Generating a fresh template

```bash
wp i18n make-pot . languages/ligase.pot
wp i18n make-json languages/  # for JS strings, if any
```

## Adding a translation

```bash
msginit --locale=pl_PL --output=languages/ligase-pl_PL.po --input=languages/ligase.pot
# edit ligase-pl_PL.po with translations
msgfmt languages/ligase-pl_PL.po -o languages/ligase-pl_PL.mo
```

## Pending work

Approximately 53 Polish source strings remain in:

- `admin/class-settings.php` (51 strings — field labels, section descriptions)
- `admin/views/meta-box.php` (1 string)
- `admin/views/posts.php` (1 string)

These should be:
1. Translated to English in source
2. Polish moved to `ligase-pl_PL.po`
