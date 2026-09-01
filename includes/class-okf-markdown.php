<?php
/**
 * Konverter HTML → Markdown berbasis DOMDocument (PHP stdlib, tanpa Composer).
 * ponytail: mencakup elemen umum konten WordPress; elemen tak dikenal di-flatten ke teks.
 * Bila kelak butuh fidelitas penuh (nested table, definition list), ganti dengan league/html-to-markdown.
 */

defined( 'ABSPATH' ) || exit;

class OKF_Markdown {

	public static function convert( string $html ): string {
		$html = trim( $html );
		if ( $html === '' ) {
			return '';
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="utf-8"?><body>' . $html . '</body>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		$md   = $body ? self::walk( $body ) : wp_strip_all_tags( $html );

		// Rapikan: maksimal satu baris kosong beruntun.
		return trim( preg_replace( "/\n{3,}/", "\n\n", $md ) ) . "\n";
	}

	private static function walk( DOMNode $node, int $list_depth = 0, string $list_type = 'ul' ): string {
		if ( $node instanceof DOMText ) {
			return preg_replace( '/\s+/u', ' ', $node->nodeValue );
		}
		if ( ! $node instanceof DOMElement && ! $node instanceof DOMDocumentFragment && $node->nodeName !== 'body' ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );

		if ( in_array( $tag, [ 'script', 'style', 'noscript', 'iframe', 'form' ], true ) ) {
			return '';
		}

		$children = fn() => self::walk_children( $node, $list_depth, $list_type );

		switch ( $tag ) {
			case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
				return "\n\n" . str_repeat( '#', (int) $tag[1] ) . ' ' . trim( $children() ) . "\n\n";

			case 'p':
			case 'figure':
				return "\n\n" . trim( $children() ) . "\n\n";

			case 'br':
				return "  \n";

			case 'hr':
				return "\n\n---\n\n";

			case 'strong': case 'b':
				$t = trim( $children() );
				return $t === '' ? '' : '**' . $t . '**';

			case 'em': case 'i':
				$t = trim( $children() );
				return $t === '' ? '' : '*' . $t . '*';

			case 'a':
				$href = $node->getAttribute( 'href' );
				$t    = trim( $children() );
				return $href ? '[' . ( $t ?: $href ) . '](' . $href . ')' : $t;

			case 'img':
				$alt = $node->getAttribute( 'alt' );
				$src = $node->getAttribute( 'src' );
				return $src ? '![' . $alt . '](' . $src . ')' : '';

			case 'figcaption':
				return "\n*" . trim( $children() ) . "*\n";

			case 'code':
				// <pre><code> ditangani di 'pre'.
				return strtolower( $node->parentNode->nodeName ?? '' ) === 'pre'
					? $node->textContent
					: '`' . $node->textContent . '`';

			case 'pre':
				return "\n\n```\n" . rtrim( $node->textContent ) . "\n```\n\n";

			case 'blockquote':
				$inner = trim( self::walk_children( $node, $list_depth, $list_type ) );
				return "\n\n> " . str_replace( "\n", "\n> ", $inner ) . "\n\n";

			case 'ul':
			case 'ol':
				return "\n" . self::walk_children( $node, $list_depth + 1, $tag ) . ( $list_depth === 0 ? "\n" : '' );

			case 'li':
				$indent = str_repeat( '  ', max( 0, $list_depth - 1 ) );
				$marker = $list_type === 'ol' ? '1. ' : '- ';
				return "\n" . $indent . $marker . trim( self::walk_children( $node, $list_depth, $list_type ) );

			case 'table':
				return self::table( $node );

			default:
				return $children();
		}
	}

	private static function walk_children( DOMNode $node, int $list_depth, string $list_type ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::walk( $child, $list_depth, $list_type );
		}
		return $out;
	}

	private static function table( DOMElement $table ): string {
		$rows = [];
		foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = [];
			foreach ( $tr->childNodes as $cell ) {
				if ( in_array( strtolower( $cell->nodeName ), [ 'td', 'th' ], true ) ) {
					$cells[] = str_replace( "\n", ' ', trim( self::walk( $cell ) ) );
				}
			}
			if ( $cells ) {
				$rows[] = $cells;
			}
		}
		if ( ! $rows ) {
			return '';
		}
		$out = "\n\n| " . implode( ' | ', $rows[0] ) . " |\n";
		$out .= '|' . str_repeat( ' --- |', count( $rows[0] ) ) . "\n";
		foreach ( array_slice( $rows, 1 ) as $r ) {
			$out .= '| ' . implode( ' | ', $r ) . " |\n";
		}
		return $out . "\n";
	}
}
