<?php

declare(strict_types=1);
/**
 * Finds posts that still contain UAMS Content Blocks.
 */
// The guard runs once, as the file loads, before any test records coverage, and
// every test runs with ABSPATH defined, so no test can reach the exit branch.
// @pest-mutate-ignore: BooleanOrToBooleanAnd
defined('ABSPATH') || exit;

/**
 * Content block scanner.
 */
class UAMS_C2S_Scanner
{
    /**
     * Post statuses worth touching.
     *
     * @return string[]
     */
    public static function statuses()
    {
        /**
         * Filters the post statuses included in a scan.
         *
         * @param  string[]  $statuses  Post statuses.
         */
        return apply_filters(
            'uams_c2s_statuses',
            ['publish', 'draft', 'pending', 'future', 'private']
        );
    }

    /**
     * Narrow the scannable statuses to a requested subset.
     *
     * Intersecting rather than replacing means a status arriving from a form
     * post can only ever shrink the scan, never widen it past statuses().
     *
     * @param  string|string[]  $status  Requested status or statuses. Empty for all.
     * @return string[] Empty when nothing valid was requested.
     */
    public static function narrow_statuses($status)
    {
        $statuses = self::statuses();

        if (empty($status)) {
            return $statuses;
        }

        $requested = array_map(sanitize_key(...), (array) $status);

        return array_values(array_intersect($statuses, $requested));
    }

    /**
     * How many posts holding a Content block sit in each post status.
     *
     * Drives the per-status batch buttons: converting every draft in one go is
     * a different risk from converting every published page, so the screen has
     * to be able to offer them separately.
     *
     * @return array<string,int> Status slug => count, statuses with none omitted.
     */
    public static function status_counts(): array
    {
        $counts = [];

        foreach (self::statuses() as $status) {
            $found = count(self::find(['status' => $status]));

            if ($found > 0) {
                $counts[$status] = $found;
            }
        }

        return $counts;
    }

    /**
     * IDs of posts containing at least one Content block.
     *
     * @param  array<string,mixed>  $args  {
     *                                     Optional. Query arguments.
     *
     * @type string $post_type Restrict to one post type.
     * @type int $limit     Maximum rows. 0 for all.
     * @type int $offset    Offset.
     * @type int[] $exclude   Post IDs to skip.
     *             }
     *
     * @return int[]
     */
    public static function find(array $args = []): array
    {
        global $wpdb;

        $args = wp_parse_args(
            $args,
            [
                'post_type' => '',
                'status' => '',
                // A default limit of -1 is as unlimited as 0: only a limit above zero adds a LIMIT.
                // @pest-mutate-ignore: DecrementInteger
                'limit' => 0,
                'offset' => 0,
            ]
        );

        $statuses = self::narrow_statuses($args['status']);

        if (! $statuses) {
            return [];
        }

        // implode() ignores the keys, so the start index of array_fill() cannot show in
        // the result: the mutants that move it are equivalent.
        // @pest-mutate-ignore: IncrementInteger, DecrementInteger
        $status_slots = implode(', ', array_fill(0, count($statuses), '%s'));

        $needle = '%'.$wpdb->esc_like('<!-- wp:'.UAMS_C2S_Converter::SOURCE_BLOCK).'%';

        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ( {$status_slots} )";
        $params = array_merge([$needle], $statuses);

        if ($args['post_type']) {
            $sql .= ' AND post_type = %s';
            $params[] = $args['post_type'];
        }

        if (! empty($args['exclude'])) {
            $exclude = array_map(absint(...), (array) $args['exclude']);
            // implode() ignores the keys, so the start index of array_fill() cannot show in
            // the result: the mutants that move it are equivalent.
            // @pest-mutate-ignore: IncrementInteger, DecrementInteger
            $exclude_slots = implode(', ', array_fill(0, count($exclude), '%d'));
            $sql .= " AND ID NOT IN ( {$exclude_slots} )";
            $params = array_merge($params, $exclude);
        }

        $sql .= ' ORDER BY post_type ASC, ID ASC';

        if ($args['limit'] > 0) {
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = (int) $args['limit'];
            $params[] = (int) $args['offset'];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
        $ids = $wpdb->get_col($wpdb->prepare($sql, $params));

        return array_map(absint(...), (array) $ids);
    }

    /**
     * Count posts whose content contains a literal string.
     *
     * @param  string  $needle  Substring to look for.
     */
    public static function count_containing($needle): int
    {
        global $wpdb;

        $statuses = self::statuses();
        // implode() ignores the keys, so the start index of array_fill() cannot show in
        // the result: the mutants that move it are equivalent.
        // @pest-mutate-ignore: IncrementInteger, DecrementInteger
        $status_slots = implode(', ', array_fill(0, count($statuses), '%s'));

        $params = array_merge(
            ['%'.$wpdb->esc_like($needle).'%'],
            $statuses
        );

        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ( {$status_slots} )";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * A whole-site picture of what the conversion has and has not touched.
     *
     * Exists so the question "the list said 60 and now says 0 -- what happened?"
     * can be answered from the Tools screen, without shell access.
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        global $wpdb;

        $records = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
                UAMS_C2S_Converter::CONVERTED_META
            )
        );

        $backups = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                UAMS_C2S_Converter::BACKUP_META
            )
        );

        $last = 0;
        $blocks = 0;
        $versions = [];

        foreach ((array) $records as $raw) {
            $record = maybe_unserialize($raw);

            if (! is_array($record)) {
                continue;
            }

            if (! empty($record['time'])) {
                $last = max($last, (int) $record['time']);
            }

            $version = ! empty($record['version']) ? (string) $record['version'] : '?';

            $versions[$version] = isset($versions[$version]) ? $versions[$version] + 1 : 1;

            $blocks += isset($record['converted']) ? (int) $record['converted'] : 0;
        }

        ksort($versions);

        return [
            'content' => self::count_containing('<!-- wp:'.UAMS_C2S_Converter::SOURCE_BLOCK),
            'section' => self::count_containing('<!-- wp:'.UAMS_C2S_Converter::TARGET_BLOCK),
            'conversions' => count((array) $records),
            'blocks' => $blocks,
            'backups' => $backups,
            'damaged' => count(self::find_damaged()),
            'at_risk' => count(self::find_at_risk()),
            'last' => $last,
            'versions' => $versions,
        ];
    }

    /**
     * IDs of posts converted by a plugin version that did not slash its writes.
     *
     * Membership is decided by the conversion record, not by the content: a
     * lost backslash-n leaves no signature the content scanner can recognise,
     * so a post can be damaged and still look clean. The record is the only
     * reliable witness.
     *
     * @return int[]
     */
    public static function find_at_risk(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC",
                UAMS_C2S_Converter::CONVERTED_META
            )
        );

        $ids = [];

        foreach ((array) $rows as $row) {
            $record = maybe_unserialize($row->meta_value);

            if (! is_array($record) || empty($record['version'])) {
                continue;
            }

            if (UAMS_C2S_Converter::wrote_unslashed($record['version'])) {
                $ids[] = (int) $row->post_id;
            }
        }

        return $ids;
    }

    /**
     * IDs of posts whose block markup lost a level of backslashes.
     *
     * Plugin 1.0.0 saved converted content without wp_slash(), so every block
     * attribute escape on the page came back as literal "u003c" text. The LIKE
     * is a cheap prefilter; the regex in the converter is the real test, since
     * only it can tell a stripped escape from an intact one.
     *
     * @return int[]
     */
    public static function find_damaged(): array
    {
        global $wpdb;

        $statuses = self::statuses();
        // implode() ignores the keys, so the start index of array_fill() cannot show in
        // the result: the mutants that move it are equivalent.
        // @pest-mutate-ignore: IncrementInteger, DecrementInteger
        $status_slots = implode(', ', array_fill(0, count($statuses), '%s'));

        $markers = ['u0022', 'u0026', 'u002d', 'u003c', 'u003e'];
        // implode() ignores the keys, so the start index of array_fill() cannot show in
        // the result: the mutants that move it are equivalent.
        // @pest-mutate-ignore: IncrementInteger, DecrementInteger
        $clauses = implode(' OR ', array_fill(0, count($markers), 'post_content LIKE %s'));

        $params = [];

        foreach ($markers as $marker) {
            $params[] = '%'.$wpdb->esc_like($marker).'%';
        }

        $params = array_merge($params, $statuses);

        // Select the content the predicate needs rather than re-fetching it per
        // row. The markers are substrings of the *correctly* slashed form too, so
        // every post this plugin has successfully converted is a candidate -- the
        // prefilter's cost grows with the plugin's success, and a get_post() per
        // candidate made that 1 + N queries, twice per Tools render.
        $sql = "SELECT ID, post_content FROM {$wpdb->posts} WHERE ( {$clauses} ) AND post_status IN ( {$status_slots} ) ORDER BY post_type ASC, ID ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

        $damaged = [];

        foreach ((array) $rows as $row) {
            if (UAMS_C2S_Converter::looks_damaged($row->post_content)) {
                $damaged[] = (int) $row->ID;
            }
        }

        return $damaged;
    }

    /**
     * Summarise one post: how many blocks, and whether any are geo-targeted.
     *
     * @param  int|WP_Post  $post  Post.
     * @return array{blocks:int,geo:int}
     */
    public static function summarize($post): array
    {
        $post = get_post($post);

        $summary = [
            'blocks' => 0,
            'geo' => 0,
        ];

        if (! $post) {
            return $summary;
        }

        self::tally(parse_blocks($post->post_content), $summary);

        return $summary;
    }

    /**
     * Walk a block tree counting Content blocks.
     *
     * @param  array[]  $blocks  Parsed blocks.
     * @param  array{blocks:int,geo:int}  $summary  Running tally, by reference.
     */
    protected static function tally(array $blocks, array &$summary)
    {
        foreach ($blocks as $block) {
            if (isset($block['blockName']) && $block['blockName'] === UAMS_C2S_Converter::SOURCE_BLOCK) {
                $summary['blocks']++;

                // Read through the converter so this column predicts what the
                // conversion will actually do. Reading only `data` here meant a
                // flat-format block with region rules reported no geo targeting
                // and was then skipped.
                $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
                $data = UAMS_C2S_Converter::block_data($attrs);

                if (! empty($data['content_geo']) && ! empty($data['content_geo_region'])) {
                    $summary['geo']++;
                }
            }

            if (! empty($block['innerBlocks'])) {
                self::tally($block['innerBlocks'], $summary);
            }
        }
    }
}
