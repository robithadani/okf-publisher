<?php
/**
 * Integrasi di layar WordPress biasa: kolom "OKF" di daftar post dan panel OKF di editor
 * (pratinjau frontmatter + opsi mengecualikan post dari bundle).
 */

defined( 'ABSPATH' ) || exit;

class OKF_Editor {

	public function __construct() {
		// Dipakai juga saat generate dari REST/cron, jadi didaftarkan selalu.
		add_filter( 'okf_exclude_post', [ $this, 'is_excluded' ], 5, 2 );
		add_action( 'admin_init', [ $this, 'admin_hooks' ] );
	}

	public function is_excluded( $excluded, $post ) {
		return $excluded || get_post_meta( $post->ID, '_okf_exclude', true ) === '1';
	}

	public function admin_hooks(): void {
		foreach ( OKF_Publisher::settings()['post_types'] as $pt ) {
			add_filter( "manage_{$pt}_posts_columns", [ $this, 'add_column' ] );
			add_action( "manage_{$pt}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
		}
		add_action( 'add_meta_boxes', [ $this, 'add_box' ] );
		add_action( 'save_post', [ $this, 'save_box' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'edit.php', 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, OKF_Publisher::settings()['post_types'], true ) ) {
			return;
		}
		wp_enqueue_style( 'okf-admin', plugins_url( 'assets/admin.css', OKF_PLUGIN_FILE ), [], OKF_VERSION );
	}

	/** @return array{0:string,1:string,2:string} [kode, label, path relatif di bundle] */
	private function status( WP_Post $post ): array {
		if ( $post->post_status !== 'publish' ) {
			return [ 'off', 'Belum terbit', '' ];
		}
		if ( apply_filters( 'okf_exclude_post', false, $post ) ) {
			return [ 'off', 'Dikecualikan', '' ];
		}
		$rel = (string) get_post_meta( $post->ID, '_okf_path', true );
		if ( $rel !== '' && is_file( OKF_Publisher::base_dir() . '/' . $rel ) ) {
			return [ 'ok', 'Di bundle', $rel ];
		}
		return [ 'warn', 'Belum masuk', '' ];
	}

	private function badge( string $code, string $label ): string {
		return '<span class="okf-badge okf-badge--' . esc_attr( $code ) . '">' . esc_html( $label ) . '</span>';
	}

	private function view_url( string $rel ): string {
		// Bundle ber-API key tidak bisa dibuka lewat link biasa.
		return OKF_Publisher::settings()['api_key'] === '' ? esc_url( home_url( '/okf/' . $rel ) ) : '';
	}

	/* ---------- Kolom di daftar post ---------- */

	public function add_column( array $cols ): array {
		$out = [];
		foreach ( $cols as $k => $v ) {
			if ( $k === 'date' ) {
				$out['okf'] = 'OKF';
			}
			$out[ $k ] = $v;
		}
		return isset( $out['okf'] ) ? $out : $out + [ 'okf' => 'OKF' ];
	}

	public function render_column( string $column, int $post_id ): void {
		if ( $column !== 'okf' ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		[ $code, $label, $rel ] = $this->status( $post );
		echo '<span class="okf-cell">' . $this->badge( $code, $label ); // phpcs:ignore WordPress.Security.EscapeOutput -- badge() meng-escape sendiri
		$url = $rel !== '' ? $this->view_url( $rel ) : '';
		if ( $url !== '' ) {
			echo ' <a href="' . $url . '" target="_blank" rel="noopener">Lihat</a>'; // phpcs:ignore WordPress.Security.EscapeOutput -- view_url() sudah esc_url
		}
		echo '</span>';
	}

	/* ---------- Panel di editor ---------- */

	public function add_box( string $post_type ): void {
		if ( in_array( $post_type, OKF_Publisher::settings()['post_types'], true ) ) {
			add_meta_box( 'okf_box', 'OKF', [ $this, 'render_box' ], $post_type, 'side', 'low' );
		}
	}

	/** Frontmatter persis seperti yang tertulis di file bundle. */
	private function frontmatter_of( string $rel ): string {
		$abs = OKF_Publisher::resolve( $rel );
		if ( $abs === null || ! is_file( $abs ) ) {
			return '';
		}
		$head = (string) file_get_contents( $abs, false, null, 0, 4096 );
		return preg_match( '/^---\n(.*?)\n---/s', $head, $m ) ? $m[1] : '';
	}

	public function render_box( WP_Post $post ): void {
		[ $code, $label, $rel ] = $this->status( $post );
		$excluded = get_post_meta( $post->ID, '_okf_exclude', true ) === '1';
		$fm       = $rel !== '' ? $this->frontmatter_of( $rel ) : '';
		$url      = $rel !== '' ? $this->view_url( $rel ) : '';
		wp_nonce_field( 'okf_box', 'okf_box_nonce' );
		?>
		<div class="okf-box">
			<p><?php echo $this->badge( $code, $label ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<?php if ( $rel !== '' ) : ?>
				<p class="description"><code><?php echo esc_html( '/' . $rel ); ?></code>
				<?php if ( $url !== '' ) : ?>
					· <a href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput ?>" target="_blank" rel="noopener">Lihat versi OKF</a>
				<?php endif; ?></p>
			<?php elseif ( $code === 'warn' ) : ?>
				<p class="description">Post ini akan masuk bundle saat disimpan, atau setelah rebuild.</p>
			<?php endif; ?>
			<?php if ( $fm !== '' ) : ?>
				<details>
					<summary>Frontmatter yang dibaca AI</summary>
					<pre class="okf-fm"><?php echo esc_html( $fm ); ?></pre>
				</details>
			<?php endif; ?>
			<p>
				<label>
					<input type="checkbox" name="okf_exclude" value="1" <?php checked( $excluded ); ?>>
					Kecualikan dari bundle OKF
				</label>
			</p>
			<p class="description">Berlaku setelah post disimpan. Post yang dikecualikan dihapus dari bundle.</p>
		</div>
		<?php
	}

	public function save_box( int $post_id, WP_Post $post ): void {
		$nonce = isset( $_POST['okf_box_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['okf_box_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'okf_box' ) ) {
			return; // panel tidak ikut dikirim (mis. simpan via REST): jangan ubah apa pun
		}
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, OKF_Publisher::settings()['post_types'], true ) ) {
			return;
		}
		if ( ! empty( $_POST['okf_exclude'] ) ) {
			update_post_meta( $post_id, '_okf_exclude', '1' );
		} else {
			delete_post_meta( $post_id, '_okf_exclude' );
		}
		OKF_Publisher::instance()->enqueue( $post_id );
	}
}
