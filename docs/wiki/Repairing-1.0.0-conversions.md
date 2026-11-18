# Repairing 1.0.0 conversions

Written against v1.1.0.

**Warning:** version 1.0.0 saved converted posts with damaged block markup. Upgrade before you convert anything else, then repair what it converted.

## What 1.0.0 damaged

`wp_update_post()` runs `wp_unslash()` over whatever it is handed, and `serialize_blocks()` writes backslash escapes into every block's attribute JSON: core escapes `<`, `>`, `&`, `"` and `--` as `<`, `>`, `&`, `"` and `-`. Version 1.0.0 passed the serialized content through unslashed, so one backslash was removed from each escape and the attributes were saved as literal text:

```
before   "cta_bar_text":"<p style="text-align: center">Contact<\/p>"
after    "cta_bar_text":"u003cp style=u0022text-align: centeru0022u003eContactu003c/pu003e"
```

This hit **every block on a converted page**, not only the converted ones, because the whole `post_content` went through the same write. It shows up soonest on ACF blocks that render a WYSIWYG field as text; the CTA bar is the usual first sighting.

The same bug damaged the `_uams_c2s_backup` meta, because `add_post_meta()` unslashes too. **Reverting a 1.0.0 conversion restores equally damaged content.** Repair therefore ignores the backup and restores from the WordPress revision saved before the conversion, then converts again with the fixed code.

## Visible damage is a floor, not a total

The detector recognizes only escapes whose stripped form is unmistakable: `u003c`, `u003e`, `u0026`, `u0022` and `u002d` with no backslash in front. Other losses leave nothing to find:

| Escape | Becomes | Detectable | Matters |
|---|---|---|---|
| `<` `"` `&` `-` | `u003c` `u0022` … | yes | yes: renders as literal text |
| `\n` `\r` `\t` | `n` `r` `t` | **no**: reads as ordinary text | yes: line breaks are gone |
| `\\` | `\` | no | yes |
| `\/` | `/` | no | no: the same JSON value |

So a page 1.0.0 converted can show **no** detectable damage and still have lost every line break inside a block attribute. **A post is listed if its markup carries the signature, or if its conversion record names an affected version**; the record is the stronger witness. For the same reason the backup meta is refused on its record alone when an affected version wrote it.

## The restore is verified, not assumed

A revision is written only when someone edits a post, so the newest one before a conversion is often much older than the conversion. That is normally right, because nothing changed in between. It is wrong if something rewrote `post_content` without creating a revision, and restoring a stale revision would discard the difference.

The backup meta settles it: it was copied from `post_content` at the moment of conversion, so comparing it with the candidate revision proves whether that revision is what was converted. Version 1.0.1 stored the backup intact and 1.0.0 stored it a slash level down; either form counts as a match. Each post gets one verdict:

- **matches the pre-conversion backup**: proven; repair freely.
- **needs a manual comparison**: the revision is not what was converted, so repairing would discard the difference. **Repair refuses these on every path**: the batch run, the per-post button and `wp uams-c2s repair` alike, and the row offers no button. Compare the revision with the backup by hand and decide what the post should hold. There is deliberately no one-click override.
- **no backup survives to check it against**: the revision is the only source.

## Steps

1. **Upgrade the plugin** to 1.0.1 or later.
2. **See what is damaged and where each post restores from:**

   ```bash
   wp uams-c2s repair --all --dry-run
   ```

   Or open **Tools → Content → Section**: a **Needs re-converting** table lists every affected post with its revision, its verdict, whether the damage is visible or inferred from the record, and a per-post **Repair** button.
3. **Repair:**

   ```bash
   wp uams-c2s repair --all
   ```

   Or choose **Repair all** on the Tools screen, which runs in batches of 10.
4. **Handle what is left.** A post with no surviving clean revision is listed with the reason and no button; it needs a database backup. A post whose verdict asks for a manual comparison needs a person to decide.

## A damaged post is not proof this plugin damaged it

Any code that writes `post_content` without `wp_slash()` leaves the same signature: an import, a REST or API update, a search and replace. The table therefore shows whether the post carries a conversion record from this plugin, and an excerpt of exactly what the detector matched, so the two can be told apart before repairing. Repairing a post the plugin never converted would restore it to whatever revision predates the damage, which may not be what you want.

## Reference

- [WP-CLI commands](WP-CLI-commands.md#repair)
- [Safety and undo](Safety-and-undo.md)
