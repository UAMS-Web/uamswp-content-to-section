# Conversion options

Written against v1.1.0.

Three options change how a conversion is done. On the Tools screen they are under *Conversion options* and are saved with *Save options*; on the command line each is a flag on `convert` and `repair`.

Where the defaults come from depends on the path. On the Tools screen, until options are saved, they come from `uams_c2s_default_args()`, which the [`uams_c2s_default_args`](Filters.md#uams_c2s_default_args) filter can change. On WP-CLI these three always come from the flags: a flag not passed means off, off and `v3`, whatever the filter returns.

| Option | Tools screen | WP-CLI flag | Argument | Default |
|---|---|---|---|---|
| Geo-targeted blocks | *Convert blocks that use GeoTargetingWP region rules* | `--include-geo` | `include_geo` | off |
| Headings | *Demote H1/H2 found in the body to H3* | `--demote-headings` | `demote_headings` | off |
| List markup | *List markup* select | `--list-format=v3\|v2` | `list_format` | `v3` |

A fourth argument, `max_heading` (default `2`), is the heading level the Section Block renders itself. It has no control on the screen or the command line; the filter changes it on every path.

## Geo-targeted blocks

The Content Block can restrict itself to GeoTargetingWP regions; the Section Block cannot. Converting one would publish region-restricted content to everyone, so by default those blocks are left alone and reported with the note *Skipped a Content block with GeoTargetingWP rules.* Turn the option on once you have decided what should happen to them: the block converts, its rules are dropped, and a note names the rules that were dropped.

## Headings

The Section Block renders its own `<h2>`, so an `<h1>` or `<h2>` in the body breaks the page outline. With the option off, the preview flags each one. With it on, they are rewritten to `<h3>` without a note; other headings are left as they are.

## List markup

`v3` writes a `core/list` block with a `core/list-item` block per item, the markup WordPress 6.0 and later uses. `v2` writes a single `core/list` block holding the list HTML, for an editor that expects the older form.

## Example

```bash
wp uams-c2s convert --all --include-geo --demote-headings --list-format=v3
```
