# UAMS Content → Section Converter

A standalone WordPress plugin that rewrites legacy **UAMS Content Blocks**
(`acf/uams-content`) into **UAMS Section Blocks** (`acf/uams-section`).

The two blocks look alike but store their body differently:

| | UAMS Content Block | UAMS Section Block |
|---|---|---|
| Block name | `acf/uams-content` | `acf/uams-section` |
| Heading | `content_heading` | `section_heading` |
| Hide heading | `content_hide_heading` | `section_hide_heading` |
| Background | `content_background_color` | `section_background_color` |
| Body | `content_content` — a single ACF WYSIWYG field | **InnerBlocks** (`jsx => true`) |
| Region filter | `content_geo` / `content_geo_region` | *none* |
| Rendered id | `content-…` | `uams-section-…` |

So the conversion is two jobs: remap three fields, and turn one blob of
WYSIWYG HTML into a real block tree.

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

## Three ways to run it

### 1. Tools → Content → Section

Opens with **Site status**: how many posts still hold a Content Block, how many
hold a Section Block, how many conversions this plugin has recorded — broken
down by the plugin version that wrote them — how many backups it is keeping,
how many posts show damaged block markup, and how many were converted by a
version that did not slash its writes. The list of posts to convert shrinks as
they convert, so these counts are what tell you whether a list that has gone
quiet did so because the work is done, or because something else changed those
posts.

Below it, every post, page and CPT entry still holding a Content Block, with a
per-post block count and a geo-targeting flag.

- **Preview** — a dry run showing the before/after `post_content` side by side,
  plus a list of anything worth eyeballing before you commit.
- **Convert** — converts one post.
- **Convert all**, plus one button per post status that has posts —
  *Convert 45 drafts*, *Convert 12 published*. Each walks its own scope in
  batches of 10 and stops there, so a draft sweep never runs on into the
  published pages. The usual workflow: batch the drafts, walk the published
  ones by hand with Preview.
- **Revert** — restores the exact pre-conversion content.
- **Repair** — appears only if 1.0.0 conversions need re-running. See
  [Repairing 1.0.0 conversions](#repairing-100-conversions).

### 2. In the editor

Select a UAMS Content Block and use the block toolbar's **Transform to → UAMS
Section**. This path uses the editor's own `rawHandler`, i.e. the same code
that runs when you paste HTML into the canvas, so it matches what an editor
would get by hand.

### 3. WP-CLI

```bash
# What is out there?
wp uams-c2s scan
wp uams-c2s scan --post_type=page --format=count

# Look before you leap
wp uams-c2s convert --all --dry-run --verbose

# Go
wp uams-c2s convert --all
wp uams-c2s convert --all --post_status=draft
wp uams-c2s convert 42 108 --demote-headings

# Undo
wp uams-c2s revert 42
wp uams-c2s revert --all

# Clean up after 1.0.0
wp uams-c2s repair --all --dry-run
wp uams-c2s repair --all
```

## What the WYSIWYG body becomes

| Source HTML | Block |
|---|---|
| `<p>` | `core/paragraph` (Bootstrap `text-center`/`text-right` → block alignment) |
| `<h1>`–`<h6>` | `core/heading`, `id` preserved as the block anchor |
| `<ul>` / `<ol>` | `core/list` + `core/list-item`, nesting intact, `start` preserved |
| `<blockquote>` | `core/quote`, with `<cite>` lifted into the citation slot |
| `<table>` | `core/table` |
| `<img>` / `<figure>` | `core/image`, attachment id recovered from `wp-image-N` or the URL |
| Bare URL on its own line | `core/embed`, provider detected |
| YouTube / Vimeo `<iframe>` | `core/embed` |
| `[shortcode]` | `core/shortcode` |
| `<hr>` | `core/separator` |
| `<pre>` / `<pre><code>` | `core/preformatted` / `core/code` |
| `<div>`, unknown markup | `core/html`, verbatim |

ACF stores the WYSIWYG value unfiltered and the front end applied `wpautop()`
at render time through `acf_the_content`. Inner blocks are not filtered that
way, so the plugin runs `wpautop()` + `shortcode_unautop()` first — otherwise
every double newline would collapse into one run-on paragraph.

## Things it deliberately does not guess

**Geo-targeted blocks are skipped by default.** The Content Block can restrict
itself to GeoTargetingWP regions; the Section Block cannot. Converting one
would silently publish region-restricted content to everyone, so those blocks
are left alone and reported. Opt in with the *Geo-targeted blocks* checkbox or
`--include-geo` once you have decided what should happen to them.

**Anchors change.** A Content Block with no custom anchor renders as
`id="content-block_xxx"`; the Section Block renders `id="uams-section-…"`. An
explicit anchor is carried across unchanged; anything relying on the generated
id is flagged in the preview.

**Layout markup is preserved, not reinterpreted.** A `<div class="row">` of
Bootstrap columns becomes a `core/html` block rather than a guessed-at
column layout.

**Headings are left where they are** unless you ask otherwise. The Section
Block renders its own `<h2>`, so any `<h1>`/`<h2>` in the body breaks the
outline — the preview flags it, and *Demote headings* / `--demote-headings`
rewrites them to `<h3>`.

## Repairing 1.0.0 conversions

**1.0.0 saved converted posts with mangled block markup. Upgrade before you
convert anything else.**

`wp_update_post()` runs `wp_unslash()` over whatever it is handed, and
`serialize_blocks()` writes backslash escapes into every block's attribute JSON
— core escapes `<`, `>`, `&`, `"` and `--` as `\u003c`, `\u003e`,
`\u0026`, `\u0022` and `\u002d`. 1.0.0 passed the serialized content
through unslashed, so
one backslash was eaten from each escape and the attributes were rewritten as
literal text:

```
before   "cta_bar_text":"\u003cp style=\u0022text-align: center\u0022\u003eContact\u003c\/p\u003e"
after    "cta_bar_text":"u003cp style=u0022text-align: centeru0022u003eContactu003c/pu003e"
```

This hit **every block on a converted page**, not only the ones being converted,
because the whole `post_content` went through the same write. It shows up
soonest on ACF blocks that render a WYSIWYG field as text — the CTA bar is the
usual first sighting.

The same bug also damaged the `_uams_c2s_backup` meta, because `add_post_meta()`
unslashes too. **Reverting a 1.0.0 conversion restores equally mangled content.**
Repair therefore ignores the backup and restores from the WordPress revision
saved before the conversion, then converts again with the fixed code.

### Visible damage is a floor, not a total

The detector only recognises escapes whose stripped form is unmistakable —
`u003c`, `u003e`, `u0026`, `u0022`, `u002d` with no backslash in front. Other
losses leave nothing to find:

| Escape | Becomes | Detectable? | Matters? |
|---|---|---|---|
| `\u003c` `\u0022` `\u0026` `\u002d` | `u003c` `u0022` … | yes | yes — renders as literal text |
| `\n` `\r` `\t` | `n` `r` `t` | **no** — reads as ordinary text | yes — line breaks are gone |
| `\\` | `\` | no | yes |
| `\/` | `/` | no | no — same JSON value |

So a page 1.0.0 converted can show **zero** detectable damage and still have lost
every line break inside a block attribute. **A post is therefore listed if its
markup carries the signature, or if its conversion record names an affected
version** — provenance is the stronger witness. For the same reason the backup
meta is refused on provenance alone when an affected version wrote it, rather
than waiting for it to look wrong.

### The restore is verified, not assumed

A revision is only written when someone edits a post, so the newest one
predating a conversion is often much older than the conversion — a page last
touched in 2025 and converted in 2026 restores from a 2025 revision. That is
normally right, because nothing changed in between. It is wrong if something
rewrote `post_content` without creating a revision, and restoring a stale
revision would quietly discard the difference.

The backup meta settles it: it was copied from `post_content` at the moment of
conversion, so comparing it to the candidate revision proves whether that
revision is what actually got converted. 1.0.1 stored the backup intact, 1.0.0
stored it a slash level down, and either form counts as a match. Each row reports
the verdict:

- **matches the pre-conversion backup** — proven; repair freely.
- **does not match** — the revision is not what was converted, so repairing
  would discard the difference. **Repair refuses these on every path** — the
  batch run, the per-post button and `wp uams-c2s repair` alike — and the row
  offers no button. Compare the revision against the backup by hand and decide
  what the post should hold. There is deliberately no one-click override: an
  override indistinguishable from the safe action is not an override.
- **no backup survives to check it against** — the revision is the only source.

Tools → Content → Section grows a **Needs re-converting** table listing every
affected post, with a per-post **Repair** button, a **Repair all** batch, the
revision each one would come from and whether it verifies, and whether the
damage is visible or inferred from the record. Posts with no surviving clean
revision are listed with the reason and no button; those need a database backup.
The same thing from the shell:

```bash
wp uams-c2s repair --all --dry-run   # what is damaged, and where it restores from
wp uams-c2s repair --all             # restore from revision, then convert again
```

**A damaged post is not proof this plugin damaged it.** Any code that writes
`post_content` without `wp_slash()` leaves the same signature — an import, a
REST or API update, a search-and-replace. The table therefore shows whether the
post carries a conversion record from this plugin, and an excerpt of exactly
what the detector matched, so you can tell the two apart before repairing.
Repairing a post the plugin never converted would restore it to whatever
revision predates the damage, which may not be what you want.

From 1.0.1 on, each conversion records the pre-conversion revision ID in
`_uams_c2s_converted`, so revert and repair identify it exactly rather than
inferring it.

## Safety

- Every conversion writes a WordPress revision, plus a copy of the original
  `post_content` in the `_uams_c2s_backup` post meta for one-click revert.
- Revert prefers the pre-conversion revision and falls back to that backup, and
  refuses to restore markup that shows the 1.0.0 damage signature.
- Uninstalling removes the plugin options but **keeps** the backups, so a
  revert is still possible after a reinstall.
- All admin actions are nonce-checked and require `manage_options` plus
  `edit_post` on the target.
- `kses` filtering is suspended only for the duration of the update, and only
  for users who lack `unfiltered_html` — block delimiters do not survive kses.

## Tests

```bash
php tests/test-slashing.php
```

Regression cover for the 1.0.0 slashing bug: damage detection, that
`write_content()` round-trips block markup byte-for-byte, and which source a
revert or repair picks. `tests/stubs.php` fakes just enough WordPress to load
the converter, including the fact that both `wp_update_post()` and
`add_post_meta()` unslash what they are handed. No PHPUnit, no database.

## Filters

| Filter | Purpose |
|---|---|
| `uams_c2s_default_args` | Change the default conversion options. |
| `uams_c2s_field_pairs` | Change the Content-field → Section-field mapping. |
| `uams_c2s_wysiwyg_field` | Point at a different body field. |
| `uams_c2s_statuses` | Change which post statuses are scanned. |

Field *keys* are read from the live ACF field groups (`group_uams_content`,
`group_uams_section`) at runtime, so changing the theme's `$suffix` does not
break the mapping; the `_b` keys are only a fallback.
