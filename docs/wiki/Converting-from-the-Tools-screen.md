# Converting from the Tools screen

Written against v1.1.0.

Use the Tools screen to convert a site's posts in batches while checking the riskier ones by hand. It is at **Tools → Content → Section** and needs the `manage_options` capability.

## What the screen shows

**Site status** comes first:

| Row | What it counts |
|---|---|
| Posts still holding a Content block | The posts the list below shows. |
| Posts holding a Section block | Includes Section Blocks authored by hand, not only converted ones. |
| Conversions recorded by this plugin | Broken down by the plugin version that wrote them. |
| Pre-conversion backups stored | One per converted post, until it is reverted. |
| Posts with damaged block markup | Block attribute escapes stored as literal text. A floor, not a total. |
| Posts converted by an unslashed version | Written through the 1.0.0 bug whether or not damage is visible. |

The list of posts to convert shrinks as they convert, so these counts are what tell you whether a list that has gone quiet did so because the work is done, or because something else changed those posts.

**Conversion options** sit below it: geo-targeted blocks, headings and list markup. They are saved with *Save options* and apply to every conversion started from this screen. See [Conversion options](Conversion-options.md).

**The post list** shows every post still holding a Content Block, with its type, status, block count and whether it is geo-targeted.

## Steps

1. **Set the options** and choose *Save options*.
2. **Convert the drafts first.** Choose the button for that status, for example *Convert 45 draft*. Each status button walks only its own posts, in batches of 10, and stops there, so a draft sweep never runs on into the published pages. A *Converting…* notice counts the posts left; *Batch conversion finished.* ends it. The per-status buttons appear only when the listed posts are in more than one status; otherwise *Convert all* covers the one status there is.
3. **Walk the published posts by hand.** For each one, choose *Preview*, read *Review before converting*, then *Convert*.
4. **Use Convert all** only when every remaining post is one you are happy to convert unseen. It converts every listed post, all statuses, in batches of 10.
5. **Revert any post** whose result is wrong. A row still in the list after its conversion has a *Revert* button; a post that converted completely has left the list, so revert it with `wp uams-c2s revert <id>` or from its *Revisions* screen (see [Safety and undo](Safety-and-undo.md)).

**Note:** a *Needs re-converting* table appears only when the site holds posts that version 1.0.0 converted. See [Repairing 1.0.0 conversions](Repairing-1.0.0-conversions.md).

## Reference

- [Conversion options](Conversion-options.md)
- [How a block is converted](How-a-block-is-converted.md)
- [Safety and undo](Safety-and-undo.md)
