<?php
/**
 * Inti generator: WordPress → bundle OKF v0.1 (file .md + YAML frontmatter).
 * Mapping mengikuti PRD §4.
 */

defined( 'ABSPATH' ) || exit;

class OKF_Generator {

	public function __construct(
		private array $settings,
		private string $base_dir
	) {}

	/* ---------- Dokumen per post ---------- */

	public function generate_post( WP_Post $post ): bool {
		if ( $post->post_status !== 'publish' ) {
			return false;
		}
		if ( apply_filters( 'okf_exclude_post', false, $post ) ) {
			return false;
		}

		$fm = [
			'type'        => $this->type_label( $post->post_type ),
			'title'       => $post->post_title,
			'description' => $this->description( $post ),
			'resource'    => get_permalink( $post ),
			'tags'        => $this->tags( $post ),
			'timestamp'   => mysql2date( 'c', $post->post_modified_gmt, false ),
			'author'      => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'status'      => 'publish',
		];
		$fm = apply_filters( 'okf_frontmatter', $fm, $post );

		$html = apply_filters( 'the_content', $post->post_content );
		$body = $this->convert_internal_links( OKF_Markdown::convert( $html ) );

		$rel = $this->post_path( $post );

		// Slug/tipe berubah → hapus file lama.
		$old = get_post_meta( $post->ID, '_okf_path', true );
		if ( $old && $old !== $rel ) {
			@unlink( $this->base_dir . '/' . $old );
			$this->log( "moved {$old} -> {$rel}" );
		}
		update_post_meta( $post->ID, '_okf_path', $rel );

		$this->write( $rel, $this->yaml( $fm ) . $body );
		$this->log( "updated {$rel} ({$post->post_title})" );
		return true;
	}

	public function delete_post_file( WP_Post $post ): void {
		$rel = get_post_meta( $post->ID, '_okf_path', true ) ?: $this->post_path( $post );
		if ( @unlink( $this->base_dir . '/' . $rel ) ) {
			$this->log( "deleted {$rel}" );
		}
		delete_post_meta( $post->ID, '_okf_path' );
	}

	/* ---------- Index ---------- */

	public function regenerate_indexes( array $post_types = [] ): void {
		foreach ( $post_types ?: $this->settings['post_types'] as $pt ) {
			$this->section_index( $pt );
		}
		$this->root_index();
	}

	private function root_index(): void {
		$fm = [
			'type'        => 'Website',
			'title'       => get_bloginfo( 'name' ),
			'description' => $this->settings['site_description'] ?: get_bloginfo( 'description' ),
			'resource'    => home_url( '/' ),
			'timestamp'   => gmdate( 'c' ),
		];

		$body  = '# ' . get_bloginfo( 'name' ) . "\n\n";
		$desc  = $this->settings['site_description'] ?: get_bloginfo( 'description' );
		$body .= $desc ? $desc . "\n\n" : '';
		$body .= "## Bagian\n\n";
		foreach ( $this->settings['post_types'] as $pt ) {
			$obj = get_post_type_object( $pt );
			if ( $obj ) {
				$body .= sprintf( "- [%s](/%s/index.md)\n", $obj->labels->name, $this->dir_for( $pt ) );
			}
		}

		$this->write( 'index.md', $this->yaml( $fm ) . $body );
	}

	private function section_index( string $post_type ): void {
		$obj = get_post_type_object( $post_type );
		if ( ! $obj ) {
			return;
		}
		$dir = $this->dir_for( $post_type );

		$ids = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		$fm = [
			'type'        => 'Collection',
			'title'       => $obj->labels->name,
			'description' => sprintf( 'Daftar %s di situs %s.', strtolower( $obj->labels->name ), get_bloginfo( 'name' ) ),
			'timestamp'   => gmdate( 'c' ),
		];

		$body = '# ' . $obj->labels->name . "\n\n";
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p || apply_filters( 'okf_exclude_post', false, $p ) ) {
				continue;
			}
			$body .= sprintf( "- [%s](/%s) — %s\n", $p->post_title, $this->post_path( $p ), $this->description( $p ) );
		}

		$this->write( $dir . '/index.md', $this->yaml( $fm ) . $body );
	}

	/* ---------- Rebuild penuh ---------- */

	/** Bersihkan bundle lama, kembalikan total post yang akan diproses. */
	public function prepare_rebuild(): int {
		$this->rrmdir( $this->base_dir );
		wp_mkdir_p( $this->base_dir );
		return count( $this->post_ids() );
	}

	/** Proses satu irisan post; aman dipanggil berulang lintas request (AJAX/CLI). */
	public function rebuild_batch( int $offset, int $limit ): int {
		$done = 0;
		foreach ( array_slice( $this->post_ids(), $offset, $limit ) as $id ) {
			$post = get_post( $id );
			if ( $post && $this->generate_post( $post ) ) {
				$done++;
			}
		}
		wp_cache_flush(); // lepas object cache agar memori tidak menumpuk
		return $done;
	}

	public function finish_rebuild( int $count ): void {
		$this->regenerate_indexes();
		update_option( 'okf_status', [ 'count' => $count, 'built_at' => time() ], false );
	}

	/**
	 * Rebuild penuh satu proses — dipakai WP-CLI (tanpa batas waktu request).
	 * @param callable|null $progress fn(int $done, int $total)
	 */
	public function rebuild_all( ?callable $progress = null ): int {
		$total = $this->prepare_rebuild();
		$done  = 0;
		for ( $offset = 0; $offset < $total; $offset += 50 ) {
			$done += $this->rebuild_batch( $offset, 50 );
			if ( $progress ) {
				$progress( min( $offset + 50, $total ), $total );
			}
		}
		$this->finish_rebuild( $done );
		return $done;
	}

	private function post_ids(): array {
		return get_posts( [
			'post_type'      => $this->settings['post_types'],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );
	}

	/* ---------- Validasi ---------- */

	/** @return string[] daftar error, kosong = valid */
	public function validate(): array {
		$errors = [];
		$files  = $this->all_md_files( $this->base_dir );
		if ( ! $files ) {
			return [ 'Bundle kosong — jalankan rebuild terlebih dahulu.' ];
		}
		foreach ( $files as $file ) {
			$rel     = ltrim( str_replace( [ $this->base_dir, '\\' ], [ '', '/' ], $file ), '/' );
			$content = (string) file_get_contents( $file );
			if ( ! preg_match( '/^---\n(.*?)\n---\n/s', $content, $m ) ) {
				$errors[] = "{$rel}: frontmatter YAML tidak ditemukan.";
				continue;
			}
			if ( ! preg_match( '/^type:\s*\S/m', $m[1] ) ) {
				$errors[] = "{$rel}: field wajib `type` tidak ada.";
			}
		}
		return $errors;
	}

	/* ---------- Helper mapping ---------- */

	public function post_path( WP_Post $post ): string {
		return $this->dir_for( $post->post_type ) . '/' . $post->post_name . '.md';
	}

	private function dir_for( string $post_type ): string {
		return [ 'post' => 'posts', 'page' => 'pages' ][ $post_type ] ?? $post_type;
	}

	private function type_label( string $post_type ): string {
		$obj = get_post_type_object( $post_type );
		return $obj ? $obj->labels->singular_name : ucfirst( $post_type );
	}

	private function description( WP_Post $post ): string {
		$text = $post->post_excerpt ?: wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
		return mb_strlen( $text ) > 155 ? mb_substr( $text, 0, 152 ) . '…' : $text;
	}

	private function tags( WP_Post $post ): array {
		$tags = [];
		foreach ( $this->settings['taxonomies'] as $tax ) {
			$terms = get_the_terms( $post, $tax );
			if ( is_array( $terms ) ) {
				$tags = array_merge( $tags, wp_list_pluck( $terms, 'slug' ) );
			}
		}
		return array_values( array_unique( $tags ) );
	}

	/** Link internal di markdown → link relatif OKF, membentuk graph antar-dokumen. */
	private function convert_internal_links( string $md ): string {
		$home = preg_quote( untrailingslashit( home_url() ), '#' );
		return preg_replace_callback(
			'#\((' . $home . '/[^)\s"]*)\)#u',
			function ( $m ) {
				$post_id = url_to_postid( $m[1] );
				if ( ! $post_id ) {
					return $m[0];
				}
				$post = get_post( $post_id );
				if ( ! $post
					|| $post->post_status !== 'publish'
					|| ! in_array( $post->post_type, $this->settings['post_types'], true ) ) {
					return $m[0];
				}
				return '(/' . $this->post_path( $post ) . ')';
			},
			$md
		);
	}

	/* ---------- Helper file ---------- */

	private function write( string $rel, string $content ): void {
		$file = $this->base_dir . '/' . $rel;
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, $content );
	}

	private function log( string $line ): void {
		if ( empty( $this->settings['enable_log'] ) ) {
			return;
		}
		wp_mkdir_p( $this->base_dir );
		file_put_contents(
			$this->base_dir . '/log.md',
			'- ' . gmdate( 'c' ) . ' — ' . $line . "\n",
			FILE_APPEND
		);
	}

	private function all_md_files( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$out = [];
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && $f->getExtension() === 'md' ) {
				$out[] = $f->getPathname();
			}
		}
		return $out;
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
	}

	/* ---------- YAML frontmatter ---------- */

	private function yaml( array $fm ): string {
		$out = "---\n";
		foreach ( $fm as $k => $v ) {
			if ( is_array( $v ) ) {
				$out .= $k . ': [' . implode( ', ', array_map( [ $this, 'yaml_scalar' ], $v ) ) . "]\n";
			} else {
				$out .= $k . ': ' . $this->yaml_scalar( $v ) . "\n";
			}
		}
		return $out . "---\n\n";
	}

	private function yaml_scalar( mixed $v ): string {
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return (string) $v;
		}
		return '"' . str_replace( [ '\\', '"', "\r", "\n" ], [ '\\\\', '\\"', '', ' ' ], (string) $v ) . '"';
	}
}
