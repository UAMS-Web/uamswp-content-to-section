# Converting in the editor

Written against v1.1.0.

Use the editor transform to convert one Content Block while you are already editing its post.

1. **Open the post in the block editor** and select a UAMS Content Block.
2. **In the block toolbar, choose Transform to → UAMS Section.** The block is replaced by a Section Block holding the same heading, hide-heading and background settings, with its body as inner blocks.
3. **Check the inner blocks**, then save the post as usual.

This path uses the editor's own `rawHandler`, the same code that runs when HTML is pasted into the canvas, so the result matches what an editor would get by hand. It can differ in detail from the Tools screen and WP-CLI, which use the plugin's own parser (see [How a block is converted](How-a-block-is-converted.md)).

**Warning:** the transform ignores the geo-targeted option. A Content Block with region rules converts anyway and its rules are dropped, with only a warning in the browser console. Check a block's region settings before transforming it.

**Note:** the editor transform does not write the plugin's backup or conversion record, so the Tools screen's *Revert* does not apply to it. Undo it with the editor's undo before saving, or restore the post's previous revision afterwards.

## Reference

- [How a block is converted](How-a-block-is-converted.md)
- [Safety and undo](Safety-and-undo.md)
