# Converting with WP-CLI

Written against v1.1.0.

Use WP-CLI to survey a site, dry-run a conversion, convert in batches by status, and undo, all from the shell. Every command is listed with its flags in [WP-CLI commands](WP-CLI-commands.md).

1. **See what is there.**

   ```bash
   wp uams-c2s scan
   wp uams-c2s scan --post_type=page --format=count
   ```

   The table lists each post's ID, type, status, Content Block count, geo flag and title.

2. **Dry-run the whole site and read every note.**

   ```bash
   wp uams-c2s convert --all --dry-run --verbose
   ```

   Nothing is saved. The notes name skipped geo-targeted blocks, anchors that change, and empty bodies.

3. **Convert the drafts first, then the rest.**

   ```bash
   wp uams-c2s convert --all --post_status=draft
   wp uams-c2s convert --all
   ```

4. **Convert chosen posts with options.**

   ```bash
   wp uams-c2s convert 42 108 --demote-headings
   ```

5. **Undo.**

   ```bash
   wp uams-c2s revert 42
   wp uams-c2s revert --all
   ```

   `revert --all` reverts every post that still holds a pre-conversion backup.

**Note:** if the site was converted with version 1.0.0, run the repair first. See [Repairing 1.0.0 conversions](Repairing-1.0.0-conversions.md).

## Reference

- [WP-CLI commands](WP-CLI-commands.md)
- [Conversion options](Conversion-options.md)
- [Safety and undo](Safety-and-undo.md)
