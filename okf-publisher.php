<?php
/**
 * Plugin Name:       OKF Publisher
 * Description:       Mengekspor konten WordPress menjadi bundle Open Knowledge Format (OKF v0.1) yang tersaji di /okf/ untuk dikonsumsi aplikasi AI.
 * Version:           0.1.1
 * Author:            Sitespirit
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * Text Domain:       okf-publisher
 */

defined( 'ABSPATH' ) || exit;

define( 'OKF_VERSION', '0.1.1' );
define( 'OKF_PLUGIN_DIR', __DIR__ );

require_once OKF_PLUGIN_DIR . '/includes/class-okf-markdown.php';
require_once OKF_PLUGIN_DIR . '/includes/class-okf-generator.php';
require_once OKF_PLUGIN_DIR . '/includes/class-okf-admin.php';

final class OKF_Publisher {

	private static ?OKF_Publisher $instance = null;

	/** Post IDs yang berubah pada request ini, diproses saat shutdown agar tidak memblok editor. */
	private array $queue = [];

	public static function instance(): OKF_Publisher {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'init', [ $this, 'add_rewrite' ] );
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ 'okf_path' ] ) );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ] );

		add_action( 'transition_post_status', [ $this, 'on_status_change' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'on_delete' ], 10, 2 );
		add_action( 'shutdown', [ $this, 'flush_queue' ] );

		new OKF_Admin();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once OKF_PLUGIN_DIR . '/includes/class-okf-cli.php';
			WP_CLI::add_command( 'okf', 'OKF_CLI' );
		}

		register_activation_hook( __FILE__, [ $this, 'activate' ] );
		register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
	}

	/* ---------- Pengaturan ---------- */

	public static function settings(): array {
		return wp_parse_args( get_option( 'okf_settings', [] ), [
			'post_types'       => [ 'post', 'page' ],
			'taxonomies'       => [ 'category', 'post_tag' ],
			'site_description' => '',
			'api_key'          => '',
			'enable_log'       => false,
		] );
	}

	public static function base_dir(): string {
		return wp_upload_dir()['basedir'] . '/okf';
	}

	public static function generator(): OKF_Generator {
		static $gen = null;
		return $gen ??= new OKF_Generator( self::settings(), self::base_dir() );
	}

	/* ---------- Penyajian /okf/* ---------- */

	public function add_rewrite(): void {
		add_rewrite_rule( '^okf/?(.*)$', 'index.php?okf_path=$matches[1]', 'top' );
	}

	public function activate(): void {
		wp_mkdir_p( self::base_dir() );
		$this->add_rewrite();
		flush_rewrite_rules();
	}

	public function maybe_serve(): void {
		global $wp_query;
		if ( ! array_key_exists( 'okf_path', $wp_query->query_vars ) ) {
			return;
		}

		$key = self::settings()['api_key'];
		if ( $key !== '' ) {
			$given = $_SERVER['HTTP_X_OKF_KEY'] ?? ( $_GET['key'] ?? '' );
			if ( ! is_string( $given ) || ! hash_equals( $key, $given ) ) {
				status_header( 401 );
				exit( 'OKF: API key tidak valid.' );
			}
		}

		$rel = trim( (string) get_query_var( 'okf_path' ), '/' );
		if ( $rel === '' ) {
			$rel = 'index.md';
		} elseif ( ! str_ends_with( $rel, '.md' ) ) {
			$rel .= '/index.md'; // URL direktori → index-nya.
		}

		// Hanya file .md, tanpa path traversal.
		if ( ! preg_match( '#^[\w\-/]+\.md$#u', $rel ) || str_contains( $rel, '..' ) ) {
			status_header( 404 );
			exit( 'OKF: not found.' );
		}

		$file = self::base_dir() . '/' . $rel;
		$real = realpath( $file );
		if ( $real === false || ! str_starts_with( $real, realpath( self::base_dir() ) ) ) {
			status_header( 404 );
			exit( 'OKF: not found.' );
		}

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Length: ' . filesize( $real ) );
		readfile( $real );
		exit;
	}

	/* ---------- Sinkronisasi incremental ---------- */

	public function on_status_change( string $new, string $old, WP_Post $post ): void {
		if ( ! in_array( $post->post_type, self::settings()['post_types'], true ) ) {
			return;
		}
		if ( $new === 'publish' || $old === 'publish' ) {
			$this->queue[ $post->ID ] = true;
		}
	}

	public function on_delete( int $post_id, WP_Post $post ): void {
		if ( in_array( $post->post_type, self::settings()['post_types'], true ) ) {
			// Post sudah tidak ada saat shutdown, hapus langsung.
			self::generator()->delete_post_file( $post );
			self::generator()->regenerate_indexes( [ $post->post_type ] );
		}
	}

	public function flush_queue(): void {
		if ( ! $this->queue ) {
			return;
		}
		$gen   = self::generator();
		$types = [];
		foreach ( array_keys( $this->queue ) as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$types[ $post->post_type ] = true;
			if ( $post->post_status === 'publish' ) {
				$gen->generate_post( $post );
			} else {
				$gen->delete_post_file( $post );
			}
		}
		$gen->regenerate_indexes( array_keys( $types ) );
	}
}

OKF_Publisher::instance();
