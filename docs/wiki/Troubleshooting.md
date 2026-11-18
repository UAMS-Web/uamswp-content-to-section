# Troubleshooting

Written against v1.1.0.

- [Block text shows `u003c` or `u0022`](#block-text-shows-u003c-or-u0022)
- [Skipped a Content block with GeoTargetingWP rules](#skipped-a-content-block-with-geotargetingwp-rules)
- [A converted block had no custom anchor](#a-converted-block-had-no-custom-anchor)
- [An h2 sits inside the section](#an-h2-sits-inside-the-section)
- [PHP is missing the DOM extension](#php-is-missing-the-dom-extension)
- [A Content block did not store its fields under "data"](#a-content-block-did-not-store-its-fields-under-data)
- [The stored backup was written by a plugin version that unslashed it](#the-stored-backup-was-written-by-a-plugin-version-that-unslashed-it)
- [A converted post has no Revert button](#a-converted-post-has-no-revert-button)
- [You do not have permission to run this conversion](#you-do-not-have-permission-to-run-this-conversion)

## Block text shows `u003c` or `u0022`

A page shows literal text such as `u003cp style=u0022text-align: centeru0022u003e`, often in a CTA bar first.

**Cause:** the post was saved without slashing its block markup, most often by version 1.0.0 of this plugin, but any import, API update or search and replace that skips `wp_slash()` leaves the same signature.

**Fix:** upgrade, then repair from the revision saved before the conversion. Do not revert: a 1.0.0 backup is damaged in the same way. See [Repairing 1.0.0 conversions](Repairing-1.0.0-conversions.md).

## Skipped a Content block with GeoTargetingWP rules

The preview, or `convert --verbose`, reports *Skipped a Content block with GeoTargetingWP rules.* and the post stays in the list.

**Cause:** the Section Block has no region filter, so converting would show region-restricted content to everyone. Geo-targeted blocks are skipped by default.

**Fix:** decide what the content should do, then convert with the geo-targeted option on (`--include-geo`). See [Conversion options](Conversion-options.md#geo-targeted-blocks).

## A converted block had no custom anchor

The preview notes that the block's rendered id changes from `content-…` to `uams-section-…`.

**Cause:** a Content Block with no custom anchor renders a generated id, and the Section Block generates a different one.

**Fix:** find in-page links and scripts that point at the old id, or give the block a custom anchor before converting; an explicit anchor carries across unchanged.

## An h2 sits inside the section

The preview notes *An &lt;h2&gt; sits inside the section, which already renders an &lt;h2&gt;.*

**Cause:** the Section Block renders its own `<h2>`, so a body `<h1>` or `<h2>` breaks the page outline.

**Fix:** convert with the headings option on (`--demote-headings`). See [Conversion options](Conversion-options.md#headings).

## PHP is missing the DOM extension

The Tools screen shows *PHP is missing the DOM extension.*

**Cause:** splitting the WYSIWYG HTML into blocks uses PHP's DOM extension. Without it, each body would be kept as a single Custom HTML block.

**Fix:** enable the PHP `dom` extension on the server, then convert.

## A Content block did not store its fields under "data"

The preview notes that a block's heading and background could not be read, and the Section Block was created with the defaults.

**Cause:** the block's attributes do not hold its ACF fields in the `data` object the converter reads them from.

**Fix:** check the converted block in the editor and set its heading and background by hand.

## The stored backup was written by a plugin version that unslashed it

A revert or repair fails with *The stored backup was written by a plugin version that unslashed it, and no clean pre-conversion revision survives.*

**Cause:** version 1.0.0 wrote the backup, so it is damaged, and no revision from before the conversion survives to restore from.

**Fix:** restore the post from a database backup.

## A converted post has no Revert button

A post converted from the Tools screen is no longer listed, so there is no *Revert* button for it.

**Cause:** the list shows only posts that still hold a Content Block; a post that converted completely leaves it.

**Fix:** run `wp uams-c2s revert <id>`, or restore the revision saved before the conversion from the post's *Revisions* screen. See [Safety and undo](Safety-and-undo.md).

## You do not have permission to run this conversion

An action stops with *You do not have permission to run this conversion.* or *You cannot edit that post.*

**Cause:** every action needs `manage_options`, and `edit_post` on the target post, plus a valid nonce. A nonce expires, so a Tools screen left open for a long time can fail this way too.

**Fix:** reload the Tools screen and try again, signed in as a user with both capabilities.
