<?php
/**
 * Plugin Name:       OKF Publisher
 * Description:       Mengekspor konten WordPress menjadi bundle Open Knowledge Format (OKF v0.1) yang tersaji di /okf/ untuk dikonsumsi aplikasi AI.
 * Version:           0.5.2
 * Update URI:        https://github.com/robithadani/okf-publisher
 * Author:            Sitespirit
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * Text Domain:       okf-publisher
 */

defined( 'ABSPATH' ) || exit;

define( 'OKF_VERSION', '0.5.2' );
define( 'OKF_PLUGIN_FILE', __FILE__ );
define( 'OKF_PLUGIN_DIR', __DIR__ );

require_once OKF_PLUGIN_DIR . '/includes/class-okf-markdown.php';
require_once OKF_PLUGIN_DIR . '/includes/class-okf-generator.php';
require_once OKF_PLUGIN_DIR . '/includes/class-okf-admin.php';
require_once OKF_PLUGIN_DIR . '/includes/class-okf-editor.php';

final class OKF_Publisher {

	private static ?OKF_Publisher $instance = null;

	/** Post IDs yang berubah pada request ini, diproses saat shutdown agar tidak memblok editor. */
	private array $queue = [];

	public static function instance(): OKF_Publisher {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'init', [ $this, 'add_rewrite' ] );
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ 'okf_path', 'okf_llms' ] ) );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ] );
		add_filter( 'redirect_canonical', [ $this, 'skip_canonical' ] );

		add_action( 'transition_post_status', [ $this, 'on_status_change' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'on_delete' ], 10, 2 );
		add_action( 'shutdown', [ $this, 'flush_queue' ] );

		new OKF_Admin();
		new OKF_Editor();

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
			'enable_llms'      => false,
		] );
	}

	/** Folder bundle memakai token acak, agar URL file langsung tidak bisa ditebak (lapis kedua selain .htaccess). */
	public static function base_dir(): string {
		$uploads = wp_upload_dir()['basedir'];
		$token   = get_option( 'okf_dir' );
		if ( ! $token ) {
			$token = bin2hex( random_bytes( 12 ) );
			if ( add_option( 'okf_dir', $token, '', false ) ) {
				if ( is_dir( $uploads . '/okf' ) ) {
					@rename( $uploads . '/okf', $uploads . '/okf-' . $token ); // migrasi dari versi lama
				}
			} else {
				$token = get_option( 'okf_dir' ); // request lain menang balapan
			}
		}
		return $uploads . '/okf-' . $token;
	}

	public static function generator(): OKF_Generator {
		static $gen = null;
		return $gen ??= new OKF_Generator( self::settings(), self::base_dir() );
	}

	/**
	 * Path relatif di dalam bundle (file .md, atau folder bila $dir) menjadi path absolut,
	 * atau null bila tidak valid. Dipakai penyaji /okf/ dan penjelajah di halaman admin.
	 */
	public static function resolve( string $rel, bool $dir = false ): ?string {
		$rel = trim( $rel, '/' );
		$re  = $dir ? '#^[\w\-/]*$#u' : '#^[\w\-/]+\.md$#u';
		if ( ! preg_match( $re, $rel ) || str_contains( $rel, '..' ) ) {
			return null;
		}
		$root = realpath( self::base_dir() );
		$real = realpath( self::base_dir() . '/' . $rel );
		if ( $root === false || $real === false ) {
			return null;
		}
		if ( $real !== $root && ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		return $real;
	}

	/* ---------- Penyajian /okf/* ---------- */

	public function add_rewrite(): void {
		add_rewrite_rule( '^okf/?(.*)$', 'index.php?okf_path=$matches[1]', 'top' );

		// Daftarkan hanya bila diaktifkan, agar tidak menimpa llms.txt plugin lain (mis. Rank Math).
		$llms = ! empty( self::settings()['enable_llms'] );
		if ( $llms ) {
			add_rewrite_rule( '^llms\.txt$', 'index.php?okf_llms=1', 'top' );
		}

		// Flush otomatis saat versi atau status opsi llms.txt berubah, tanpa re-save permalink manual.
		$sig = OKF_VERSION . ':' . (int) $llms;
		if ( get_option( 'okf_version' ) !== $sig ) {
			flush_rewrite_rules();
			update_option( 'okf_version', $sig, false );
			self::generator()->protect();
		}
	}

	public function activate(): void {
		wp_mkdir_p( self::base_dir() );
		$this->add_rewrite();
		flush_rewrite_rules();
	}

	public function maybe_serve(): void {
		global $wp_query;

		if ( get_query_var( 'okf_llms' ) ) {
			$this->serve_llms();
			return;
		}

		if ( ! array_key_exists( 'okf_path', $wp_query->query_vars ) ) {
			return;
		}

		$key = self::settings()['api_key'];
		if ( $key !== '' ) {
			$given = $_SERVER['HTTP_X_OKF_KEY'] ?? ''; // hanya header: parameter URL tercatat di access log
			if ( ! is_string( $given ) || ! hash_equals( $key, $given ) ) {
				status_header( 401 );
				exit( 'OKF: API key tidak valid.' );
			}
		}

		$rel = trim( (string) get_query_var( 'okf_path' ), '/' );
		if ( $rel === '' ) {
			$rel = 'index.md';
		} elseif ( ! str_ends_with( $rel, '.md' ) ) {
			$rel .= '/index.md'; // URL direktori: sajikan index-nya.
		}

		$real = self::resolve( $rel );
		if ( $real === null || ! is_file( $real ) ) {
			status_header( 404 );
			exit( 'OKF: not found.' );
		}

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		// Bundle bukan halaman untuk mesin pencari (duplikat konten). Kosongkan lewat filter bila ingin diindeks.
		$robots = apply_filters( 'okf_robots_header', 'noindex, nofollow' );
		if ( $robots ) {
			header( 'X-Robots-Tag: ' . $robots );
		}
		header( 'Content-Length: ' . filesize( $real ) );
		readfile( $real );
		exit;
	}

	/** llms.txt penunjuk ke bundle, hanya bila diaktifkan dan bundle publik (PRD 9.5). */
	private function serve_llms(): void {
		$s = self::settings();
		if ( empty( $s['enable_llms'] ) || $s['api_key'] !== '' ) {
			return; // biarkan WP 404 / handler lain mengambil alih
		}
		$desc = $s['site_description'] ?: get_bloginfo( 'description' );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo '# ' . get_bloginfo( 'name' ) . "\n\n"
			. ( $desc ? '> ' . $desc . "\n\n" : '' )
			. "## Knowledge base\n\n"
			. '- [Bundle OKF](' . home_url( '/okf/index.md' ) . "): Seluruh konten situs dalam Open Knowledge Format (markdown + YAML frontmatter)\n";
		exit;
	}

	/* ---------- Sinkronisasi incremental ---------- */

	/** URL file (mis. /okf/index.md) tidak boleh dialihkan ke versi ber-slash: curl dan klien lain tidak mengikuti redirect. */
	public function skip_canonical( $redirect ) {
		return ( get_query_var( 'okf_path', null ) !== null || get_query_var( 'okf_llms', null ) !== null ) ? false : $redirect;
	}

	/** Jadwalkan regenerasi satu post pada akhir request (dipakai panel editor). */
	public function enqueue( int $post_id ): void {
		$this->queue[ $post_id ] = true;
	}

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
