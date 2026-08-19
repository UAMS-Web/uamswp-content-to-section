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

uams_c2s_test_done();
