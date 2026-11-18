# WP-CLI commands

Written against v1.1.0.

The plugin registers one command, `wp uams-c2s`, with four subcommands. Each one needs the plugin active on the site WP-CLI is pointed at.

## scan

Lists posts that still contain a UAMS Content Block.

| Flag | Default | Effect |
|---|---|---|
| `--post_type=<post_type>` | every type | Restrict the scan to one post type. |
| `--format=<format>` | `table` | `table`, `csv`, `json`, `ids` (space-separated IDs) or `count`. |

```bash
wp uams-c2s scan --post_type=page --format=ids
```

## convert

Converts Content Blocks into Section Blocks.

| Argument or flag | Default | Effect |
|---|---|---|
| `<id>...` | none | One or more post IDs. Omit and pass `--all` instead. |
| `--all` | off | Convert every post that contains a Content Block. |
| `--post_type=<post_type>` | every type | With `--all`, restrict to one post type. |
| `--post_status=<post_status>` | every scanned status | With `--all`, restrict to `draft`, `publish`, `pending`, `future` or `private`. Comma-separate for several. |
| `--dry-run` | off | Report what would change without saving. |
| `--include-geo` | off | Also convert geo-targeted blocks, dropping their region rules. |
| `--demote-headings` | off | Demote H1 and H2 in the body to H3. |
| `--list-format=<format>` | `v3` | `v3` (`core/list` with `core/list-item`) or `v2` (a single `core/list` block). |
| `--verbose` | off | Print every conversion note. |

```bash
wp uams-c2s convert --all --include-geo --demote-headings
```

The flags are described in [Conversion options](Conversion-options.md).

## revert

Restores posts to their pre-conversion content.

| Argument or flag | Default | Effect |
|---|---|---|
| `<id>...` | none | One or more post IDs. Omit and pass `--all` instead. |
| `--all` | off | Revert every post that holds a pre-conversion backup. |

A post that cannot be reverted prints a warning naming it and the reason, and the rest continue. See [Safety and undo](Safety-and-undo.md) for which source a revert restores from.

## repair

Re-runs conversions that version 1.0.0 saved without slashing the markup. It restores each post from the revision saved before its conversion, then converts it again.

| Argument or flag | Default | Effect |
|---|---|---|
| `<id>...` | none | One or more post IDs. Omit and pass `--all` instead. |
| `--all` | off | Repair every damaged post on the site. |
| `--dry-run` | off | Report what would be repaired, and from where, without saving. |
| `--include-geo` | off | As for `convert`. |
| `--demote-headings` | off | As for `convert`. |
| `--list-format=<format>` | `v3` | As for `convert`. |

```bash
wp uams-c2s repair --all --dry-run
```

See [Repairing 1.0.0 conversions](Repairing-1.0.0-conversions.md).
