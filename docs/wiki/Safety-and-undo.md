# Safety and undo

Written against v1.1.0.

What the plugin keeps so a conversion can be undone, who may run one, and what uninstalling leaves behind.

| Safeguard | What it does |
|---|---|
| Backup meta | A copy of the original `post_content` is stored in the `_uams_c2s_backup` post meta before the conversion is written. |
| Revision | The conversion is saved through `wp_update_post()`, so WordPress keeps its usual revision of the converted content. The revision that held the original is whichever one was saved before the conversion, and a post that was never revised has none. |
| Conversion record | `_uams_c2s_converted` records the conversion, the plugin version that wrote it and, from 1.0.1 on, the ID of the revision saved before it, so revert and repair find it exactly rather than inferring it. |
| Permissions | Every admin action is nonce-checked and requires `manage_options`. A single-post action also requires `edit_post` on that post; a batch skips posts the user cannot edit. |
| `kses` | Filtering is suspended only for the duration of the update, and only for users who lack `unfiltered_html`, because block delimiters do not survive `kses`. |
| Uninstall | Removes the plugin's options but **keeps** the backups, so a revert is still possible after a reinstall. |

## How a revert restores

A revert prefers the revision saved before the conversion and falls back to the backup meta. It refuses to restore markup that shows the 1.0.0 damage signature, and the backup is refused outright when an affected version wrote it (see [Repairing 1.0.0 conversions](Repairing-1.0.0-conversions.md)).

Revert a post with `wp uams-c2s revert <id>` (see [WP-CLI commands](WP-CLI-commands.md#revert)), or with the *Revert* button on a Tools screen row that is still listed. A post that converted completely leaves the Tools screen list, so it has no button there; the command, or the post's *Revisions* screen, restores it.

A conversion made with the editor transform writes no backup or record; undo it in the editor, or restore the previous revision.
