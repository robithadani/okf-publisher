<?php
/**
 * Dijalankan WordPress saat plugin dihapus: bersihkan opsi, meta, dan bundle.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'okf_settings' );
delete_option( 'okf_status' );
delete_option( 'okf_version' );
delete_post_meta_by_key( '_okf_path' );

// Hapus bundle di uploads/okf.
$dir = wp_upload_dir()['basedir'] . '/okf';
if ( is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $f ) {
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $dir );
}
