<?php
/**
 * Converts the Content block's WYSIWYG HTML into an array of parsed blocks.
 *
 * The output uses the same shape `parse_blocks()` produces, so it can be handed
 * straight to `serialize_blocks()`.
 *
 * @package UAMS_Content_To_Section
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rich text to Gutenberg blocks.
 */
class UAMS_C2S_HTML_To_Blocks {

	/**
	 * Conversion arguments.
	 *
	 * @var array<string,mixed>
	 */
	protected $args;

	/**
	 * Human-readable notes about lossy or noteworthy conversions.
	 *
	 * @var string[]
	 */
	protected $notices = [];

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $args Conversion arguments.
	 */
	public function __construct( array $args = [] ) {
		$this->args = wp_parse_args( $args, uams_c2s_default_args() );
	}

	/**
	 * Notes collected during the last conversion.
	 *
	 * @return string[]
	 */
	public function get_notices(): array {
		return array_values( array_unique( $this->notices ) );
	}

	/**
	 * Convert WYSIWYG HTML into blocks.
	 *
	 * @param string $html Raw ACF WYSIWYG value.
	 * @return array[] Parsed-block arrays.
	 */
	public function convert( $html ): array {
		$this->notices = [];

		$html = $this->prepare( (string) $html );

		if ( '' === trim( $html ) ) {
			return [];
		}

		$dom = $this->load( $html );

		if ( !$dom instanceof \DOMDocument ) {
			$this->note( __( 'HTML could not be parsed; the body was preserved as a single Custom HTML block.', 'uams-c2s' ) );

			return [ $this->html_block( $html ) ];
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body ) {
			return [ $this->html_block( $html ) ];
		}

		return $this->children_to_blocks( $body );
	}

	/* ---------------------------------------------------------------------
	 * Input preparation
	 * ------------------------------------------------------------------ */

	/**
	 * Apply the paragraph handling the front end used to apply on render.
	 *
	 * ACF stores the raw editor value and runs `wpautop()` through
	 * `acf_the_content` at display time. Inner blocks are not filtered that
	 * way, so the paragraphs have to be materialised here instead.
	 *
	 * @param string $html Raw value.
	 * @return string
	 */
	protected function prepare( $html ) {
		$html = wpautop( $html );

		return shortcode_unautop( $html );
	}

	/**
     * Load HTML into a DOMDocument as UTF-8.
     *
     * @param string $html HTML fragment.
     */
    protected function load( $html ): ?\DOMDocument {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}

		$wrapped = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
			. $html
			. '</body></html>';

		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML( $wrapped );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $dom : null;
	}

	/* ---------------------------------------------------------------------
	 * Node walking
	 * ------------------------------------------------------------------ */

	/**
	 * Convert every child of a node.
	 *
	 * @param DOMNode $parent Parent node.
	 * @return array[]
	 */
	protected function children_to_blocks( DOMNode $parent ): array {
		$blocks = [];

		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			foreach ( $this->node_to_blocks( $node ) as $block ) {
				$blocks[] = $block;
			}
		}

		return $blocks;
	}

	/**
	 * Convert a single node.
	 *
	 * @param DOMNode $node Node.
	 * @return array[]
	 */
	protected function node_to_blocks( DOMNode $node ): array {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return $this->text_to_blocks( $node );
		}

		if ( XML_COMMENT_NODE === $node->nodeType ) {
			return [];
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return [];
		}

		$tag = strtolower( $node->nodeName );

		switch ( $tag ) {
			case 'p':
				return $this->paragraph( $node );

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return $this->heading( $node, (int) substr( $tag, 1 ) );

			case 'ul':
			case 'ol':
				return [ $this->list_block( $node ) ];

			case 'blockquote':
				return [ $this->quote( $node ) ];

			case 'table':
				return [ $this->table( $node ) ];

			case 'figure':
				return $this->figure( $node );

			case 'img':
				return [ $this->image_block( $node ) ];

			case 'iframe':
				return [ $this->iframe( $node ) ];

			case 'hr':
				return [ $this->separator() ];

			case 'pre':
				return [ $this->preformatted( $node ) ];

			case 'br':
				return [];

			case 'script':
			case 'style':
			case 'form':
			case 'object':
			case 'video':
			case 'audio':
			case 'noscript':
				$this->note(
					sprintf(
						/* translators: %s: HTML tag name. */
						__( '<%s> markup was preserved as a Custom HTML block.', 'uams-c2s' ),
						$tag
					)
				);

				return [ $this->html_block( $this->outer_html( $node ) ) ];

			case 'div':
			case 'section':
			case 'article':
			case 'aside':
			case 'span':
				// Layout markup (Bootstrap rows, embed wrappers, etc.) is kept
				// verbatim rather than guessed at.
				$this->note(
					sprintf(
						/* translators: %s: HTML tag name. */
						__( 'A <%s> wrapper was preserved as a Custom HTML block.', 'uams-c2s' ),
						$tag
					)
				);

				return [ $this->html_block( $this->outer_html( $node ) ) ];

			default:
				$this->note(
					sprintf(
						/* translators: %s: HTML tag name. */
						__( 'Unrecognised <%s> markup was preserved as a Custom HTML block.', 'uams-c2s' ),
						$tag
					)
				);

				return [ $this->html_block( $this->outer_html( $node ) ) ];
		}
	}

	/**
	 * Convert a bare text node.
	 *
	 * @param DOMNode $node Text node.
	 * @return array[]
	 */
	protected function text_to_blocks( DOMNode $node ): array {
		$text = trim( $node->textContent );

		if ( '' === $text ) {
			return [];
		}

		if ( $this->is_shortcode( $text ) ) {
			return [ $this->shortcode_block( $text ) ];
		}

		return [ $this->block( 'core/paragraph', [], '<p>' . trim( $this->outer_html( $node ) ) . '</p>' ) ];
	}

	/* ---------------------------------------------------------------------
	 * Element handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Paragraph, or the richer block a paragraph is really standing in for.
	 *
	 * @param DOMElement $node Paragraph element.
	 * @return array[]
	 */
	protected function paragraph( DOMElement $node ): array {
		$inner = trim( $this->inner_html( $node ) );

		if ( '' === $inner ) {
			return [];
		}

		$only = $this->significant_children( $node );

		// Spacer paragraphs: nothing but whitespace, &nbsp; and <br>. The DOM
		// decodes &nbsp; to U+00A0, which trim() does not remove.
		$markup_only = true;

		foreach ( $only as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'br' !== strtolower( $child->nodeName ) ) {
				$markup_only = false;
				break;
			}
		}

		if ( $markup_only && $this->is_blank( $node->textContent ) ) {
			return [];
		}

		// Scripts, forms and media players must survive byte for byte.
		foreach ( [ 'script', 'style', 'form', 'object', 'video', 'audio', 'embed', 'canvas', 'svg', 'noscript' ] as $opaque ) {
			if ( $node->getElementsByTagName( $opaque )->length > 0 ) {
				$this->note(
					sprintf(
						/* translators: %s: HTML tag name. */
						__( '<%s> markup was preserved as a Custom HTML block.', 'uams-c2s' ),
						$opaque
					)
				);

				return [ $this->html_block( $inner ) ];
			}
		}

		$text     = trim( $node->textContent );
		$is_plain = ( $text === html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' ) );

		// A paragraph holding nothing but a shortcode.
		if ( $is_plain && $this->is_shortcode( $text ) ) {
			return [ $this->shortcode_block( $text ) ];
		}

		// A paragraph holding nothing but a URL: the front end auto-embedded it.
		if ( $is_plain && $this->is_url( $text ) ) {
			if ( 0 === stripos( $text, 'httpv' ) ) {
				// YouTube Lyte syntax. Leave it untouched so the Lyte plugin can
				// still see it, and flag it for review.
				$this->note( __( 'A "httpv://" YouTube Lyte URL was kept as plain text -- confirm it still lazy-loads.', 'uams-c2s' ) );
			} else {
				return [ $this->embed_block( $text ) ];
			}
		}

		if ( 1 === count( $only ) && XML_ELEMENT_NODE === $only[0]->nodeType ) {
			$child_tag = strtolower( $only[0]->nodeName );

			if ( 'img' === $child_tag ) {
				return [ $this->image_block( $only[0] ) ];
			}

			if ( 'iframe' === $child_tag ) {
				return [ $this->iframe( $only[0] ) ];
			}

			if ( 'a' === $child_tag ) {
				$grandchildren = $this->significant_children( $only[0] );

				if ( 1 === count( $grandchildren )
					&& XML_ELEMENT_NODE === $grandchildren[0]->nodeType
					&& 'img' === strtolower( $grandchildren[0]->nodeName )
				) {
					return [ $this->image_block( $grandchildren[0], $only[0]->getAttribute( 'href' ) ) ];
				}
			}
		}

		$attrs   = [];
		$classes = $this->class_list( $node );
		$align   = $this->text_align( $classes );

		// Every class text_align() recognises comes out first, so what is left is
		// purely the author's. Source that already carried has-text-align-* used
		// to keep it here and be given it a second time below, emitting
		// class="has-text-align-center has-text-align-center".
		$extra = array_values(
			array_diff(
				$classes,
				[
					'text-left',
					'text-center',
					'text-right',
					'has-text-align-left',
					'has-text-align-center',
					'has-text-align-right',
				]
			)
		);

		$classes = $extra;

		if ( $align ) {
			$attrs['align'] = $align;
			$classes[]      = 'has-text-align-' . $align;
		}

		if ( $extra !== [] ) {
			$attrs['className'] = implode( ' ', $extra );
		}

		$class_attr = $classes !== [] ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '';

		return [ $this->block( 'core/paragraph', $attrs, '<p' . $class_attr . '>' . $inner . '</p>' ) ];
	}

	/**
	 * Heading.
	 *
	 * @param DOMElement $node  Heading element.
	 * @param int        $level Heading level from the tag name.
	 * @return array[]
	 */
	protected function heading( DOMElement $node, $level ): array {
		$inner = trim( $this->inner_html( $node ) );

		if ( '' === $inner ) {
			return [];
		}

		$section_level = (int) $this->args['max_heading'];

		if ( ! empty( $this->args['demote_headings'] ) && $level <= $section_level ) {
			$level = $section_level + 1;
		} elseif ( $level <= $section_level ) {
			$this->note(
				sprintf(
					/* translators: 1: found heading level, 2: heading level rendered by the Section block. */
					__( 'An <h%1$d> sits inside the section, which already renders an <h%2$d>. Consider the "demote headings" option.', 'uams-c2s' ),
					$level,
					$section_level
				)
			);
		}

		$level = min( 6, max( 1, $level ) );

		$attrs   = [];
		$classes = [ 'wp-block-heading' ];

		if ( 2 !== $level ) {
			$attrs['level'] = $level;
		}

		$align = $this->text_align( $this->class_list( $node ) );

		if ( $align ) {
			$attrs['textAlign'] = $align;
			$classes[]          = 'has-text-align-' . $align;
		}

		$anchor = $node->getAttribute( 'id' );
		$id     = '';

		if ( $anchor ) {
			$attrs['anchor'] = $anchor;
			$id              = ' id="' . esc_attr( $anchor ) . '"';
		}

		$html = sprintf(
			'<h%1$d class="%2$s"%3$s>%4$s</h%1$d>',
			$level,
			esc_attr( implode( ' ', $classes ) ),
			$id,
			$inner
		);

		return [ $this->block( 'core/heading', $attrs, $html ) ];
	}

	/**
     * Ordered or unordered list.
     *
     * @param DOMElement $node List element.
     */
    protected function list_block( DOMElement $node ): array {
		$ordered = ( 'ol' === strtolower( $node->nodeName ) );
		$attrs   = $ordered ? [ 'ordered' => true ] : [];

		if ( 'v3' !== $this->args['list_format'] ) {
			return $this->block( 'core/list', $attrs, $this->outer_html( $node ) );
		}

		$items = [];

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'li' === strtolower( $child->nodeName ) ) {
				$items[] = $this->list_item( $child );
			}
		}

		if ( $items === [] ) {
			return $this->block( 'core/list', $attrs, $this->outer_html( $node ) );
		}

		$tag   = $ordered ? 'ol' : 'ul';
		$start = $node->getAttribute( 'start' );

		if ( $ordered && $start ) {
			$attrs['start'] = (int) $start;
		}

		$open  = "\n" . '<' . $tag . ' class="wp-block-list"' . ( $ordered && $start ? ' start="' . esc_attr( $start ) . '"' : '' ) . '>';
		$close = '</' . $tag . '>' . "\n";

		return [
			'blockName'    => 'core/list',
			'attrs'        => $attrs,
			'innerBlocks'  => $items,
			'innerHTML'    => $open . $close,
			'innerContent' => array_merge( [ $open ], array_fill( 0, count( $items ), null ), [ $close ] ),
		];
	}

	/**
     * A single list item, splitting out any nested list.
     *
     * @param DOMElement $node List item element.
     */
    protected function list_item( DOMElement $node ): array {
		$nested  = [];
		$content = '';

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType
				&& in_array( strtolower( $child->nodeName ), [ 'ul', 'ol' ], true )
			) {
				$nested[] = $this->list_block( $child );
				continue;
			}

			$content .= $this->outer_html( $child );
		}

		$content = trim( $content );

		if ( $nested === [] ) {
			return $this->block( 'core/list-item', [], '<li>' . $content . '</li>', false );
		}

		$open  = '<li>' . $content;
		$close = '</li>';

		return [
			'blockName'    => 'core/list-item',
			'attrs'        => [],
			'innerBlocks'  => $nested,
			'innerHTML'    => $open . $close,
			'innerContent' => array_merge( [ $open ], array_fill( 0, count( $nested ), null ), [ $close ] ),
		];
	}

	/**
     * Blockquote.
     *
     * @param DOMElement $node Blockquote element.
     */
    protected function quote( DOMElement $node ): array {
		$cite  = '';
		$inner = [];

		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			$citation = $this->citation_from( $child );

			if ( null !== $citation ) {
				$cite = $citation;
				continue;
			}

			foreach ( $this->node_to_blocks( $child ) as $block ) {
				$inner[] = $block;
			}
		}

		if ( $inner === [] ) {
			$inner[] = $this->block( 'core/paragraph', [], '<p></p>' );
		}

		$open  = "\n" . '<blockquote class="wp-block-quote">';
		$close = ( '' !== $cite ? '<cite>' . $cite . '</cite>' : '' ) . '</blockquote>' . "\n";

		return [
			'blockName'    => 'core/quote',
			'attrs'        => [],
			'innerBlocks'  => $inner,
			'innerHTML'    => $open . $close,
			'innerContent' => array_merge( [ $open ], array_fill( 0, count( $inner ), null ), [ $close ] ),
		];
	}

	/**
	 * Citation text, if a node is a <cite> or a paragraph wrapping only a <cite>.
	 *
	 * `wpautop()` wraps a bare <cite> inside a blockquote in a paragraph, so both
	 * shapes have to be recognised.
	 *
	 * @param DOMNode $node Candidate node.
	 * @return string|null Null when the node is not a citation.
	 */
	protected function citation_from( DOMNode $node ): ?string {
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return null;
		}

		$tag = strtolower( $node->nodeName );

		if ( 'cite' === $tag ) {
			return trim( $this->inner_html( $node ) );
		}

		if ( 'p' !== $tag ) {
			return null;
		}

		$children = $this->significant_children( $node );

		if ( 1 === count( $children )
			&& XML_ELEMENT_NODE === $children[0]->nodeType
			&& 'cite' === strtolower( $children[0]->nodeName )
		) {
			return trim( $this->inner_html( $children[0] ) );
		}

		return null;
	}

	/**
     * Table.
     *
     * @param DOMElement $node Table element.
     */
    protected function table( DOMElement $node ): array {
		$attrs   = [];
		$classes = $this->class_list( $node );

		if ( in_array( 'is-style-stripes', $classes, true ) ) {
			$attrs['className'] = 'is-style-stripes';
		}

		return $this->block( 'core/table', $attrs, '<figure class="wp-block-table">' . $this->outer_html( $node ) . '</figure>' );
	}

	/**
	 * Figure element -- image, embed, or opaque markup.
	 *
	 * @param DOMElement $node Figure element.
	 * @return array[]
	 */
	protected function figure( DOMElement $node ): array {
		$images = $node->getElementsByTagName( 'img' );

		if ( $images->length > 0 ) {
			$img     = $images->item( 0 );
			$link    = null;
			$parent  = $img->parentNode;
			$caption = '';

			if ( $parent instanceof DOMElement && 'a' === strtolower( $parent->nodeName ) ) {
				$link = $parent->getAttribute( 'href' );
			}

			$captions = $node->getElementsByTagName( 'figcaption' );

			if ( $captions->length > 0 ) {
				$caption = trim( $this->inner_html( $captions->item( 0 ) ) );
			}

			return [ $this->image_block( $img, $link, $caption, $this->class_list( $node ) ) ];
		}

		$iframes = $node->getElementsByTagName( 'iframe' );

		if ( $iframes->length > 0 ) {
			return [ $this->iframe( $iframes->item( 0 ) ) ];
		}

		$this->note( __( 'A <figure> with no image or iframe was preserved as a Custom HTML block.', 'uams-c2s' ) );

		return [ $this->html_block( $this->outer_html( $node ) ) ];
	}

	/**
     * Image block built from an <img> element.
     *
     * @param DOMElement  $img            Image element.
     * @param string|null $link           Optional link href.
     * @param string      $caption        Optional caption HTML.
     * @param string[]    $figure_classes Classes taken from a wrapping <figure>.
     */
    protected function image_block( DOMElement $img, $link = null, $caption = '', array $figure_classes = [] ): array {
		$src = $img->getAttribute( 'src' );
		$alt = $img->getAttribute( 'alt' );

		$classes = array_merge( $this->class_list( $img ), $figure_classes );
		$attrs   = [];

		$id = 0;

		foreach ( $classes as $class ) {
			if ( preg_match( '/^wp-image-(\d+)$/', $class, $matches ) ) {
				$id = (int) $matches[1];
				break;
			}
		}

		if ( ! $id && $src ) {
			$id = $this->attachment_id_from_url( $src );
		}

		if ( $id ) {
			$attrs['id'] = $id;
		} else {
			$this->note( __( 'An image could not be matched to a Media Library item; it was linked by URL instead.', 'uams-c2s' ) );
		}

		$size = 'full';

		foreach ( $classes as $class ) {
			if ( preg_match( '/^size-([a-z0-9_\-]+)$/i', $class, $matches ) ) {
				$size = $matches[1];
				break;
			}
		}

		$attrs['sizeSlug'] = $size;

		$align = '';

		foreach ( $classes as $class ) {
			if ( preg_match( '/^align(left|right|center)$/', $class, $matches ) ) {
				$align          = $matches[1];
				$attrs['align'] = $align;
				break;
			}
		}

		$attrs['linkDestination'] = $link ? 'custom' : 'none';

		if ( '' === $alt ) {
			$this->note( __( 'An image has no alt text. Add one before publishing.', 'uams-c2s' ) );
		}

		$img_attrs = ' src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"';

		if ( $id ) {
			$img_attrs .= ' class="wp-image-' . $id . '"';
		}

		$width  = $img->getAttribute( 'width' );
		$height = $img->getAttribute( 'height' );

		if ( $width && $height ) {
			$img_attrs .= ' width="' . esc_attr( $width ) . '" height="' . esc_attr( $height ) . '"';
		}

		$markup = '<img' . $img_attrs . '/>';

		if ( $link ) {
			$markup = '<a href="' . esc_url( $link ) . '">' . $markup . '</a>';
		}

		if ( '' !== $caption ) {
			$markup .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
		}

		$figure_class = 'wp-block-image';

		if ( $align ) {
			$figure_class .= ' align' . $align;
		}

		$figure_class .= ' size-' . $size;

		return $this->block( 'core/image', $attrs, '<figure class="' . esc_attr( $figure_class ) . '">' . $markup . '</figure>' );
	}

	/**
     * Iframe -- promoted to an embed when the provider is recognised.
     *
     * @param DOMElement $node Iframe element.
     */
    protected function iframe( DOMElement $node ): array {
		$src = $node->getAttribute( 'src' );
		$url = $this->embeddable_url( $src );

		if ( $url ) {
			return $this->embed_block( $url );
		}

		$this->note( __( 'An <iframe> from an unrecognised provider was preserved as a Custom HTML block.', 'uams-c2s' ) );

		return $this->html_block( $this->outer_html( $node ) );
	}

	/**
     * Horizontal rule.
     */
    protected function separator(): array {
		return $this->block(
			'core/separator',
			[],
			'<hr class="wp-block-separator has-alpha-channel-opacity"/>'
		);
	}

	/**
     * Preformatted text or code.
     *
     * @param DOMElement $node Pre element.
     */
    protected function preformatted( DOMElement $node ): array {
		$codes = $node->getElementsByTagName( 'code' );

		if ( $codes->length > 0 ) {
			return $this->block(
				'core/code',
				[],
				'<pre class="wp-block-code"><code>' . trim( $this->inner_html( $codes->item( 0 ) ) ) . '</code></pre>'
			);
		}

		return $this->block(
			'core/preformatted',
			[],
			'<pre class="wp-block-preformatted">' . $this->inner_html( $node ) . '</pre>'
		);
	}

	/* ---------------------------------------------------------------------
     * Block builders
     * ------------------------------------------------------------------ */
    /**
     * Build a leaf block.
     *
     * @param string              $name  Block name.
     * @param array<string,mixed> $attrs Block attributes.
     * @param string              $html  Saved markup.
     * @param bool                $pad   Wrap the markup in newlines, as core does.
     */
    protected function block( $name, array $attrs, $html, $pad = true ): array {
		if ( $pad ) {
			$html = "\n" . $html . "\n";
		}

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
     * Custom HTML block.
     *
     * @param string $html Markup.
     */
    protected function html_block( $html ): array {
		return $this->block( 'core/html', [], trim( $html ) );
	}

	/**
     * Shortcode block.
     *
     * @param string $text Shortcode text.
     */
    protected function shortcode_block( $text ): array {
		return $this->block( 'core/shortcode', [], trim( $text ) );
	}

	/**
     * Embed block for a URL.
     *
     * @param string $url URL.
     */
    protected function embed_block( $url ): array {
		$providers = [
			'youtube'     => [ 'youtube.com', 'youtu.be', 'youtube-nocookie.com' ],
			'vimeo'       => [ 'vimeo.com' ],
			'tiktok'      => [ 'tiktok.com' ],
			'twitter'     => [ 'twitter.com', 'x.com' ],
			'instagram'   => [ 'instagram.com' ],
			'facebook'    => [ 'facebook.com' ],
			'flickr'      => [ 'flickr.com', 'flic.kr' ],
			'soundcloud'  => [ 'soundcloud.com' ],
			'spotify'     => [ 'spotify.com' ],
			'slideshare'  => [ 'slideshare.net' ],
			'issuu'       => [ 'issuu.com' ],
			'dailymotion' => [ 'dailymotion.com' ],
		];

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$slug = '';

		foreach ( $providers as $provider => $hosts ) {
			foreach ( $hosts as $provider_host ) {
				if ( $host === $provider_host || $this->str_ends_with( $host, '.' . $provider_host ) ) {
					$slug = $provider;
					break 2;
				}
			}
		}

		$video = in_array( $slug, [ 'youtube', 'vimeo', 'tiktok', 'dailymotion' ], true );

		$attrs = [
			'url'  => $url,
			'type' => $video ? 'video' : 'rich',
		];

		if ( $slug ) {
			$attrs['providerNameSlug'] = $slug;
		}

		$classes = [ 'wp-block-embed' ];

		if ( $slug ) {
			$classes[] = 'is-type-' . $attrs['type'];
			$classes[] = 'is-provider-' . $slug;
			$classes[] = 'wp-block-embed-' . $slug;
		}

		if ( $video ) {
			$attrs['responsive'] = true;
			$classes[]           = 'wp-embed-aspect-16-9';
			$classes[]           = 'wp-has-aspect-ratio';
		}

		if ( ! $slug ) {
			$this->note(
				sprintf(
					/* translators: %s: host name. */
					__( 'An embed for "%s" is not a block-editor provider; confirm it renders.', 'uams-c2s' ),
					$host
				)
			);
		}

		// The wrapper holds the raw URL as text -- esc_url() would rewrite "&"
		// and desynchronise it from the url attribute. The scheme and the
		// absence of whitespace are already guaranteed by is_url().
		$safe_url = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], $url );

		$html = '<figure class="' . esc_attr( implode( ' ', $classes ) ) . '"><div class="wp-block-embed__wrapper">'
			. "\n" . $safe_url . "\n"
			. '</div></figure>';

		return $this->block( 'core/embed', $attrs, $html );
	}

	/* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */
    /**
     * Serialize a node including its own tag.
     *
     * @param DOMNode $node Node.
     */
    protected function outer_html( DOMNode $node ): string {
		return (string) $node->ownerDocument->saveHTML( $node );
	}

	/**
     * Serialize a node's children.
     *
     * @param DOMNode $node Node.
     */
    protected function inner_html( DOMNode $node ): string {
		$html = '';

		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * Child nodes that carry meaning (skipping whitespace-only text).
	 *
	 * @param DOMNode $node Node.
	 * @return DOMNode[]
	 */
	protected function significant_children( DOMNode $node ): array {
		$children = [];

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( $child->textContent ) ) {
				continue;
			}

			if ( XML_COMMENT_NODE === $child->nodeType ) {
				continue;
			}

			$children[] = $child;
		}

		return $children;
	}

	/**
	 * Class attribute as an array.
	 *
	 * @param DOMNode $node Node.
	 * @return string[]
	 */
	protected function class_list( DOMNode $node ) {
		if ( ! $node instanceof DOMElement ) {
			return [];
		}

		$class = trim( $node->getAttribute( 'class' ) );

		if ( '' === $class ) {
			return [];
		}

		return array_values( array_filter( preg_split( '/\s+/', $class ) ) );
	}

	/**
	 * Map Bootstrap / editor alignment classes to a block alignment.
	 *
	 * @param string[] $classes Class names.
	 * @return string Empty string when no alignment is present.
	 */
	protected function text_align( array $classes ): string {
		$map = [
			'text-center'          => 'center',
			'text-right'           => 'right',
			'text-left'            => 'left',
			'has-text-align-center' => 'center',
			'has-text-align-right'  => 'right',
			'has-text-align-left'   => 'left',
		];

		foreach ( $classes as $class ) {
			if ( isset( $map[ $class ] ) ) {
				return $map[ $class ];
			}
		}

		return '';
	}

	/**
     * Whether a string holds nothing but whitespace.
     *
     * Covers the Unicode separators the DOM produces from &nbsp; and friends,
     * which `trim()` leaves in place.
     *
     * @param string $text Text.
     */
    protected function is_blank( $text ): bool {
		$stripped = preg_replace( '/[\p{Z}\s\x{200B}\x{FEFF}]+/u', '', (string) $text );

		if ( null === $stripped ) {
			// Invalid UTF-8: fall back to an ASCII trim rather than dropping content.
			return '' === trim( (string) $text );
		}

		return '' === $stripped;
	}

	/**
     * Whether a string is a single shortcode.
     *
     * @param string $text Text.
     */
    protected function is_shortcode( $text ): bool {
		$text = trim( $text );

		return (bool) preg_match( '/^\[[a-zA-Z0-9_\-]+[\s\S]*\]$/', $text );
	}

	/**
	 * Whether a string is a bare URL.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	protected function is_url( $text ) {
		$text = trim( $text );

		if ( false !== strpos( $text, ' ' ) || false !== strpos( $text, "\n" ) ) {
			return false;
		}

		return (bool) preg_match( '#^https?v?://\S+$#i', $text );
	}

	/**
	 * Normalise a provider iframe src to an embeddable page URL.
	 *
	 * @param string $src Iframe source.
	 * @return string Empty string when unrecognised.
	 */
	protected function embeddable_url( $src ): string {
		if ( ! $src ) {
			return '';
		}

		if ( preg_match( '#youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_\-]+)#i', $src, $matches ) ) {
			return 'https://www.youtube.com/watch?v=' . $matches[1];
		}

		if ( preg_match( '#player\.vimeo\.com/video/(\d+)#i', $src, $matches ) ) {
			return 'https://vimeo.com/' . $matches[1];
		}

		return '';
	}

	/**
     * Resolve an attachment ID from a URL, retrying without the size suffix.
     *
     * @param string $url Image URL.
     */
    protected function attachment_id_from_url( $url ): int {
		$id = attachment_url_to_postid( $url );

		if ( $id ) {
			return (int) $id;
		}

		$full = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $url );

		if ( $full && $full !== $url ) {
			return (int) attachment_url_to_postid( $full );
		}

		return 0;
	}

	/**
	 * PHP 7 compatible str_ends_with.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	protected function str_ends_with( $haystack, $needle ) {
		$length = strlen( $needle );

		if ( 0 === $length ) {
			return true;
		}

		return substr( $haystack, -$length ) === $needle;
	}

	/**
	 * Record a conversion note.
	 *
	 * @param string $message Message.
	 */
	protected function note( $message ) {
		$this->notices[] = $message;
	}
}
