<?php
/**
 * Halaman Settings → OKF: pengaturan + tombol rebuild + status bundle.
 */

defined( 'ABSPATH' ) || exit;

class OKF_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'wp_ajax_okf_rebuild', [ $this, 'ajax_rebuild' ] );
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

	private static function exportable_post_types(): array {
		$types = get_post_types( [ 'public' => true ], 'objects' );
		unset( $types['attachment'] );
		return $types;
	}

	public function render(): void {
		$s      = OKF_Publisher::settings();
		$status = get_option( 'okf_status', [] );
		?>
		<div class="wrap">
			<h1>OKF Publisher</h1>

			<p>
				Bundle tersaji di
				<code><a href="<?php echo esc_url( home_url( '/okf/index.md' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/okf/' ) ); ?></a></code>
				<?php if ( ! empty( $status['built_at'] ) ) : ?>
					· <?php echo (int) ( $status['count'] ?? 0 ); ?> dokumen
					· build terakhir <?php echo esc_html( wp_date( 'j M Y H:i', $status['built_at'] ) ); ?>
				<?php else : ?>
					· <strong>belum pernah di-build</strong> — jalankan rebuild di bawah.
				<?php endif; ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'okf' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Post type yang diekspor</th>
						<td>
							<?php foreach ( self::exportable_post_types() as $slug => $obj ) : ?>
								<label style="display:block">
									<input type="checkbox" name="okf_settings[post_types][]"
										value="<?php echo esc_attr( $slug ); ?>"
										<?php checked( in_array( $slug, $s['post_types'], true ) ); ?>>
									<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $slug ); ?></code>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Taksonomi untuk <code>tags</code></th>
						<td>
							<?php foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $slug => $obj ) : ?>
								<label style="display:block">
									<input type="checkbox" name="okf_settings[taxonomies][]"
										value="<?php echo esc_attr( $slug ); ?>"
										<?php checked( in_array( $slug, $s['taxonomies'], true ) ); ?>>
									<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $slug ); ?></code>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="okf_desc">Deskripsi situs</label></th>
						<td>
							<textarea id="okf_desc" name="okf_settings[site_description]" rows="4" class="large-text"
								placeholder="Deskripsi bisnis untuk root index.md — konteks terpenting bagi agent AI."><?php echo esc_textarea( $s['site_description'] ); ?></textarea>
						</td>
					</tr>
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
			</form>

			<hr>
			<p>
				<button type="button" id="okf-rebuild" class="button button-secondary">Rebuild Sekarang</button>
				<strong id="okf-progress" style="margin-left:8px"></strong>
			</p>
			<p class="description">Rebuild berjalan bertahap (25 dokumen per langkah) agar aman di hosting dengan limit ketat. Alternatif: <code>wp okf rebuild</code> via WP-CLI.</p>

			<script>
			(function () {
				const btn   = document.getElementById('okf-rebuild');
				const out   = document.getElementById('okf-progress');
				const nonce = '<?php echo esc_js( wp_create_nonce( 'okf_rebuild' ) ); ?>';

				document.getElementById('okf-genkey').addEventListener('click', function () {
					const b = crypto.getRandomValues(new Uint8Array(24));
					document.getElementById('okf_key').value = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
				});

				async function call(data) {
					const body = new URLSearchParams({ action: 'okf_rebuild', _ajax_nonce: nonce, ...data });
					const res  = await fetch(ajaxurl, { method: 'POST', body });
					if (!res.ok) throw new Error('server merespons ' + res.status);
					const json = await res.json();
					if (!json.success) throw new Error(json.data || 'step gagal');
					return json.data || {};
				}

				btn.addEventListener('click', async function () {
					btn.disabled = true;
					try {
						out.textContent = 'Menyiapkan…';
						const { total } = await call({ step: 'init' });
						let offset = 0, written = 0;
						while (offset < total) {
							written += (await call({ step: 'batch', offset })).written;
							offset  += 25;
							out.textContent = Math.min(offset, total) + '/' + total + ' dokumen…';
						}
						await call({ step: 'finish', written });
						out.textContent = 'Selesai — ' + written + ' dokumen ditulis.';
						setTimeout(() => location.reload(), 1000);
					} catch (e) {
						out.textContent = 'Gagal: ' + e.message + ' — coba ulangi, atau jalankan wp okf rebuild.';
						btn.disabled = false;
					}
				});
			})();
			</script>
		</div>
		<?php
	}
}
