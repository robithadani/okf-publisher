<?php
/**
 * WP-CLI: wp okf rebuild | wp okf validate
 */

defined( 'ABSPATH' ) || exit;

class OKF_CLI {

	/**
	 * Membangun ulang seluruh bundle OKF.
	 *
	 * ## EXAMPLES
	 *     wp okf rebuild
	 */
	public function rebuild(): void {
		$count = OKF_Publisher::generator()->rebuild_all(
			fn( $done, $total ) => WP_CLI::log( "  {$done}/{$total} dokumen…" )
		);
		WP_CLI::success( "Bundle selesai: {$count} dokumen di " . OKF_Publisher::base_dir() );
	}

	/**
	 * Memvalidasi bundle: setiap file .md punya frontmatter YAML dengan field `type`.
	 *
	 * ## EXAMPLES
	 *     wp okf validate
	 */
	public function validate(): void {
		$errors = OKF_Publisher::generator()->validate();
		if ( $errors ) {
			foreach ( $errors as $e ) {
				WP_CLI::warning( $e );
			}
			WP_CLI::error( count( $errors ) . ' masalah ditemukan.' );
		}
		WP_CLI::success( 'Bundle valid — semua dokumen punya frontmatter dan field `type`.' );
	}
}
