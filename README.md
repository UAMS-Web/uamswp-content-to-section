# UAMS Content → Section Converter

A standalone WordPress plugin that rewrites legacy **UAMS Content Blocks**
(`acf/uams-content`) into **UAMS Section Blocks** (`acf/uams-section`).

The two blocks look alike but store their body differently, so a conversion
remaps three fields and turns the Content Block's one WYSIWYG field into real
inner blocks. The [wiki](https://github.com/UAMS-Web/uamswp-content-to-section/wiki)
has the full guide and reference.

## Installing

Drop the `uamswp-content-to-section` directory into `wp-content/plugins/` and
activate it. No build step, no Composer dependencies, no options to configure
before first use.

```bash
cd wp-content/plugins
git clone git@github.com:UAMS-Web/uamswp-content-to-section.git
```

It needs the UAMS 2020 theme (or wherever the two blocks are registered) and
ACF Pro active to *render* the result, but it will parse and rewrite content
without them.

## Quick start

**If version 1.0.0 converted anything on the site, repair it first:** 1.0.0
saved converted posts with damaged block markup. See
[Repairing 1.0.0 conversions](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/Repairing-1.0.0-conversions).

There are three ways to run a conversion:

- **Tools → Content → Section:** site status counts, a list of every post still
  holding a Content Block, Preview (a dry run), Convert, and batch buttons. See
  [Your first conversion](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/Your-first-conversion).
- **In the editor:** select a UAMS Content Block and choose
  **Transform to → UAMS Section** in the block toolbar.
- **WP-CLI:**

  ```bash
  wp uams-c2s scan
  wp uams-c2s convert --all --dry-run --verbose
  wp uams-c2s convert --all
  wp uams-c2s revert 42
  ```

## Documentation

The [wiki](https://github.com/UAMS-Web/uamswp-content-to-section/wiki) holds
everything long-form:

- Guides: [the Tools screen](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/Converting-from-the-Tools-screen),
  [the editor](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/Converting-in-the-editor),
  [WP-CLI](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/Converting-with-WP-CLI) and
  [repairing 1.0.0 conversions](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/Repairing-1.0.0-conversions).
- Reference: [how a block is converted](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/How-a-block-is-converted),
  [conversion options](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/Conversion-options),
  [WP-CLI commands](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/WP-CLI-commands),
  [filters](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/Filters) and
  [safety and undo](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/Safety-and-undo).
- [Troubleshooting](https://github.com/UAMS-Web/uamswp-content-to-section/wiki/Troubleshooting).

The pages are written in [`docs/wiki/`](docs/wiki/Home.md) and published from
there; change them through a pull request.

## Tests

The Unit suite runs on Pest 5, on PHP 8.4, with WordPress mocked by Brain Monkey:

```bash
composer install
vendor/bin/pest
```

The Integration suite (WordPress booted through wp-phpunit, PHPUnit 9.6) has its own Composer setup:

```bash
composer setup:integration
composer test:integration
```

The two original scripts also run on their own, and `vendor/bin/pest` runs both in separate processes:

```bash
php tests-legacy/test-slashing.php
php tests-legacy/test-html-to-blocks.php
```

`test-slashing.php` is regression cover for the 1.0.0 slashing bug: damage
detection, that `write_content()` and the backup write both round-trip block
markup byte-for-byte, which source a revert or repair picks, and that a repair
refuses rather than reporting success when it cannot be trusted.

`test-html-to-blocks.php` covers the WYSIWYG parser, currently paragraph
alignment — including that a source already carrying `has-text-align-*` is not
given it twice.

`tests-legacy/stubs.php` fakes just enough WordPress to load both, including the fact
that `wp_update_post()` and `add_post_meta()` unslash what they are handed and
that `add_post_meta()` refuses to overwrite a `$unique` row. No PHPUnit, no
database.

Both suites are mutation-checked: removing a guard or a `wp_slash()` they cover
must turn them red. If a change to this plugin leaves them green, assume the
change is untested rather than safe.

## Credits

Developed and maintained by UAMS Web Services.

Developers:

- Josh Daugherty
- Brent Passmore
