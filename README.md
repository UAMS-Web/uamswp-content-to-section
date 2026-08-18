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

Drop the `uams-content-to-section` directory into `wp-content/plugins/` and
activate it. No build step, no Composer dependencies, no options to configure
before first use.

It needs the UAMS 2020 theme (or wherever the two blocks are registered) and
ACF Pro active to *render* the result, but it will parse and rewrite content
without them.

## Three ways to run it

### 1. Tools → Content → Section

Lists every post, page and CPT entry still holding a Content Block, with a
per-post block count and a geo-targeting flag.

- **Preview** — a dry run showing the before/after `post_content` side by side,
  plus a list of anything worth eyeballing before you commit.
- **Convert** — converts one post.
- **Convert all** — walks the whole site in batches of 10.
- **Revert** — restores the exact pre-conversion content.

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
wp uams-c2s convert 42 108 --demote-headings

# Undo
wp uams-c2s revert 42
wp uams-c2s revert --all
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

## Safety

- Every conversion writes a WordPress revision, plus a copy of the original
  `post_content` in the `_uams_c2s_backup` post meta for one-click revert.
- Uninstalling removes the plugin options but **keeps** the backups, so a
  revert is still possible after a reinstall.
- All admin actions are nonce-checked and require `manage_options` plus
  `edit_post` on the target.
- `kses` filtering is suspended only for the duration of the update, and only
  for users who lack `unfiltered_html` — block delimiters do not survive kses.

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
