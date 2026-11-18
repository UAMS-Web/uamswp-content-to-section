# Your first conversion

Written against v1.1.0.

At the end of this page you will have converted one post from Content Blocks to Section Blocks, checked the result on the site, and know how to put it back.

1. **Install and activate the plugin**, as the [README](https://github.com/UAMS-Web/uamswp-content-to-section#installing) describes. A new item appears under *Tools*: **Content → Section**.
2. **Open Tools → Content → Section.** The *Site status* table at the top counts the posts that still hold a Content Block. Below it, every post, page and custom post type entry that holds one is listed with its block count and a geo-targeting flag.
3. **Pick a post and choose Preview.** The preview is a dry run: it shows the post's `post_content` before and after, side by side, and lists anything worth checking under *Review before converting*. Nothing is saved.
4. **Read the review list.** It names blocks that were skipped (see [geo-targeted blocks](Conversion-options.md#geo-targeted-blocks)), anchors that will change, and headings that break the page outline. Change the [conversion options](Conversion-options.md) and preview again if a note asks for it.
5. **Choose Convert.** The notice *Post converted.* appears, and the post leaves the list. The plugin saved a WordPress revision and a copy of the original content first (see [Safety and undo](Safety-and-undo.md)).
6. **Open the page on the site** and check it. Before converting, the row's *View on site* link (*Preview on site* for a post that is not published) opens it in a new tab; keep that tab and reload it after converting. The Section Block needs the UAMS 2020 theme and ACF Pro active to render.
7. **If the result is wrong, revert it** with `wp uams-c2s revert <id>` (see [WP-CLI commands](WP-CLI-commands.md#revert)), or restore the revision saved before the conversion from the editor's *Revisions* screen. The post then holds exactly what it held before.

**Note:** the Tools screen offers *Revert* only on a row that is still listed, which is a post that still holds a Content Block after its conversion (one whose geo-targeted blocks were skipped, for example). A post that converted completely leaves the list, and with it the button.

**Note:** once a post converts it leaves the list, so a list that goes quiet does not on its own mean the work is done. The *Site status* counts say whether it is.

## Next

- [Converting from the Tools screen](Converting-from-the-Tools-screen.md) for batches by post status.
- [Converting with WP-CLI](Converting-with-WP-CLI.md) for the same work from the shell.
- [How a block is converted](How-a-block-is-converted.md) for what the preview is showing you.
