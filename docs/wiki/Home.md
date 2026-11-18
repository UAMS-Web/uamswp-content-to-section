# UAMS Content → Section Converter

This wiki covers how to convert legacy UAMS Content Blocks (`acf/uams-content`) into UAMS Section Blocks (`acf/uams-section`) with this plugin: the three ways to run a conversion, how to undo one, how to repair posts that version 1.0.0 damaged, and the reference for every command, option and filter. The [README](https://github.com/UAMS-Web/uamswp-content-to-section#readme) keeps the overview, installation and the test suites.

Written against v1.1.0.

**Getting started**

- [Installation (README)](https://github.com/UAMS-Web/uamswp-content-to-section#installing): where the plugin goes and what it needs to render its output.
- [Your first conversion](Your-first-conversion.md): preview one post, convert it, check it, and revert it if needed.

**Guides**

- [Converting from the Tools screen](Converting-from-the-Tools-screen.md): the site status counts, the post list, and the per-status batch buttons.
- [Converting in the editor](Converting-in-the-editor.md): the block toolbar's transform for one block at a time.
- [Converting with WP-CLI](Converting-with-WP-CLI.md): scanning, dry runs, batches by status, and reverting from the shell.
- [Repairing 1.0.0 conversions](Repairing-1.0.0-conversions.md): what version 1.0.0 damaged, how repair restores it, and when it refuses.

**Reference**

- [How a block is converted](How-a-block-is-converted.md): the field mapping, what each piece of WYSIWYG HTML becomes, and what the plugin deliberately leaves alone.
- [Conversion options](Conversion-options.md): geo-targeted blocks, heading demotion and list markup, on the Tools screen and on the command line.
- [WP-CLI commands](WP-CLI-commands.md): `scan`, `convert`, `revert` and `repair`, with every flag.
- [Filters](Filters.md): the four filters and what each changes.
- [Safety and undo](Safety-and-undo.md): revisions, the backup meta, permissions and what uninstalling keeps.

**Troubleshooting**

- [Troubleshooting](Troubleshooting.md): symptoms you may meet, their cause and the fix.
