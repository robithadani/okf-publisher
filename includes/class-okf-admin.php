<?php
/**
 * Halaman Settings → OKF: konsep, status bundle, penjelajah, cara menyambungkan, dan pengaturan.
 */

defined( 'ABSPATH' ) || exit;

class OKF_Admin {

	private const HOOK = 'settings_page_okf-publisher';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'wp_ajax_okf_rebuild', [ $this, 'ajax_rebuild' ] );
		add_action( 'wp_ajax_okf_browse', [ $this, 'ajax_browse' ] );
	}

	public function menu(): void {
		add_options_page( 'OKF Publisher', 'OKF', 'manage_options', 'okf-publisher', [ $this, 'render' ] );
	}

	public function register(): void {
		register_setting( 'okf', 'okf_settings', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );
	}

	public function assets( string $hook ): void {
		if ( $hook !== self::HOOK ) {
			return;
		}
		wp_enqueue_style( 'okf-admin', plugins_url( 'assets/admin.css', OKF_PLUGIN_FILE ), [], OKF_VERSION );
		wp_enqueue_script( 'okf-admin', plugins_url( 'assets/admin.js', OKF_PLUGIN_FILE ), [], OKF_VERSION, true );
		wp_localize_script( 'okf-admin', 'OKF', [
			'ajax'         => admin_url( 'admin-ajax.php' ),
			'nonceRebuild' => wp_create_nonce( 'okf_rebuild' ),
			'nonceBrowse'  => wp_create_nonce( 'okf_browse' ),
			'base'         => home_url( '/okf/' ),
			'public'       => OKF_Publisher::settings()['api_key'] === '',
		] );
	}

	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];

		$key = trim( sanitize_text_field( $input['api_key'] ?? '' ) );
		if ( $key !== '' && strlen( $key ) < 16 ) {
			add_settings_error( 'okf_settings', 'okf_key_short', 'API key minimal 16 karakter, jadi key sebelumnya dipertahankan. Gunakan tombol "Buat key acak".' );
			$key = OKF_Publisher::settings()['api_key'];
		}
		return [
			'post_types'       => array_values( array_intersect(
				array_map( 'sanitize_key', (array) ( $input['post_types'] ?? [] ) ),
				array_keys( self::exportable_post_types() )
			) ),
			'taxonomies'       => array_values( array_intersect(
				array_map( 'sanitize_key', (array) ( $input['taxonomies'] ?? [] ) ),
				get_taxonomies( [ 'public' => true ] )
			) ),
			'site_description' => sanitize_textarea_field( $input['site_description'] ?? '' ),
			'api_key'          => $key,
			'enable_log'       => ! empty( $input['enable_log'] ),
			'enable_llms'      => ! empty( $input['enable_llms'] ),
		];
	}

	/**
	 * Rebuild berbatch via AJAX: init (bersihkan + hitung total) → batch × n (25 post
	 * per request agar tidak kena limit memori/waktu hosting) → finish (index + status).
	 */
	public function ajax_rebuild(): void {
		check_ajax_referer( 'okf_rebuild' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Tidak diizinkan.', 403 );
		}
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$gen  = OKF_Publisher::generator();
		$step = sanitize_key( $_POST['step'] ?? '' );

		switch ( $step ) {
			case 'init':
				wp_send_json_success( [ 'total' => $gen->prepare_rebuild() ] );
			case 'batch':
				$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
				wp_send_json_success( [ 'written' => $gen->rebuild_batch( $offset, 25 ) ] );
			case 'finish':
				$gen->finish_rebuild( max( 0, (int) ( $_POST['written'] ?? 0 ) ) );
				wp_send_json_success();
		}
		wp_send_json_error( 'Step tidak dikenal.', 400 );
	}

	/**
	 * Penjelajah bundle (hanya baca). path kosong = akar, "posts/" = isi folder, "posts/x.md" = isi file.
	 */
	public function ajax_browse(): void {
		check_ajax_referer( 'okf_browse' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Tidak diizinkan.', 403 );
		}

		$path = trim( (string) wp_unslash( $_POST['path'] ?? '' ) );
		$base = OKF_Publisher::base_dir();

		if ( $path === '' ) {
			$files = array_values( array_filter( [ 'index.md', 'log.md' ], fn( $f ) => is_file( $base . '/' . $f ) ) );
			wp_send_json_success( [
				'files' => $files,
				'dirs'  => OKF_Publisher::generator()->stats()['sections'],
			] );
		}

		if ( str_ends_with( $path, '/' ) ) {
			$abs = OKF_Publisher::resolve( $path, true );
			if ( $abs === null || ! is_dir( $abs ) ) {
				wp_send_json_error( 'Folder tidak ditemukan.', 404 );
			}
			$files = array_values( array_filter( scandir( $abs ), fn( $f ) => str_ends_with( $f, '.md' ) ) );
			usort( $files, fn( $a, $b ) => ( $a === 'index.md' ? 0 : 1 ) <=> ( $b === 'index.md' ? 0 : 1 ) ?: strcmp( $a, $b ) );
			wp_send_json_success( [ 'files' => array_slice( $files, 0, 3000 ) ] );
		}

		$abs = OKF_Publisher::resolve( $path );
		if ( $abs === null || ! is_file( $abs ) ) {
			wp_send_json_error( 'File tidak ditemukan.', 404 );
		}
		$full = (string) file_get_contents( $abs );
		$max  = 12000; // karakter; cukup untuk pratinjau
		wp_send_json_success( [
			'content'   => mb_substr( $full, 0, $max ),
			'truncated' => mb_strlen( $full ) > $max,
			'size'      => filesize( $abs ),
		] );
	}

	private static function exportable_post_types(): array {
		$types = get_post_types( [ 'public' => true ], 'objects' );
		unset( $types['attachment'] );
		return $types;
	}

	private static function copy_row( string $label, string $value, string $hint = '' ): void {
		?>
		<div class="okf-copy-row">
			<div class="okf-copy-label"><?php echo esc_html( $label ); ?></div>
			<div class="okf-copy-line">
				<code><?php echo esc_html( $value ); ?></code>
				<button type="button" class="button okf-copy" data-copy="<?php echo esc_attr( $value ); ?>">Salin</button>
			</div>
			<?php if ( $hint ) : ?>
				<p class="description"><?php echo esc_html( $hint ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}


	public function render(): void {
		$s       = OKF_Publisher::settings();
		$st      = OKF_Publisher::generator()->stats();
		$url     = home_url( '/okf/index.md' );
		$root    = untrailingslashit( home_url( '/okf' ) );
		$has_key = $s['api_key'] !== '';
		$labels  = [];
		foreach ( $s['post_types'] as $pt ) {
			$obj = get_post_type_object( $pt );
			if ( $obj ) {
				$labels[] = $obj->labels->name;
			}
		}
		?>
		<div class="wrap okf-wrap">
			<div class="okf-head">
				<h1>OKF Publisher</h1>
				<?php if ( $st['exists'] ) : ?>
					<span class="okf-badge okf-badge--ok">Bundle aktif</span>
				<?php else : ?>
					<span class="okf-badge okf-badge--warn">Belum dibuat</span>
				<?php endif; ?>
				<span class="okf-head-actions">
					<button type="button" id="okf-rebuild" class="button button-primary">Rebuild sekarang</button>
				</span>
			</div>
			<p id="okf-progress" class="okf-progress" role="status" aria-live="polite"></p>
			<?php settings_errors( 'okf_settings' ); ?>

			<section class="okf-card" aria-labelledby="okf-h-flow">
				<h2 id="okf-h-flow">Cara kerja OKF di situs ini</h2>
				<div class="okf-flow">
					<div class="okf-step">
						<span class="dashicons dashicons-wordpress" aria-hidden="true"></span>
						<strong>Konten WordPress</strong>
						<span><?php echo esc_html( $labels ? implode( ', ', $labels ) : 'Belum ada yang dipilih' ); ?></span>
					</div>
					<span class="dashicons dashicons-arrow-right-alt okf-arrow" aria-hidden="true"></span>
					<div class="okf-step okf-step--bundle">
						<span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
						<strong>Bundle OKF</strong>
						<span>Folder file markdown di /okf/, otomatis mengikuti konten</span>
					</div>
					<span class="dashicons dashicons-arrow-right-alt okf-arrow" aria-hidden="true"></span>
					<div class="okf-step">
						<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
						<strong>Aplikasi AI</strong>
						<span>Membaca index, lalu menelusuri link antar-dokumen</span>
					</div>
				</div>
				<p class="description">OKF (Open Knowledge Format) mengubah situs Anda menjadi folder berkas markdown yang bisa dibaca AI apa adanya, tanpa database dan tanpa SDK.</p>
			</section>

			<div class="okf-stats">
				<div class="okf-stat"><span class="okf-stat-label">Total dokumen</span><span class="okf-stat-value"><?php echo (int) $st['total']; ?></span></div>
				<?php foreach ( $st['sections'] as $sec ) : ?>
					<div class="okf-stat"><span class="okf-stat-label"><?php echo esc_html( $sec['label'] ); ?></span><span class="okf-stat-value"><?php echo (int) $sec['count']; ?></span></div>
				<?php endforeach; ?>
				<div class="okf-stat">
					<span class="okf-stat-label">Terakhir berubah</span>
					<span class="okf-stat-value okf-stat-value--sm"><?php echo $st['updated'] ? esc_html( human_time_diff( $st['updated'], time() ) . ' lalu' ) : '–'; ?></span>
				</div>
				<div class="okf-stat">
					<span class="okf-stat-label">Akses</span>
					<span class="okf-stat-value okf-stat-value--sm"><?php echo $has_key ? 'Dengan API key' : 'Publik'; ?></span>
				</div>
			</div>

			<section class="okf-card" aria-labelledby="okf-h-explore">
				<h2 id="okf-h-explore">Jelajahi bundle, persis seperti yang dibaca AI</h2>
				<?php if ( ! $st['exists'] ) : ?>
					<p>Bundle belum dibuat. Klik <strong>Rebuild sekarang</strong> untuk membuatnya, lalu kembali ke sini.</p>
				<?php else : ?>
					<div class="okf-explorer">
						<div class="okf-tree" id="okf-tree" aria-label="Isi bundle"><span class="okf-muted">Memuat…</span></div>
						<div class="okf-preview">
							<p class="okf-meta" id="okf-meta"></p>
							<div class="okf-fm-wrap">
								<p class="okf-legend">Frontmatter: label untuk mesin</p>
								<pre class="okf-fm" id="okf-fm"></pre>
							</div>
							<div class="okf-body-wrap">
								<p class="okf-legend">Isi: dibaca AI sebagai pengetahuan</p>
								<pre class="okf-body" id="okf-body"></pre>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</section>

			<section class="okf-card" aria-labelledby="okf-h-connect">
				<h2 id="okf-h-connect">Hubungkan ke aplikasi AI</h2>
				<?php
				self::copy_row( 'URL bundle', $url, $has_key ? 'Bundle memakai API key: kirim header X-OKF-Key pada setiap permintaan.' : '' );
				self::copy_row(
					'Uji dari terminal',
					$has_key ? 'curl -s -H "X-OKF-Key: <API_KEY>" ' . $url : 'curl -s ' . $url
				);
				self::copy_row(
					'Sambungkan lewat MCP (Claude Code)',
					'claude mcp add okf-reader -- node /path/ke/mcp-okf-reader/server.js ' . $root . ( $has_key ? '::<API_KEY>' : '' ),
					'Ganti /path/ke/ dengan lokasi mcp-okf-reader di komputer Anda' . ( $has_key ? ' dan <API_KEY> dengan key Anda.' : '.' )
				);
				?>
				<p id="okf-copy-status" class="screen-reader-text" role="status" aria-live="polite"></p>
			</section>

			<form method="post" action="options.php" class="okf-form">
				<?php settings_fields( 'okf' ); ?>

				<h2>Konten yang dibagikan</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Post type yang diekspor</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">Post type yang diekspor</legend>
								<?php foreach ( self::exportable_post_types() as $slug => $obj ) : ?>
									<label class="okf-check">
										<input type="checkbox" name="okf_settings[post_types][]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( in_array( $slug, $s['post_types'], true ) ); ?>>
										<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $slug ); ?></code>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">Taksonomi untuk <code>tags</code></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">Taksonomi untuk tags</legend>
								<?php foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $slug => $obj ) : ?>
									<label class="okf-check">
										<input type="checkbox" name="okf_settings[taxonomies][]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( in_array( $slug, $s['taxonomies'], true ) ); ?>>
										<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $slug ); ?></code>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="okf_desc">Deskripsi situs</label></th>
						<td>
							<textarea id="okf_desc" name="okf_settings[site_description]" rows="4" class="large-text"
								placeholder="Deskripsi bisnis untuk root index.md — konteks terpenting bagi agent AI."><?php echo esc_textarea( $s['site_description'] ); ?></textarea>
						</td>
					</tr>
				</table>

				<h2>Akses</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="okf_key">API key</label></th>
						<td>
							<input type="text" id="okf_key" name="okf_settings[api_key]" class="regular-text" autocomplete="off" spellcheck="false"
								aria-describedby="okf_key_desc" value="<?php echo esc_attr( $s['api_key'] ); ?>">
							<button type="button" class="button" id="okf-genkey">Buat key acak</button>
							<p class="description" id="okf_key_desc">Kosongkan untuk akses publik. Bila diisi (minimal 16 karakter), konsumen wajib mengirim header <code>X-OKF-Key</code>; parameter URL tidak didukung agar key tidak tercatat di log. Klik Simpan setelah membuat key.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Discoverability</th>
						<td>
							<label>
								<input type="checkbox" name="okf_settings[enable_llms]" value="1" <?php checked( $s['enable_llms'] ); ?>>
								Tayangkan <code>/llms.txt</code> yang menunjuk ke bundle OKF
							</label>
							<p class="description">Hanya aktif bila bundle publik (API key kosong). Jangan aktifkan bila plugin lain (mis. SEO plugin) sudah menyajikan llms.txt.</p>
						</td>
					</tr>
				</table>

				<h2>Lanjutan</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Riwayat perubahan</th>
						<td>
							<label>
								<input type="checkbox" name="okf_settings[enable_log]" value="1" <?php checked( $s['enable_log'] ); ?>>
								Tulis <code>log.md</code> setiap ada perubahan
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Simpan Pengaturan' ); ?>
				<p class="description">Setelah mengubah post type, taksonomi, atau deskripsi, klik <strong>Rebuild sekarang</strong> agar dokumen lama ikut diperbarui. Untuk situs besar, gunakan <code>wp okf rebuild</code> via WP-CLI.</p>
			</form>
		</div>
		<?php
	}
}
