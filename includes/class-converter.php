<?php

declare(strict_types=1);
/**
 * Block-level and post-level conversion.
 */
// The guard runs once, as the file loads, before any test records coverage, and
// every test runs with ABSPATH defined, so no test can reach the exit branch.
// @pest-mutate-ignore: BooleanOrToBooleanAnd
defined('ABSPATH') || exit;

/**
 * Turns acf/uams-content blocks into acf/uams-section blocks.
 */
class UAMS_C2S_Converter
{
    const SOURCE_BLOCK = 'acf/uams-content';

    const TARGET_BLOCK = 'acf/uams-section';

    const BACKUP_META = '_uams_c2s_backup';

    const CONVERTED_META = '_uams_c2s_converted';

    /**
     * Signature of block markup that has lost a level of backslashes.
     *
     * serialize_block_attributes() escapes the characters < > & " and -- inside
     * a block's attribute JSON as backslash-u003c, u003e, u0026, u0022 and
     * u002d. A bare "u003c" with no backslash in front of it therefore means
     * something ran stripslashes() over the markup -- see write_content().
     */
    const DAMAGE_PATTERN = '/(?<!\\\\)u00(?:22|26|2d|3c|3e)/i';

    /**
     * Plugin versions that saved post content without slashing it.
     *
     * Anything these wrote went through wp_unslash(), whether or not
     * DAMAGE_PATTERN can see it afterwards. The pattern only catches escapes
     * whose stripped form is unambiguous; a lost backslash-n or backslash-t
     * inside an attribute value leaves a bare "n" or "t" that is
     * indistinguishable from ordinary text. A conversion record naming one of
     * these versions is therefore stronger evidence than the content is.
     *
     * @var string[]
     */
    // A constant is evaluated as the class loads, before coverage records, so the
    // driver reports this line uncovered. ConverterTest pins it through
    // wrote_unslashed('1.0.0'); the entry deleted by hand turned that test red.
    // @pest-mutate-ignore: RemoveArrayItem
    const UNSLASHED_VERSIONS = ['1.0.0'];

    /**
     * Conversion arguments.
     *
     * @var array<string,mixed>
     */
    protected $args;

    /**
     * Constructor.
     *
     * @param  array<string,mixed>  $args  Conversion arguments.
     */
    public function __construct(array $args = [])
    {
        $this->args = wp_parse_args($args, uams_c2s_default_args());
    }

    /* ---------------------------------------------------------------------
     * Content level
     * ------------------------------------------------------------------ */

    /**
     * Convert every Content block found in a post_content string.
     *
     * @param  string  $content  Post content.
     * @return array{content:string,converted:int,skipped:int,notices:string[]}
     */
    public function convert_content($content): array
    {
        $stats = [
            'converted' => 0,
            'skipped' => 0,
            'notices' => [],
        ];

        $blocks = parse_blocks($content);
        $blocks = $this->walk($blocks, $stats);

        return [
            'content' => serialize_blocks($blocks),
            'converted' => $stats['converted'],
            'skipped' => $stats['skipped'],
            'notices' => array_values(array_unique($stats['notices'])),
        ];
    }

    /**
     * Recursively convert a parsed block tree.
     *
     * @param  array[]  $blocks  Parsed blocks.
     * @param  array  $stats  Running stats, by reference.
     * @return array[]
     */
    protected function walk(array $blocks, array &$stats): array
    {
        $out = [];

        foreach ($blocks as $block) {
            if (! empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->walk($block['innerBlocks'], $stats);
            }

            if (isset($block['blockName']) && $block['blockName'] === self::SOURCE_BLOCK) {
                $converted = $this->convert_block($block, $stats);

                if ($converted !== null) {
                    $out[] = $converted;

                    continue;
                }
            }

            $out[] = $block;
        }

        return $out;
    }

    /**
     * Convert one Content block.
     *
     * @param  array  $block  Parsed block.
     * @param  array  $stats  Running stats, by reference.
     * @return array|null Null when the block was intentionally left alone.
     */
    public function convert_block(array $block, array &$stats): ?array
    {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $data = self::block_data($attrs);

        // Every Content block on every target site nests its fields under `data`,
        // which is why block_data() no longer handles anything else. If that ever
        // stops being true, say so: the block would otherwise convert into a
        // Section with no heading and no background colour, and nothing on the
        // screen would explain where they went.
        if (! isset($attrs['data'])) {
            $stats['notices'][] = __('A Content block did not store its fields under "data". Its heading and background colour could not be read, so the Section was created with the defaults. Check that block by hand.', 'uams-c2s');
        }

        $geo = $data['content_geo'] ?? '';
        $geo_region = $data['content_geo_region'] ?? [];

        $has_geo = ! empty($geo) && ! empty($geo_region);

        if ($has_geo && empty($this->args['include_geo'])) {
            $stats['skipped']++;
            $stats['notices'][] = __('Skipped a Content block with GeoTargetingWP rules. The Section block has no region filter; re-run with "include geo-targeted blocks" to convert it and drop the rules.', 'uams-c2s');

            return null;
        }

        if ($has_geo) {
            $stats['notices'][] = sprintf(
                /* translators: 1: include or exclude, 2: comma separated region list. */
                __('Region rules were dropped from a converted block (%1$s: %2$s). It now displays everywhere.', 'uams-c2s'),
                $geo,
                implode(', ', (array) $geo_region)
            );
        }

        $target_keys = UAMS_C2S_Field_Map::target_keys();
        $pairs = UAMS_C2S_Field_Map::pairs();

        $new_data = [];

        foreach ($pairs as $from => $to) {
            $value = $data[$from] ?? '';

            if ($to === 'section_hide_heading') {
                $value = $value ? 1 : 0;
            }

            if ($to === 'section_background_color' && $value === '') {
                $value = 'bg-auto';
            }

            $new_data[$to] = $value;
            $new_data['_'.$to] = $target_keys[$to] ?? '';
        }

        // Body: WYSIWYG -> inner blocks.
        $wysiwyg = UAMS_C2S_Field_Map::wysiwyg_field();
        $html = isset($data[$wysiwyg]) ? (string) $data[$wysiwyg] : '';

        $parser = new UAMS_C2S_HTML_To_Blocks($this->args);
        $inner_blocks = $parser->convert($html);

        foreach ($parser->get_notices() as $notice) {
            $stats['notices'][] = $notice;
        }

        if ($inner_blocks === []) {
            $stats['notices'][] = __('A Content block had an empty body; the Section block was created with no inner blocks.', 'uams-c2s');
        }

        $new_attrs = [
            'name' => self::TARGET_BLOCK,
            'data' => $new_data,
        ];

        // ACF generates its own block id when absent; mirror the source block's
        // convention rather than reusing its id.
        if (! empty($attrs['id'])) {
            $new_attrs['id'] = uniqid('block_');
        }

        $new_attrs['align'] = ! empty($attrs['align']) ? $attrs['align'] : 'full';
        $new_attrs['mode'] = 'preview';

        if (! empty($attrs['anchor'])) {
            $new_attrs['anchor'] = $attrs['anchor'];
        } else {
            $stats['notices'][] = __('A converted block had no custom anchor, so its rendered id changes from "content-…" to "uams-section-…". Check for in-page links pointing at it.', 'uams-c2s');
        }

        if (! empty($attrs['className'])) {
            $new_attrs['className'] = $attrs['className'];
        }

        $stats['converted']++;

        $count = count($inner_blocks);

        return [
            'blockName' => self::TARGET_BLOCK,
            'attrs' => $new_attrs,
            'innerBlocks' => $inner_blocks,
            'innerHTML' => '',
            'innerContent' => $this->inner_content_padding($count),
        ];
    }

    /**
     * Build the innerContent array core would produce for N inner blocks.
     *
     * @param  int  $count  Inner block count.
     */
    protected function inner_content_padding($count): array
    {
        $content = ["\n"];

        for ($i = 0; $i < $count; $i++) {
            $content[] = null;
            $content[] = "\n";
        }

        return $content;
    }

    /**
     * Read an ACF block's field values.
     *
     * Public and static so the scanner reads a Content block the same way the
     * converter does. They disagreed once: the scanner looked only under `data`
     * while this also handled a legacy ACF layout that stored field values at
     * the top level, so a flat-format block with region rules showed an
     * em-dash in the Geo-targeted column and was then skipped by the conversion
     * that column exists to predict.
     *
     * That tolerance is gone. All three target sites were measured on
     * 2026-08-19: 91 Content blocks remained across them, every one nesting its
     * fields under `data`, so nothing left to convert can exercise it. Anything
     * shaped otherwise now reads as empty -- and convert_block() reports it
     * rather than quietly writing a Section with no heading.
     *
     * @param  array  $attrs  Block attributes.
     * @return array<string,mixed>
     */
    public static function block_data(array $attrs): array
    {
        return (isset($attrs['data']) && is_array($attrs['data'])) ? $attrs['data'] : [];
    }

    /* ---------------------------------------------------------------------
     * Post level
     * ------------------------------------------------------------------ */
    /**
     * Convert a post without saving.
     *
     * @param  int|WP_Post  $post  Post.
     */
    public function preview_post($post): WP_Error|array
    {
        $post = get_post($post);

        if (! $post) {
            return new WP_Error('uams_c2s_no_post', __('Post not found.', 'uams-c2s'));
        }

        $result = $this->convert_content($post->post_content);
        $result['post_id'] = (int) $post->ID;
        $result['original'] = $post->post_content;

        return $result;
    }

    /**
     * Convert a post and save it.
     *
     * @param  int|WP_Post  $post  Post.
     * @return array|WP_Error
     */
    public function convert_post($post)
    {
        $post = get_post($post);
        $result = $this->preview_post($post);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result['converted'] < 1) {
            $result['updated'] = false;

            return $result;
        }

        // Note the newest revision before the update so a later revert knows
        // exactly which one holds the pre-conversion content, rather than having
        // to guess from timestamps.
        $source_revision = $this->latest_revision_id($post->ID);

        // Keep the pre-conversion content addressable for a one-click revert,
        // alongside the revision WordPress stores on update.
        //
        // add_post_meta() runs wp_unslash() on the value, so it has to be
        // slashed on the way in or the backup is stored damaged.
        if (! metadata_exists('post', $post->ID, self::BACKUP_META)) {
            add_post_meta($post->ID, self::BACKUP_META, wp_slash($result['original']), true);
        }

        $updated = $this->write_content($post->ID, $result['content']);

        if (is_wp_error($updated)) {
            return $updated;
        }

        update_post_meta(
            $post->ID,
            self::CONVERTED_META,
            [
                'time' => time(),
                'user' => get_current_user_id(),
                'converted' => $result['converted'],
                'skipped' => $result['skipped'],
                'version' => UAMS_C2S_VERSION,
                'source_revision' => $source_revision,
            ]
        );

        $result['updated'] = true;

        return $result;
    }

    /**
     * Restore a post's pre-conversion content.
     *
     * @param  int|WP_Post  $post  Post.
     * @return true|WP_Error
     */
    public function revert_post($post)
    {
        $post = get_post($post);

        if (! $post) {
            return new WP_Error('uams_c2s_no_post', __('Post not found.', 'uams-c2s'));
        }

        $source = $this->restore_source($post);

        if (is_wp_error($source)) {
            return $source;
        }

        $updated = $this->write_content($post->ID, $source['content']);

        if (is_wp_error($updated)) {
            return $updated;
        }

        delete_post_meta($post->ID, self::BACKUP_META);
        delete_post_meta($post->ID, self::CONVERTED_META);

        return true;
    }

    /**
     * Re-run a conversion that was written by a build which mangled the markup.
     *
     * Restores the pre-conversion content, drops the equally-mangled backup, and
     * converts again with the current code. The conversion options come from
     * this instance, so pass the same ones the original run used.
     *
     * @param  int|WP_Post  $post  Post.
     * @param  bool  $force  Repair even when no damage is visible, for posts
     *                       an affected version converted.
     * @return array|WP_Error
     */
    public function repair_post($post, $force = false)
    {
        $post = get_post($post);

        if (! $post) {
            return new WP_Error('uams_c2s_no_post', __('Post not found.', 'uams-c2s'));
        }

        if (! $force && ! self::looks_damaged($post->post_content)) {
            return $this->refusal($post, 'clean');
        }

        $source = $this->restore_source($post);

        if (is_wp_error($source)) {
            return $source;
        }

        // Refusing a restore that provably is not what was converted is a
        // property of the repair, not of one caller. Deciding it here means the
        // single-post button, the batch run and WP-CLI cannot disagree about it,
        // and the resolution is not computed twice to answer the same question.
        if ($source['verified'] === 'mismatch') {
            return $this->refusal($post, 'mismatch');
        }

        $restored = $this->write_content($post->ID, $source['content']);

        if (is_wp_error($restored)) {
            return $restored;
        }

        // The 1.0.0 backup went through the same unslashing as the content, so
        // it is no more trustworthy than what we just replaced. Drop both metas
        // and let the re-run write a clean pair.
        delete_post_meta($post->ID, self::BACKUP_META);
        delete_post_meta($post->ID, self::CONVERTED_META);

        $result = $this->convert_post($post->ID);

        if (is_wp_error($result)) {
            return $result;
        }

        // convert_post() reports updated => false when the restored content held
        // no Content block, having written neither a backup nor a record. Both
        // metas are already gone by then, so treating "not an error" as "repaired"
        // left the post outside the safety net while announcing success. The post
        // itself is fine -- it holds its restored pre-conversion content and will
        // reappear in the conversion queue -- but the caller has to be told.
        if (empty($result['updated'])) {
            return $this->refusal($post, 'not_converted');
        }

        $result['repaired'] = true;
        $result['source'] = $source['source'];
        $result['label'] = $source['label'];

        return $result;
    }

    /**
     * A repair that declined to act, and why.
     *
     * @param  WP_Post  $post  Post.
     * @param  string  $reason  'clean', 'mismatch' or 'not_converted'.
     * @return array{post_id:int,repaired:false,reason:string}
     */
    protected function refusal(WP_Post $post, $reason): array
    {
        return [
            'post_id' => (int) $post->ID,
            'repaired' => false,
            'reason' => $reason,
        ];
    }

    /**
     * Report what repair_post() would do, without writing anything.
     *
     * `converted` carries this plugin's own conversion record, or an empty array
     * when it has none. Damage with no record did not come from a conversion
     * this plugin performed -- anything that writes post_content without
     * slashing it leaves the same signature -- so callers must not blame it on
     * the conversion.
     *
     * @param  int|WP_Post  $post  Post.
     * @return array{post_id:int,damaged:bool,source:string,label:string,error:string,sample:string,converted:array}
     */
    public function repair_plan($post): array
    {
        $post = get_post($post);

        $plan = [
            'post_id' => $post ? (int) $post->ID : 0,
            'damaged' => false,
            'at_risk' => false,
            'source' => '',
            'label' => '',
            'verified' => '',
            'error' => '',
            'sample' => '',
            'converted' => [],
        ];

        if (! $post) {
            $plan['error'] = __('Post not found.', 'uams-c2s');

            return $plan;
        }

        $record = get_post_meta($post->ID, self::CONVERTED_META, true);
        $record = is_array($record) ? $record : [];

        $plan['converted'] = $record;
        $plan['damaged'] = self::looks_damaged($post->post_content);
        // A record with no version reads as the empty string or any other
        // non-matching string alike: wrote_unslashed() is false for both.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $plan['at_risk'] = self::wrote_unslashed($record['version'] ?? '');

        if (! $plan['damaged'] && ! $plan['at_risk']) {
            return $plan;
        }

        $plan['sample'] = self::damage_sample($post->post_content);

        $source = $this->restore_source($post);

        if (is_wp_error($source)) {
            $plan['error'] = $source->get_error_message();

            return $plan;
        }

        $plan['source'] = $source['source'];
        $plan['label'] = $source['label'];
        $plan['verified'] = $source['verified'];

        return $plan;
    }

    /* ---------------------------------------------------------------------
     * Reading and writing content safely
     * ------------------------------------------------------------------ */
    /**
     * Does this markup show the stripped-backslash signature?
     *
     * @param  string  $content  Post content.
     */
    public static function looks_damaged($content): bool
    {
        return (bool) preg_match(self::DAMAGE_PATTERN, (string) $content);
    }

    /**
     * Did this plugin version write post content without slashing it?
     *
     * @param  string  $version  Version from a conversion record.
     */
    public static function wrote_unslashed($version): bool
    {
        return in_array((string) $version, self::UNSLASHED_VERSIONS, true);
    }

    /**
     * The plugin version recorded against a post's conversion, if any.
     *
     * @param  int  $post_id  Post ID.
     * @return string Empty when this plugin has no record of converting it.
     */
    public function converted_by($post_id): string
    {
        $record = get_post_meta($post_id, self::CONVERTED_META, true);

        return (is_array($record) && ! empty($record['version'])) ? (string) $record['version'] : '';
    }

    /**
     * A short excerpt around the first stripped escape, as evidence.
     *
     * The detector cannot prove intent -- a page could in principle contain the
     * text "u003c" for innocent reasons -- so anything that reports damage should
     * show what it matched and let a human judge.
     *
     * @param  string  $content  Post content.
     * @param  int  $padding  Characters of context on each side.
     * @return string Empty when nothing matched.
     */
    public static function damage_sample($content, $padding = 60): string
    {
        $content = (string) $content;

        if (! preg_match(self::DAMAGE_PATTERN, $content, $match, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $at = $match[0][1];
        $start = max(0, $at - $padding);
        $slice = substr($content, $start, $padding * 2 + strlen($match[0][0]));

        return ($start > 0 ? '…' : '').trim($slice).'…';
    }

    /**
     * Write post content without letting WordPress chew on the block markup.
     *
     * wp_update_post() runs wp_unslash() over everything it is handed, and
     * serialize_blocks() writes backslash escapes into every block's attribute
     * JSON. Passing unslashed content therefore eats one backslash from each
     * escape and rewrites the markup as literal "u003c" text -- see
     * self::DAMAGE_PATTERN.
     *
     * @param  int  $post_id  Post ID.
     * @param  string  $content  Unslashed post content.
     * @return int|WP_Error
     */
    protected function write_content($post_id, $content)
    {
        $kses_was_active = false;

        if (! current_user_can('unfiltered_html') && has_filter('content_save_pre', 'wp_filter_post_kses')) {
            // Block markup must survive intact; kses would mangle the comments.
            kses_remove_filters();
            $kses_was_active = true;
        }

        $updated = wp_update_post(
            [
                'ID' => $post_id,
                'post_content' => wp_slash($content),
            ],
            true
        );

        if ($kses_was_active) {
            kses_init_filters();
        }

        return $updated;
    }

    /**
     * Best available copy of a post's pre-conversion content.
     *
     * A revision is preferred over the stored backup: plugin 1.0.0 handed the
     * backup to add_post_meta() unslashed, which strips the same backslashes it
     * stripped from the content, so on those posts the backup is damaged too.
     *
     * @param  WP_Post  $post  Post.
     * @return array{content:string,source:string,label:string}|WP_Error
     */
    protected function restore_source(WP_Post $post): array|WP_Error
    {
        $revision = $this->pre_conversion_revision($post);

        if ($revision) {
            return [
                'content' => $revision->post_content,
                'source' => 'revision',
                'verified' => $this->matches_backup($post->ID, $revision->post_content),
                'label' => sprintf(
                    /* translators: 1: revision ID, 2: revision date. */
                    __('revision #%1$d (%2$s GMT)', 'uams-c2s'),
                    $revision->ID,
                    $revision->post_date_gmt
                ),
            ];
        }

        if (! metadata_exists('post', $post->ID, self::BACKUP_META)) {
            return new WP_Error(
                'uams_c2s_no_backup',
                __('No pre-conversion backup or usable revision is stored for this post.', 'uams-c2s')
            );
        }

        $backup = get_post_meta($post->ID, self::BACKUP_META, true);

        // Distrust the backup on its provenance, not on how it looks. An
        // affected version stored it through add_post_meta()'s unslash, so it
        // lost a backslash level even where nothing visible survives to prove it.
        if (self::wrote_unslashed($this->converted_by($post->ID)) || self::looks_damaged($backup)) {
            return new WP_Error(
                'uams_c2s_damaged_backup',
                __('The stored backup was written by a plugin version that unslashed it, and no clean pre-conversion revision survives. Restore this post from a database backup instead.', 'uams-c2s')
            );
        }

        return [
            'content' => (string) $backup,
            'source' => 'backup',
            'verified' => 'exact',
            'label' => __('stored backup', 'uams-c2s'),
        ];
    }

    /**
     * Does a candidate restore match what was actually converted?
     *
     * A revision is only written when someone edits a post, so the newest one
     * predating a conversion can be much older than the conversion itself. That
     * is usually fine -- nothing changed in between -- but not always: anything
     * that rewrites post_content without creating a revision would leave the
     * revision stale, and restoring it would quietly throw away the difference.
     *
     * The backup meta settles it, because it was copied from post_content at the
     * moment of conversion. 1.0.1 stored it intact; 1.0.0 stored it a slash
     * level down, so either form counts as a match.
     *
     * @param  int  $post_id  Post ID.
     * @param  string  $content  Candidate content.
     * @return string 'exact', 'mismatch', or 'unknown' when there is no backup.
     */
    protected function matches_backup($post_id, $content): string
    {
        if (! metadata_exists('post', $post_id, self::BACKUP_META)) {
            return 'unknown';
        }

        $backup = (string) get_post_meta($post_id, self::BACKUP_META, true);

        if ((string) $content === $backup || wp_unslash((string) $content) === $backup) {
            return 'exact';
        }

        return 'mismatch';
    }

    /**
     * The revision holding this post's pre-conversion content, if one survives.
     *
     * Conversions from 1.0.1 onwards record the revision ID directly. Older ones
     * did not, so fall back to the newest revision that still contains a Content
     * block, is not the conversion's own revision, and is not itself damaged.
     *
     * @param  int|WP_Post  $post  Post.
     * @return WP_Post|null
     */
    public function pre_conversion_revision($post)
    {
        $post = get_post($post);

        if (! $post) {
            return null;
        }

        $meta = get_post_meta($post->ID, self::CONVERTED_META, true);
        $meta = is_array($meta) ? $meta : [];

        if (! empty($meta['source_revision'])) {
            $revision = get_post((int) $meta['source_revision']);

            // A recorded id says which revision to look at, not that the content
            // behind it is fit to restore. It gets the same content checks as a
            // revision found by searching -- a recorded id pointing at damaged or
            // already-converted markup was the one way mangled content could be
            // written back over a post while reporting success.
            if ($revision
                && $revision->post_type === 'revision'
                && (int) $revision->post_parent === (int) $post->ID
                && $this->usable_revision($revision, $post)
            ) {
                return $revision;
            }
        }

        // PHP compares an int with a numeric string numerically, and WordPress stores
        // the time as an int, so the cast changes no comparison below.
        // @pest-mutate-ignore: RemoveIntegerCast
        $cutoff = ! empty($meta['time']) ? (int) $meta['time'] : 0;

        foreach ($this->revisions($post->ID) as $revision) {
            // Anything saved after the conversion is a later edit, not a source.
            // Only the search needs this; a recorded id is captured before the
            // update and is pre-conversion by construction.
            // strtotime() reads the zone token before or after the date alike, so
            // switching the two sides of the concatenation parses the same instant.
            // @pest-mutate-ignore: ConcatSwitchSides
            if ($cutoff && strtotime($revision->post_date_gmt.' +0000') > $cutoff) {
                continue;
            }

            if (! $this->usable_revision($revision, $post)) {
                continue;
            }

            return $revision;
        }

        return null;
    }

    /**
     * Is this revision's content fit to restore over the post?
     *
     * The three content-level guards, shared by both branches of
     * pre_conversion_revision() so that neither can accept what the other would
     * reject.
     *
     * @param  WP_Post  $revision  Candidate revision.
     * @param  WP_Post  $post  Post being restored.
     * @return bool
     */
    protected function usable_revision(WP_Post $revision, WP_Post $post)
    {
        // The pre-conversion content necessarily still had a Content block.
        if (! str_contains($revision->post_content, '<!-- wp:'.self::SOURCE_BLOCK)) {
            return false;
        }

        // The conversion's own revision, when nothing was edited since.
        if ($revision->post_content === $post->post_content) {
            return false;
        }

        // Never restore markup that is already mangled.
        return ! self::looks_damaged($revision->post_content);
    }

    /**
     * A post's real revisions, newest first, with autosaves left out.
     *
     * @param  int  $post_id  Post ID.
     * @return WP_Post[]
     */
    protected function revisions($post_id): array
    {
        $revisions = wp_get_post_revisions(
            $post_id,
            [
                'order' => 'DESC',
                'orderby' => 'date ID',
            ]
        );

        $out = [];

        foreach ($revisions as $revision) {
            if (wp_is_post_autosave($revision)) {
                continue;
            }

            $out[] = $revision;
        }

        return $out;
    }

    /**
     * ID of a post's newest non-autosave revision, or 0 when it has none.
     *
     * @param  int  $post_id  Post ID.
     */
    protected function latest_revision_id($post_id): int
    {
        $revisions = $this->revisions($post_id);

        return $revisions !== [] ? (int) $revisions[0]->ID : 0;
    }
}
