<?php
/**
 * Cover for UAMS_C2S_HTML_To_Blocks.
 *
 * Run with: php tests/test-html-to-blocks.php
 *
 * Currently focused on paragraph alignment, where the class list served three
 * jobs at once -- alignment signal, emitted `class` attribute, and the basis for
 * `className` -- and a source that already carried `has-text-align-*` was given
 * it a second time.
 *
 * @package UAMS_Content_To_Section
 */

require_once __DIR__ . '/stubs.php';

/**
 * Convert one HTML fragment and return the first block.
 *
 * @param string $html Fragment.
 * @param array  $args Conversion arguments.
 * @return array
 */
function uams_c2s_first_block( $html, array $args = [] ) {
	$parser = new UAMS_C2S_HTML_To_Blocks( $args );
	$blocks = $parser->convert( $html );

	return $blocks ? $blocks[0] : [];
}

/**
 * The class tokens a converted paragraph emits.
 *
 * @param string $html Fragment.
 * @return string[]
 */
function uams_c2s_emitted_classes( $html ) {
	$block = uams_c2s_first_block( $html );

	if ( ! preg_match( '/class="([^"]*)"/', $block['innerHTML'], $m ) ) {
		return [];
	}

	return explode( ' ', $m[1] );
}

echo "-- paragraph alignment\n";

// Bootstrap alignment: mapped to a block attribute and the canonical class.
uams_c2s_test_is(
	'text-center maps to the canonical class',
	uams_c2s_emitted_classes( '<p class="text-center">x</p>' ),
	[ 'has-text-align-center' ]
);
uams_c2s_test_is(
	'text-center sets the align attribute',
	uams_c2s_first_block( '<p class="text-center">x</p>' )['attrs']['align'],
	'center'
);

// The regression: source already in editor form must not be given it twice.
uams_c2s_test_is(
	'has-text-align-center is not duplicated',
	uams_c2s_emitted_classes( '<p class="has-text-align-center">x</p>' ),
	[ 'has-text-align-center' ]
);
uams_c2s_test_is(
	'conflicting alignment classes collapse to one',
	uams_c2s_emitted_classes( '<p class="has-text-align-right text-center">x</p>' ),
	[ 'has-text-align-right' ]
);

// Author classes survive, and land in className as well as the markup.
uams_c2s_test_is(
	'author classes are kept alongside the alignment',
	uams_c2s_emitted_classes( '<p class="lead text-center">x</p>' ),
	[ 'lead', 'has-text-align-center' ]
);
uams_c2s_test_is(
	'author classes reach className',
	uams_c2s_first_block( '<p class="lead text-center">x</p>' )['attrs']['className'],
	'lead'
);
uams_c2s_test_is(
	'an alignment class never leaks into className',
	uams_c2s_first_block( '<p class="has-text-align-center lead">x</p>' )['attrs']['className'],
	'lead'
);

// No alignment at all.
uams_c2s_test_is(
	'a plain paragraph emits no class attribute',
	uams_c2s_emitted_classes( '<p>x</p>' ),
	[]
);
uams_c2s_test_is(
	'a plain paragraph has no align attribute',
	isset( uams_c2s_first_block( '<p>x</p>' )['attrs']['align'] ),
	false
);
uams_c2s_test_is(
	'author-only classes emit without an alignment',
	uams_c2s_emitted_classes( '<p class="lead">x</p>' ),
	[ 'lead' ]
);

// Whatever the input, a class attribute must never repeat a token.
foreach ( [ 'text-center', 'has-text-align-center', 'has-text-align-right text-center', 'lead has-text-align-left', 'has-text-align-center lead extra' ] as $input ) {
	$tokens = uams_c2s_emitted_classes( '<p class="' . $input . '">x</p>' );

	uams_c2s_test_is(
		sprintf( 'no repeated token for "%s"', $input ),
		count( $tokens ) === count( array_unique( $tokens ) ),
		true
	);
}

uams_c2s_test_done();
