<?php
/**
 * Dijalankan WordPress saat plugin dihapus: bersihkan opsi, meta, dan bundle.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$uploads = wp_upload_dir()['basedir'];
$token   = get_option( 'okf_dir' );

// Folder bundle ber-token (v0.3+) dan folder lama uploads/okf (v0.1-0.2).
foreach ( array_filter( [ $token ? $uploads . '/okf-' . $token : '', $uploads . '/okf' ] ) as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $f ) {
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $dir );
}

foreach ( [ 'okf_settings', 'okf_status', 'okf_version', 'okf_dir' ] as $opt ) {
	delete_option( $opt );
}
delete_post_meta_by_key( '_okf_path' );
delete_post_meta_by_key( '_okf_exclude' );
