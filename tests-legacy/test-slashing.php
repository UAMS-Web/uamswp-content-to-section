<?php
/**
 * Regression cover for the 1.0.0 slashing bug.
 *
 * Run with: php tests/test-slashing.php
 *
 * 1.0.0 handed serialized block markup to wp_update_post() unslashed. Core
 * unslashes what it is given, so every backslash escape that
 * serialize_block_attributes() writes into a block's attribute JSON lost its
 * backslash and the attributes were rewritten as literal "u003c" text.
 *
 * @package UAMS_Content_To_Section
 */

require_once __DIR__ . '/stubs.php';

/**
 * Core's serialize_block_attributes(), copied so the fixtures below are real
 * block markup rather than hand-typed escapes.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function uams_c2s_test_serialize_attributes( array $attributes ) {
	$json = json_encode( $attributes, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

	$json = preg_replace( '/--/', '\\\\u002d\\\\u002d', $json );
	$json = preg_replace( '/</', '\\\\u003c', $json );
	$json = preg_replace( '/>/', '\\\\u003e', $json );
	$json = preg_replace( '/&/', '\\\\u0026', $json );
	$json = preg_replace( '/\\\\"/', '\\\\u0022', $json );

	return $json;
}

$cta_attrs = array(
	'name' => 'acf/uams-cta-bar',
	'data' => array(
		'cta_bar_text' => "<p style=\"text-align: center\">UAMS College of Medicine -- Northwest\r\nFayetteville, AR &amp; beyond</p>",
	),
);

$cta_block  = '<!-- wp:acf/uams-cta-bar ' . uams_c2s_test_serialize_attributes( $cta_attrs ) . ' /-->';
$source     = '<!-- wp:acf/uams-content {"data":{"content_heading":"Program"}} /-->' . $cta_block;
$converted  = '<!-- wp:acf/uams-section {"data":{"section_heading":"Program"}} /-->' . $cta_block;
$mangled    = wp_unslash( $converted );

/* -------------------------------------------------------------------------
 * Damage detection
 * ---------------------------------------------------------------------- */

echo "-- damage detection\n";

uams_c2s_test_is(
	'intact block markup is not flagged',
	UAMS_C2S_Converter::looks_damaged( $converted ),
	false
);

uams_c2s_test_is(
	'markup that lost a slash level is flagged',
	UAMS_C2S_Converter::looks_damaged( $mangled ),
	true
);

uams_c2s_test_is(
	'plain paragraph markup is not flagged',
	UAMS_C2S_Converter::looks_damaged( '<!-- wp:paragraph --><p>Hello &amp; goodbye.</p><!-- /wp:paragraph -->' ),
	false
);

uams_c2s_test_is(
	'a wp_slash/wp_unslash round trip is not flagged',
	UAMS_C2S_Converter::looks_damaged( wp_unslash( wp_slash( $converted ) ) ),
	false
);

/* -------------------------------------------------------------------------
 * write_content()
 * ---------------------------------------------------------------------- */

echo "\n-- write_content()\n";

uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 1 ) );

$converter = new UAMS_C2S_Test_Converter();
$converter->write( 1, $converted );

uams_c2s_test_is( 'stored markup is byte-identical', get_post( 1 )->post_content, $converted );
uams_c2s_test_is( 'stored markup is not damaged', UAMS_C2S_Converter::looks_damaged( get_post( 1 )->post_content ), false );

/* -------------------------------------------------------------------------
 * Choosing what to restore from
 * ---------------------------------------------------------------------- */

echo "\n-- restore source\n";

// A post converted by 1.0.0: current content mangled, the conversion's own
// revision mangled, and the backup meta mangled by add_post_meta()'s unslash.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 1, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 10, 'post_type' => 'revision', 'post_parent' => 1, 'post_content' => $source, 'post_date_gmt' => '2026-08-18 20:00:00' ) );
uams_c2s_test_post( array( 'ID' => 11, 'post_type' => 'revision', 'post_parent' => 1, 'post_content' => $mangled, 'post_date_gmt' => '2026-08-18 21:00:00' ) );
add_post_meta( 1, UAMS_C2S_Converter::BACKUP_META, $source ); // Unslashed, as 1.0.0 did.
update_post_meta(
	1,
	UAMS_C2S_Converter::CONVERTED_META,
	array( 'time' => strtotime( '2026-08-18 21:00:05 +0000' ), 'version' => '1.0.0' )
);

$converter = new UAMS_C2S_Test_Converter();
$restore   = $converter->source( 1 );

uams_c2s_test_is( '1.0.0 damage: prefers a revision over the backup', $restore['source'], 'revision' );
uams_c2s_test_is( '1.0.0 damage: picks the pre-conversion revision', $restore['label'], 'revision #10 (2026-08-18 20:00:00 GMT)' );
uams_c2s_test_is( '1.0.0 damage: what it restores is intact', UAMS_C2S_Converter::looks_damaged( $restore['content'] ), false );
uams_c2s_test_is( '1.0.0 damage: the backup really was damaged', UAMS_C2S_Converter::looks_damaged( get_post_meta( 1, UAMS_C2S_Converter::BACKUP_META, true ) ), true );

// No revision survives and the only backup is the damaged one.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 2, 'post_content' => $mangled ) );
add_post_meta( 2, UAMS_C2S_Converter::BACKUP_META, $source );

$converter = new UAMS_C2S_Test_Converter();
$restore   = $converter->source( 2 );

uams_c2s_test_is( 'damaged backup, no revision: refuses', is_wp_error( $restore ), true );
uams_c2s_test_is( 'damaged backup, no revision: says why', $restore->get_error_code(), 'uams_c2s_damaged_backup' );

// A 1.0.1 conversion records its source revision ID, so there is no guessing.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 3, 'post_content' => $converted ) );
uams_c2s_test_post( array( 'ID' => 30, 'post_type' => 'revision', 'post_parent' => 3, 'post_content' => $source, 'post_date_gmt' => '2026-08-19 10:00:00' ) );
uams_c2s_test_post( array( 'ID' => 31, 'post_type' => 'revision', 'post_parent' => 3, 'post_content' => $converted, 'post_date_gmt' => '2026-08-19 11:00:00' ) );
update_post_meta(
	3,
	UAMS_C2S_Converter::CONVERTED_META,
	array( 'time' => strtotime( '2026-08-19 11:00:00 +0000' ), 'version' => '1.0.1', 'source_revision' => 30 )
);

$converter = new UAMS_C2S_Test_Converter();
$restore   = $converter->source( 3 );

uams_c2s_test_is( '1.0.1: uses the recorded source revision', $restore['label'], 'revision #30 (2026-08-19 10:00:00 GMT)' );

// No revisions at all, but a clean backup written by 1.0.1.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 4, 'post_content' => $converted ) );
add_post_meta( 4, UAMS_C2S_Converter::BACKUP_META, wp_slash( $source ) );

$converter = new UAMS_C2S_Test_Converter();
$restore   = $converter->source( 4 );

uams_c2s_test_is( 'no revisions, clean backup: falls back to it', $restore['source'], 'backup' );
uams_c2s_test_is( 'no revisions, clean backup: content intact', $restore['content'], $source );

// Nothing stored anywhere.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 5, 'post_content' => $converted ) );

$converter = new UAMS_C2S_Test_Converter();
$restore   = $converter->source( 5 );

uams_c2s_test_is( 'nothing stored: refuses', $restore->get_error_code(), 'uams_c2s_no_backup' );

// Autosaves and post-conversion edits must not be mistaken for the source.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 6, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 60, 'post_type' => 'revision', 'post_parent' => 6, 'post_content' => $source, 'post_date_gmt' => '2026-08-18 20:00:00' ) );
uams_c2s_test_post( array( 'ID' => 61, 'post_type' => 'revision', 'post_parent' => 6, 'post_name' => '6-autosave-v1', 'post_content' => $source . '<!-- autosave -->', 'post_date_gmt' => '2026-08-18 20:30:00' ) );
uams_c2s_test_post( array( 'ID' => 62, 'post_type' => 'revision', 'post_parent' => 6, 'post_content' => $source . '<!-- later edit -->', 'post_date_gmt' => '2026-08-19 09:00:00' ) );
update_post_meta(
	6,
	UAMS_C2S_Converter::CONVERTED_META,
	array( 'time' => strtotime( '2026-08-18 21:00:00 +0000' ), 'version' => '1.0.0' )
);

$converter = new UAMS_C2S_Test_Converter();
$restore   = $converter->source( 6 );

uams_c2s_test_is( 'skips autosaves and later edits', $restore['label'], 'revision #60 (2026-08-18 20:00:00 GMT)' );

/* -------------------------------------------------------------------------
 * Attribution: damage without a conversion record is not this plugin's doing
 * ---------------------------------------------------------------------- */

echo "\n-- repair_plan attribution\n";

// Damaged, but nothing here ever ran a conversion.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 7, 'post_content' => $mangled ) );

$converter = new UAMS_C2S_Test_Converter();
$plan      = $converter->repair_plan( 7 );

uams_c2s_test_is( 'no conversion record: still reported damaged', $plan['damaged'], true );
uams_c2s_test_is( 'no conversion record: attribution left empty', $plan['converted'], array() );
uams_c2s_test_is( 'no conversion record: shows what matched', false !== strpos( $plan['sample'], 'u003c' ), true );

// Damaged and converted by this plugin.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 8, 'post_content' => $mangled ) );
update_post_meta( 8, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => 1, 'version' => '1.0.0' ) );

$converter = new UAMS_C2S_Test_Converter();
$plan      = $converter->repair_plan( 8 );

uams_c2s_test_is( 'conversion record is carried through', $plan['converted']['version'], '1.0.0' );

// A clean post reports nothing at all.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 9, 'post_content' => $converted ) );

$converter = new UAMS_C2S_Test_Converter();
$plan      = $converter->repair_plan( 9 );

uams_c2s_test_is( 'clean post: not damaged', $plan['damaged'], false );
uams_c2s_test_is( 'clean post: no sample', $plan['sample'], '' );

uams_c2s_test_is( 'damage_sample() on clean markup is empty', UAMS_C2S_Converter::damage_sample( $converted ), '' );

/* -------------------------------------------------------------------------
 * Damage the content scanner cannot see
 *
 * DAMAGE_PATTERN only recognises escapes whose stripped form is unmistakable.
 * A lost backslash-n leaves a bare "n" that reads as ordinary text, so a post
 * an affected version converted can look perfectly clean and still be wrong.
 * ---------------------------------------------------------------------- */

echo "\n-- invisible damage\n";

// An attribute whose only escape is a newline: nothing survives to detect.
$newline_attrs   = array( 'name' => 'acf/uams-cta-bar', 'data' => array( 'address' => "1125 North College Avenue\r\nFayetteville, AR 72703" ) );
$newline_block   = '<!-- wp:acf/uams-cta-bar ' . uams_c2s_test_serialize_attributes( $newline_attrs ) . ' /-->';
$newline_mangled = wp_unslash( $newline_block );

uams_c2s_test_is( 'newline-only damage really is invisible', UAMS_C2S_Converter::looks_damaged( $newline_mangled ), false );
uams_c2s_test_is( 'but the content really did change', $newline_mangled === $newline_block, false );

uams_c2s_test_is( 'v1.0.0 is a known unslashed version', UAMS_C2S_Converter::wrote_unslashed( '1.0.0' ), true );
uams_c2s_test_is( 'v1.0.1 is not', UAMS_C2S_Converter::wrote_unslashed( '1.0.1' ), false );
uams_c2s_test_is( 'no version is not', UAMS_C2S_Converter::wrote_unslashed( '' ), false );

// Clean-looking, but the record says an affected version wrote it.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 10, 'post_content' => $newline_mangled ) );
uams_c2s_test_post( array( 'ID' => 100, 'post_type' => 'revision', 'post_parent' => 10, 'post_content' => $source, 'post_date_gmt' => '2026-08-19 10:00:00' ) );
add_post_meta( 10, UAMS_C2S_Converter::BACKUP_META, $source );
update_post_meta( 10, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => strtotime( '2026-08-19 11:00:00 +0000' ), 'version' => '1.0.0' ) );

$converter = new UAMS_C2S_Test_Converter();
$plan      = $converter->repair_plan( 10 );

uams_c2s_test_is( 'invisible damage: not flagged by content', $plan['damaged'], false );
uams_c2s_test_is( 'invisible damage: flagged by its record', $plan['at_risk'], true );
uams_c2s_test_is( 'invisible damage: still has a restore source', $plan['label'], 'revision #100 (2026-08-19 10:00:00 GMT)' );

// The backup is refused on provenance even though it looks clean.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 11, 'post_content' => $newline_mangled ) );
add_post_meta( 11, UAMS_C2S_Converter::BACKUP_META, $source );
update_post_meta( 11, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => 1, 'version' => '1.0.0' ) );

$converter = new UAMS_C2S_Test_Converter();
$restore   = $converter->source( 11 );

uams_c2s_test_is( 'v1.0.0 backup refused on provenance', $restore->get_error_code(), 'uams_c2s_damaged_backup' );

// A v1.0.1 backup that looks clean is trusted.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 12, 'post_content' => $converted ) );
add_post_meta( 12, UAMS_C2S_Converter::BACKUP_META, wp_slash( $source ) );
update_post_meta( 12, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => 1, 'version' => '1.0.1' ) );

$converter = new UAMS_C2S_Test_Converter();
$restore   = $converter->source( 12 );

uams_c2s_test_is( 'v1.0.1 backup is trusted', $restore['source'], 'backup' );

// repair_post() declines a clean post unless forced.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 13, 'post_content' => $newline_mangled ) );

$converter = new UAMS_C2S_Test_Converter();
$result    = $converter->repair_post( 13 );

uams_c2s_test_is( 'unforced repair of a clean-looking post declines', $result['reason'], 'clean' );

/* -------------------------------------------------------------------------
 * Verifying the restore against the backup
 *
 * A revision is only written when someone edits, so the newest one predating a
 * conversion can be far older than the conversion. The backup was copied from
 * post_content at the moment of conversion, so it settles whether the revision
 * really is what got converted.
 * ---------------------------------------------------------------------- */

echo "\n-- restore verification\n";

// v1.0.0: backup stored a slash level down, revision intact. Still a match.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 20, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 200, 'post_type' => 'revision', 'post_parent' => 20, 'post_content' => $source, 'post_date_gmt' => '2025-08-27 19:31:56' ) );
add_post_meta( 20, UAMS_C2S_Converter::BACKUP_META, $source ); // Unslashed, as 1.0.0 did.
update_post_meta( 20, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => strtotime( '2026-08-18 21:51:00 +0000' ), 'version' => '1.0.0' ) );

$converter = new UAMS_C2S_Test_Converter();
$plan      = $converter->repair_plan( 20 );

uams_c2s_test_is( 'year-old revision still verifies against a 1.0.0 backup', $plan['verified'], 'exact' );

// The post changed after its last revision but before the conversion.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 21, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 210, 'post_type' => 'revision', 'post_parent' => 21, 'post_content' => $source, 'post_date_gmt' => '2025-08-27 19:31:56' ) );
add_post_meta( 21, UAMS_C2S_Converter::BACKUP_META, $source . '<!-- wp:paragraph --><p>Added without a revision.</p><!-- /wp:paragraph -->' );
update_post_meta( 21, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => strtotime( '2026-08-18 21:51:00 +0000' ), 'version' => '1.0.0' ) );

$converter = new UAMS_C2S_Test_Converter();
$plan      = $converter->repair_plan( 21 );

uams_c2s_test_is( 'a stale revision is caught as a mismatch', $plan['verified'], 'mismatch' );
uams_c2s_test_is( 'a mismatch still names its candidate', $plan['label'], 'revision #210 (2025-08-27 19:31:56 GMT)' );

// No backup left to check against.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 22, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 220, 'post_type' => 'revision', 'post_parent' => 22, 'post_content' => $source, 'post_date_gmt' => '2025-08-27 19:31:56' ) );

$converter = new UAMS_C2S_Test_Converter();
$plan      = $converter->repair_plan( 22 );

uams_c2s_test_is( 'no backup: verification is unknown, not a match', $plan['verified'], 'unknown' );

// A clean v1.0.1 post is not in the repair queue at all, so repair_plan()
// returns before verifying anything. The restore path still verifies, which is
// what a plain revert goes through.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 23, 'post_content' => $converted ) );
uams_c2s_test_post( array( 'ID' => 230, 'post_type' => 'revision', 'post_parent' => 23, 'post_content' => $source, 'post_date_gmt' => '2026-08-19 10:00:00' ) );
add_post_meta( 23, UAMS_C2S_Converter::BACKUP_META, wp_slash( $source ) );
update_post_meta( 23, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => strtotime( '2026-08-19 11:00:00 +0000' ), 'version' => '1.0.1', 'source_revision' => 230 ) );

$converter = new UAMS_C2S_Test_Converter();

uams_c2s_test_is( 'clean v1.0.1 post is not queued for repair', $converter->repair_plan( 23 )['verified'], '' );
uams_c2s_test_is( 'v1.0.1 backup verifies its revision too', $converter->source( 23 )['verified'], 'exact' );

/* -------------------------------------------------------------------------
 * Batch scope narrowing
 *
 * The scope arrives from a form post and decides how many published pages a
 * click rewrites, so it has to be incapable of widening the run.
 * ---------------------------------------------------------------------- */

echo "\n-- batch scope\n";

$all = UAMS_C2S_Scanner::statuses();

uams_c2s_test_is( 'no scope means every scannable status', UAMS_C2S_Scanner::narrow_statuses( '' ), $all );
uams_c2s_test_is( 'one status narrows to just it', UAMS_C2S_Scanner::narrow_statuses( 'draft' ), array( 'draft' ) );
uams_c2s_test_is( 'several statuses narrow to those', UAMS_C2S_Scanner::narrow_statuses( array( 'draft', 'publish' ) ), array( 'publish', 'draft' ) );
uams_c2s_test_is( 'case and stray characters are normalised', UAMS_C2S_Scanner::narrow_statuses( 'DRAFT' ), array( 'draft' ) );

// The ones that matter: a scope must never reach a status the scan excludes.
uams_c2s_test_is( 'an unscannable status yields nothing', UAMS_C2S_Scanner::narrow_statuses( 'trash' ), array() );
uams_c2s_test_is( 'auto-draft cannot be smuggled in', UAMS_C2S_Scanner::narrow_statuses( 'auto-draft' ), array() );
uams_c2s_test_is( 'a bogus status cannot widen the run', UAMS_C2S_Scanner::narrow_statuses( 'any' ), array() );
uams_c2s_test_is( 'a mixed request keeps only the valid part', UAMS_C2S_Scanner::narrow_statuses( array( 'draft', 'trash' ) ), array( 'draft' ) );

/* -------------------------------------------------------------------------
 * F9 — one reader for a Content block's fields
 *
 * The scanner used to look only under `data` while the converter also handled
 * the legacy flat layout, so the Geo-targeted column could contradict the
 * conversion it exists to predict.
 * ---------------------------------------------------------------------- */

echo "\n-- block field reading\n";

$nested = array( 'name' => 'acf/uams-content', 'data' => array( 'content_geo' => 'include', 'content_geo_region' => array( 'ar' ) ) );

uams_c2s_test_is( 'nested attributes are read from data', UAMS_C2S_Converter::block_data( $nested ), $nested['data'] );
uams_c2s_test_is( 'a non-array data attribute reads as empty', UAMS_C2S_Converter::block_data( array( 'data' => 'nonsense' ) ), array() );

// The legacy flat layout is deliberately no longer read: all three target sites
// were measured and none of the 91 remaining Content blocks uses it. What must
// not happen is reading it as empty *quietly*.
uams_c2s_test_is(
	'a block with no data attribute reads as empty',
	UAMS_C2S_Converter::block_data( array( 'name' => 'acf/uams-content', 'align' => 'full', 'content_heading' => 'Program' ) ),
	array()
);
// Scanner::tally() calls block_data() rather than reaching into `data` itself,
// so the two cannot diverge again. Asserting that end to end needs a real
// parse_blocks(), which this harness deliberately does not fake -- a stub block
// parser would prove agreement with itself, not with WordPress.

/* -------------------------------------------------------------------------
 * F6 — a recorded source_revision is an address, not a warrant
 *
 * The recorded-id branch used to return whatever the id pointed at after
 * checking only post type and parent, while the search branch rejected damaged
 * and already-converted revisions. That gap was the one way mangled content
 * could be written back over a post while repair reported success.
 * ---------------------------------------------------------------------- */

echo "\n-- recorded revision validity\n";

// Recorded id points at damaged markup.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 40, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 400, 'post_type' => 'revision', 'post_parent' => 40, 'post_content' => $mangled, 'post_date_gmt' => '2026-08-18 20:00:00' ) );
update_post_meta( 40, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => 1, 'version' => '1.0.0', 'source_revision' => 400 ) );

$converter = new UAMS_C2S_Test_Converter();

uams_c2s_test_is( 'recorded id pointing at damaged markup is refused', $converter->pre_conversion_revision( 40 ), null );

// Recorded id points at content with no Content block left to convert.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 41, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 410, 'post_type' => 'revision', 'post_parent' => 41, 'post_content' => $converted, 'post_date_gmt' => '2026-08-18 20:00:00' ) );
update_post_meta( 41, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => 1, 'version' => '1.0.0', 'source_revision' => 410 ) );

$converter = new UAMS_C2S_Test_Converter();

uams_c2s_test_is( 'recorded id with no Content block is refused', $converter->pre_conversion_revision( 41 ), null );

// A recorded id pointing at good content is still honoured.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 42, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 420, 'post_type' => 'revision', 'post_parent' => 42, 'post_content' => $source, 'post_date_gmt' => '2026-08-18 20:00:00' ) );
update_post_meta( 42, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => 1, 'version' => '1.0.0', 'source_revision' => 420 ) );

$converter = new UAMS_C2S_Test_Converter();
$chosen    = $converter->pre_conversion_revision( 42 );

uams_c2s_test_is( 'a sound recorded id is still honoured', $chosen ? (int) $chosen->ID : 0, 420 );

/* -------------------------------------------------------------------------
 * F6/F12 — repair_post() refuses instead of reporting success
 * ---------------------------------------------------------------------- */

echo "\n-- repair refusals\n";

// Mismatch: the restore is not what was converted. Must refuse, and must not
// touch post_content -- previously only the batch loop declined, while the
// single-post button and the CLI went ahead.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 50, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 500, 'post_type' => 'revision', 'post_parent' => 50, 'post_content' => $source, 'post_date_gmt' => '2025-08-27 19:31:56' ) );
add_post_meta( 50, UAMS_C2S_Converter::BACKUP_META, $source . '<!-- wp:paragraph --><p>Edited without a revision.</p><!-- /wp:paragraph -->' );
update_post_meta( 50, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => strtotime( '2026-08-18 21:51:00 +0000' ), 'version' => '1.0.0' ) );

$converter         = new UAMS_C2S_Test_Converter();
$converter->canned = array( 'converted' => 1 );
$result            = $converter->repair_post( 50, true );

uams_c2s_test_is( 'mismatch: refuses even when forced', empty( $result['repaired'] ), true );
uams_c2s_test_is( 'mismatch: says why', $result['reason'], 'mismatch' );
uams_c2s_test_is( 'mismatch: post_content untouched', get_post( 50 )->post_content, $mangled );
uams_c2s_test_is( 'mismatch: backup still present', metadata_exists( 'post', 50, UAMS_C2S_Converter::BACKUP_META ), true );

// Restore succeeds but yields nothing to convert. The post is legitimately back
// to pre-conversion content, but claiming "repaired" hid that both metas had
// been dropped and no new pair written.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 51, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 510, 'post_type' => 'revision', 'post_parent' => 51, 'post_content' => $source, 'post_date_gmt' => '2026-08-18 20:00:00' ) );
update_post_meta( 51, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => strtotime( '2026-08-18 21:00:00 +0000' ), 'version' => '1.0.0' ) );

$converter         = new UAMS_C2S_Test_Converter();
$converter->canned = array( 'converted' => 0 ); // Nothing convertible in the restore.
$result            = $converter->repair_post( 51, true );

uams_c2s_test_is( 'no-op re-conversion is not reported as repaired', empty( $result['repaired'] ), true );
uams_c2s_test_is( 'no-op re-conversion says why', $result['reason'], 'not_converted' );
uams_c2s_test_is( 'no-op re-conversion still restored the content', get_post( 51 )->post_content, $source );

// The healthy path still works end to end.
uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 52, 'post_content' => $mangled ) );
uams_c2s_test_post( array( 'ID' => 520, 'post_type' => 'revision', 'post_parent' => 52, 'post_content' => $source, 'post_date_gmt' => '2026-08-18 20:00:00' ) );
update_post_meta( 52, UAMS_C2S_Converter::CONVERTED_META, array( 'time' => strtotime( '2026-08-18 21:00:00 +0000' ), 'version' => '1.0.0' ) );

$converter         = new UAMS_C2S_Test_Converter();
$converter->canned = array( 'content' => $converted, 'converted' => 2 );
$result            = $converter->repair_post( 52, true );

uams_c2s_test_is( 'healthy repair reports repaired', ! empty( $result['repaired'] ), true );
uams_c2s_test_is( 'healthy repair restored from the revision', $result['source'], 'revision' );

/* -------------------------------------------------------------------------
 * F25 — drive convert_post() for real, so the backup write is covered
 *
 * Acceptance test for the fix: deleting wp_slash() from the add_post_meta()
 * call in convert_post() must turn 'backup round-trips byte-identically' red.
 * ---------------------------------------------------------------------- */

echo "\n-- convert_post()\n";

uams_c2s_test_reset();
uams_c2s_test_post( array( 'ID' => 60, 'post_content' => $source ) );
// Deliberately non-monotonic: the newest revision by date is NOT the highest ID.
uams_c2s_test_post( array( 'ID' => 605, 'post_type' => 'revision', 'post_parent' => 60, 'post_content' => $source, 'post_date_gmt' => '2026-08-01 09:00:00' ) );
uams_c2s_test_post( array( 'ID' => 601, 'post_type' => 'revision', 'post_parent' => 60, 'post_content' => $source, 'post_date_gmt' => '2026-08-18 09:00:00' ) );

$converter         = new UAMS_C2S_Test_Converter();
$converter->canned = array( 'content' => $converted, 'converted' => 3 );
$result            = $converter->convert_post( 60 );

uams_c2s_test_is( 'convert_post reports updated', ! empty( $result['updated'] ), true );
uams_c2s_test_is( 'converted content is stored intact', get_post( 60 )->post_content, $converted );
uams_c2s_test_is(
	'backup round-trips byte-identically',
	get_post_meta( 60, UAMS_C2S_Converter::BACKUP_META, true ),
	$source
);
uams_c2s_test_is(
	'backup is not damaged',
	UAMS_C2S_Converter::looks_damaged( get_post_meta( 60, UAMS_C2S_Converter::BACKUP_META, true ) ),
	false
);

$record = get_post_meta( 60, UAMS_C2S_Converter::CONVERTED_META, true );

uams_c2s_test_is( 'conversion record names this version', $record['version'], 'test' );
uams_c2s_test_is( 'conversion record counts the blocks', (int) $record['converted'], 3 );

// A second conversion must not replace a good backup with the converted content.
$converter2         = new UAMS_C2S_Test_Converter();
$converter2->canned = array( 'content' => $converted, 'converted' => 1 );
$converter2->convert_post( 60 );

uams_c2s_test_is(
	'a second conversion leaves the original backup alone',
	get_post_meta( 60, UAMS_C2S_Converter::BACKUP_META, true ),
	$source
);

uams_c2s_test_done();
