<?php
/**
 * Project lifecycle, uploads and the extraction job queue.
 *
 * States (meta _unfold_state):
 *   queued      ZIP accepted, waiting for the extraction worker
 *   extracting  a worker has claimed the job
 *   failed      extraction failed (the owner can try again)
 *   review      the owner checks/edits the extracted data
 *   submitted   sent to the instructor
 *   changes     returned by the instructor with notes
 *   approved    published on the public site
 *
 * Every transition is appended to an event log (meta _unfold_events), which the
 * instructor statistics are computed from.
 *
 * Uploaded ZIPs are never unpacked to a public folder. They live in a private
 * directory with a random name; entries are read through ZipArchive streams, and
 * only referenced images / CSV / video files are copied into the media library,
 * through WordPress' own file-type checks.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Workflow {

	const STATES = array( 'queued', 'extracting', 'failed', 'review', 'submitted', 'changes', 'approved' );

	/** Editable by the project owner in these states. */
	const OWNER_EDITABLE = array( 'failed', 'review', 'changes' );

	const MAX_ZIP_BYTES      = 1073741824; // 1 GB compressed.
	const MAX_UNPACKED_BYTES = 3221225472; // 3 GB uncompressed (zip-bomb guard).
	const MAX_ENTRIES        = 20000;
	const MAX_ATTACH_BYTES   = 26214400;   // 25 MB per file copied to the media library.
	const CHUNK_BYTES        = 8388608;    // 8 MB upload chunks.
	const CLAIM_TIMEOUT      = 1800;       // A claimed job is re-queued after 30 min without a result.

	/** Extensions never copied anywhere (only listed in the manifest). */
	const BLOCKED_EXT = array( 'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht', 'htaccess', 'htpasswd', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'ps1', 'vbs', 'scr', 'sh', 'jar', 'svg', 'html', 'htm' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'legacy_states' ), 30 );
	}

	/* ---------------------------------------------------------------------
	 * State & events
	 * ------------------------------------------------------------------ */

	public static function state( $post_id ) {
		$s = get_post_meta( $post_id, '_unfold_state', true );
		if ( ! $s ) {
			return 'publish' === get_post_status( $post_id ) ? 'approved' : 'review';
		}
		return $s;
	}

	/** Projects made before the portals (imported by CLI) count as approved. */
	public static function legacy_states() {
		if ( get_option( 'unfold_legacy_states_done' ) ) {
			return;
		}
		foreach ( get_posts( array( 'post_type' => Unfold_Post_Type::TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			if ( ! get_post_meta( $id, '_unfold_state', true ) ) {
				update_post_meta( $id, '_unfold_state', 'publish' === get_post_status( $id ) ? 'approved' : 'review' );
			}
		}
		update_option( 'unfold_legacy_states_done', 1 );
	}

	/** @return array[] events, oldest first */
	public static function events( $post_id ) {
		$e = get_post_meta( $post_id, '_unfold_events', true );
		return is_array( $e ) ? $e : array();
	}

	public static function log( $post_id, $action, $note = '', $extra = array() ) {
		$events   = self::events( $post_id );
		$events[] = array_merge(
			array(
				't'      => time(),
				'user'   => get_current_user_id(),
				'action' => $action,
				'note'   => (string) $note,
			),
			$extra
		);
		update_post_meta( $post_id, '_unfold_events', $events );
	}

	public static function set_state( $post_id, $state, $note = '' ) {
		if ( ! in_array( $state, self::STATES, true ) ) {
			return;
		}
		$from = self::state( $post_id );
		update_post_meta( $post_id, '_unfold_state', $state );
		self::log( $post_id, 'state', $note, array( 'from' => $from, 'to' => $state ) );

		// Only approved projects are public.
		$want = 'approved' === $state ? 'publish' : ( 'submitted' === $state ? 'pending' : 'draft' );
		if ( get_post_status( $post_id ) !== $want ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => $want ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------ */

	public static function is_owner( $post_id, $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		return $user_id && (int) get_post_field( 'post_author', $post_id ) === (int) $user_id;
	}

	/** Whether the project author can review projects directly (instructor / admin). */
	public static function author_is_reviewer( $post_id ) {
		$author_id = (int) get_post_field( 'post_author', $post_id );
		return $author_id && user_can( $author_id, Unfold_Accounts::CAP_REVIEW );
	}

	public static function can_view( $post_id ) {
		return Unfold_Post_Type::TYPE === get_post_type( $post_id )
			&& ( current_user_can( Unfold_Accounts::CAP_REVIEW ) || ( current_user_can( Unfold_Accounts::CAP_SUBMIT ) && self::is_owner( $post_id ) ) );
	}

	public static function can_edit( $post_id ) {
		if ( current_user_can( Unfold_Accounts::CAP_REVIEW ) ) {
			return true;
		}
		return self::can_view( $post_id ) && in_array( self::state( $post_id ), self::OWNER_EDITABLE, true );
	}

	/* ---------------------------------------------------------------------
	 * Private storage
	 * ------------------------------------------------------------------ */

	public static function private_root() {
		$dir = WP_CONTENT_DIR . '/unfold-private';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Deny web access on Apache/IIS; the random sub-folder names protect other servers.
		foreach ( array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
			'index.php'  => "<?php // Silence.\n",
		) as $f => $c ) {
			if ( ! file_exists( "$dir/$f" ) ) {
				file_put_contents( "$dir/$f", $c ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return $dir;
	}

	public static function project_dir( $post_id ) {
		$key = get_post_meta( $post_id, '_unfold_store_key', true );
		if ( ! $key ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_post_meta( $post_id, '_unfold_store_key', $key );
		}
		$dir = self::private_root() . '/' . $key;
		wp_mkdir_p( $dir );
		return $dir;
	}

	/** True when the AI reader polled the site in the last two minutes. */
	/** The AI reader is polling now, or the site can start it by itself (launch_extractor). */
	public static function reader_available() {
		return self::reader_running() || ( defined( 'UNFOLD_EXTRACT_PYTHON' ) && defined( 'UNFOLD_EXTRACT_WORKER' ) && function_exists( 'popen' ) );
	}

	/**
	 * How long reading a project has taken here (median of finished jobs, seconds), so the page can
	 * say "usually about N min" from real runs. Null until there is at least one.
	 */
	public static function typical_read_seconds() {
		$cached = get_transient( 'unfold_typical_read' );
		if ( false !== $cached ) {
			return $cached ? (int) $cached : null;
		}
		$d = array();
		foreach ( get_posts( array( 'post_type' => Unfold_Post_Type::TYPE, 'post_status' => 'any', 'numberposts' => 200, 'fields' => 'ids', 'meta_key' => '_unfold_job' ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$j = get_post_meta( $id, '_unfold_job', true );
			if ( is_array( $j ) && ! empty( $j['finished_at'] ) && ! empty( $j['claimed_at'] ) && $j['finished_at'] > $j['claimed_at'] ) {
				$d[] = (int) $j['finished_at'] - (int) $j['claimed_at'];
			}
		}
		sort( $d );
		$m = $d ? $d[ intdiv( count( $d ), 2 ) ] : 0;
		set_transient( 'unfold_typical_read', $m, HOUR_IN_SECONDS );
		return $m ? $m : null;
	}

	public static function reader_running() {
		$seen = (int) get_option( 'unfold_worker_seen', 0 );
		return $seen && time() - $seen < 120;
	}

	public static function zip_path( $post_id ) {
		$p = self::project_dir( $post_id ) . '/upload.zip';
		return is_file( $p ) ? $p : null;
	}

	/* ---------------------------------------------------------------------
	 * Chunked upload
	 * ------------------------------------------------------------------ */

	public static function upload_dir( $upload_id ) {
		return self::private_root() . '/tmp-' . $upload_id;
	}

	/**
	 * Remove uploads that stopped halfway (a closed tab, a lost connection): any tmp-* folder whose
	 * upload record has expired (they last a day) and that has not grown for a day. Runs when a new
	 * upload starts, for projects and 3D worlds alike.
	 */
	public static function purge_stale_uploads() {
		foreach ( (array) glob( self::private_root() . '/tmp-*', GLOB_ONLYDIR ) as $dir ) {
			$id   = substr( basename( $dir ), 4 );
			$part = $dir . '/part';
			$age  = time() - ( is_file( $part ) ? filemtime( $part ) : filemtime( $dir ) );
			if ( $age < DAY_IN_SECONDS || false !== get_transient( 'unfold_upload_' . $id ) ) {
				continue;
			}
			foreach ( (array) glob( $dir . '/*' ) as $f ) {
				if ( is_file( $f ) ) {
					wp_delete_file( $f );
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	public static function start_upload( $name, $size ) {
		self::purge_stale_uploads();
		if ( $size <= 0 || $size > self::MAX_ZIP_BYTES ) {
			return new WP_Error( 'unfold_size', __( 'The ZIP must be smaller than 1 GB.', 'unfold' ), array( 'status' => 400 ) );
		}
		if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'unfold_type', __( 'Upload a .zip file.', 'unfold' ), array( 'status' => 400 ) );
		}
		$id = bin2hex( random_bytes( 16 ) );
		wp_mkdir_p( self::upload_dir( $id ) );
		set_transient(
			'unfold_upload_' . $id,
			array( 'user' => get_current_user_id(), 'name' => sanitize_file_name( $name ), 'size' => (int) $size, 'received' => 0 ),
			DAY_IN_SECONDS
		);
		return array( 'upload_id' => $id, 'chunk_bytes' => self::CHUNK_BYTES );
	}

	public static function put_chunk( $upload_id, $offset, $bytes ) {
		$u = get_transient( 'unfold_upload_' . $upload_id );
		if ( ! $u || (int) $u['user'] !== get_current_user_id() ) {
			return new WP_Error( 'unfold_upload', __( 'Upload not found or expired.', 'unfold' ), array( 'status' => 404 ) );
		}
		if ( (int) $offset !== (int) $u['received'] ) {
			return new WP_Error( 'unfold_offset', __( 'Chunk out of order.', 'unfold' ), array( 'status' => 409, 'expected' => $u['received'] ) );
		}
		$len = strlen( $bytes );
		if ( 0 === $len || $len > self::CHUNK_BYTES || $u['received'] + $len > $u['size'] ) {
			return new WP_Error( 'unfold_chunk', __( 'Invalid chunk.', 'unfold' ), array( 'status' => 400 ) );
		}
		file_put_contents( self::upload_dir( $upload_id ) . '/part', $bytes, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$u['received'] += $len;
		set_transient( 'unfold_upload_' . $upload_id, $u, DAY_IN_SECONDS );
		return array( 'received' => $u['received'] );
	}

	/**
	 * Finish an upload: validate the ZIP, create or update the project, queue extraction.
	 */
	public static function finish_upload( $upload_id, $post_id = 0, $ai = null ) {
		$u = get_transient( 'unfold_upload_' . $upload_id );
		if ( ! $u || (int) $u['user'] !== get_current_user_id() || ! empty( $u['world'] ) ) {
			return new WP_Error( 'unfold_upload', __( 'Upload not found or expired.', 'unfold' ), array( 'status' => 404 ) );
		}
		$part = self::upload_dir( $upload_id ) . '/part';
		if ( (int) $u['received'] !== (int) $u['size'] || ! is_file( $part ) ) {
			return new WP_Error( 'unfold_incomplete', __( 'The upload is incomplete.', 'unfold' ), array( 'status' => 400 ) );
		}

		$manifest = self::inspect_zip( $part );
		if ( is_wp_error( $manifest ) ) {
			self::cleanup_upload( $upload_id );
			return $manifest;
		}

		if ( $post_id ) {
			if ( ! self::can_edit( $post_id ) ) {
				self::cleanup_upload( $upload_id );
				return new WP_Error( 'unfold_forbidden', __( 'You cannot replace the files of this project now.', 'unfold' ), array( 'status' => 403 ) );
			}
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'   => Unfold_Post_Type::TYPE,
					'post_status' => 'draft',
					'post_title'  => preg_replace( '/\.zip$/i', '', $u['name'] ),
					'post_author' => get_current_user_id(),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				self::cleanup_upload( $upload_id );
				return $post_id;
			}
		}

		$dest = self::project_dir( $post_id ) . '/upload.zip';
		rename( $part, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		self::cleanup_upload( $upload_id );

		update_post_meta( $post_id, '_unfold_manifest', $manifest );
		update_post_meta( $post_id, '_unfold_zip_name', $u['name'] );
		update_post_meta( $post_id, '_unfold_report', $manifest['report_guess'] );
		self::log( $post_id, 'upload', $u['name'], array( 'bytes' => $u['size'], 'files' => count( $manifest['files'] ) ) );
		Unfold_AI::save_project_choice( $post_id, $ai, get_current_user_id() );
		self::queue_extraction( $post_id );
		return $post_id;
	}

	private static function cleanup_upload( $upload_id ) {
		$dir = self::upload_dir( $upload_id );
		if ( is_file( "$dir/part" ) ) {
			wp_delete_file( "$dir/part" );
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		delete_transient( 'unfold_upload_' . $upload_id );
	}

	/** Category for the manifest and the extraction prompt. */
	public static function kind( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map = array(
			'report' => array( 'pdf', 'docx', 'doc' ),
			'data'   => array( 'csv', 'tsv', 'mat', 'bag', 'npy', 'npz', 'json', 'parquet', 'xlsx', 'txt', 'log' ),
			'image'  => array( 'png', 'jpg', 'jpeg', 'gif', 'webp' ),
			'video'  => array( 'mp4', 'webm', 'mov' ),
			'code'   => array( 'py', 'm', 'slx', 'mdl', 'ipynb', 'c', 'cpp', 'h', 'hpp', 'js', 'ts', 'java', 'cs', 'rs', 'go', 'yaml', 'yml', 'xml', 'launch', 'md' ),
		);
		foreach ( $map as $k => $exts ) {
			if ( in_array( $ext, $exts, true ) ) {
				return $k;
			}
		}
		return 'other';
	}

	/**
	 * Validate the archive without unpacking it.
	 *
	 * @return array|WP_Error manifest { files: [{path,size,kind}], totals, report_guess }
	 */
	public static function inspect_zip( $file ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'unfold_zip', __( 'The server cannot read ZIP files (ZipArchive missing).', 'unfold' ), array( 'status' => 500 ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $file, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'unfold_zip', __( 'This is not a valid ZIP file.', 'unfold' ), array( 'status' => 400 ) );
		}
		if ( $zip->numFiles > self::MAX_ENTRIES ) {
			$zip->close();
			return new WP_Error( 'unfold_zip', sprintf( __( 'The ZIP has more than %d files.', 'unfold' ), self::MAX_ENTRIES ), array( 'status' => 400 ) );
		}

		$files  = array();
		$total  = 0;
		$totals = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$st = $zip->statIndex( $i );
			if ( ! $st ) {
				continue;
			}
			$name = str_replace( '\\', '/', $st['name'] );
			if ( '/' === substr( $name, -1 ) ) {
				continue; // Directory.
			}
			// Path traversal / absolute paths.
			if ( preg_match( '#(^|/)\.\.(/|$)#', $name ) || preg_match( '#^([a-zA-Z]:|/)#', $name ) ) {
				$zip->close();
				return new WP_Error( 'unfold_zip', __( 'The ZIP contains unsafe file paths.', 'unfold' ), array( 'status' => 400 ) );
			}
			// Skip OS junk and tool folders.
			if ( preg_match( '#(^|/)(__MACOSX|\.git|\.claude|__pycache__|node_modules|\.venv|venv)/#', $name ) || preg_match( '#(^|/)(\.DS_Store|desktop\.ini|Thumbs\.db)$#i', $name ) ) {
				continue;
			}
			// Symlinks (Unix mode in the external attributes).
			$opsys = 0;
			$attr  = 0;
			if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && ZipArchive::OPSYS_UNIX === $opsys && 0120000 === ( ( $attr >> 16 ) & 0170000 ) ) {
				continue;
			}
			$total += (int) $st['size'];
			if ( $total > self::MAX_UNPACKED_BYTES ) {
				$zip->close();
				return new WP_Error( 'unfold_zip', __( 'The ZIP unpacks to more than 3 GB.', 'unfold' ), array( 'status' => 400 ) );
			}
			$kind              = self::kind( $name );
			$ext               = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$files[]           = array(
				'path'    => $name,
				'size'    => (int) $st['size'],
				'kind'    => $kind,
				'blocked' => in_array( $ext, self::BLOCKED_EXT, true ),
			);
			$totals[ $kind ]   = ( $totals[ $kind ] ?? 0 ) + 1;
		}
		$zip->close();

		if ( ! $files ) {
			return new WP_Error( 'unfold_zip', __( 'The ZIP is empty.', 'unfold' ), array( 'status' => 400 ) );
		}
		return array(
			'files'        => $files,
			'totals'       => $totals,
			'bytes'        => $total,
			'report_guess' => self::guess_report( $files ),
		);
	}

	/** Most likely project report: a PDF/DOCX named like a report, not a template or brief. */
	public static function guess_report( $files ) {
		$best = null;
		$best_score = -INF;
		foreach ( $files as $f ) {
			if ( 'report' !== $f['kind'] ) {
				continue;
			}
			$n     = strtolower( $f['path'] );
			$score = 0;
			$score += preg_match( '/report/', $n ) ? 40 : 0;
			$score += preg_match( '/team|group|final|project|problem/', $n ) ? 15 : 0;
			$score -= preg_match( '/requirement|template|guide|instruction|activity|rubric|lab_guide|weekly|brief/', $n ) ? 50 : 0;
			$score += '.pdf' === substr( $n, -4 ) ? 8 : 0;   // PDFs keep figures and page numbers.
			$score += preg_match( '#(^|/)report/#', $n ) ? 6 : 0;
			$score += min( 10, $f['size'] / 200000 );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $f['path'];
			}
		}
		return $best;
	}

	/* ---------------------------------------------------------------------
	 * Extraction jobs (pulled by the external worker)
	 * ------------------------------------------------------------------ */

	public static function queue_extraction( $post_id ) {
		update_post_meta( $post_id, '_unfold_job', array( 'id' => wp_generate_uuid4(), 'queued_at' => time(), 'claimed_at' => null, 'error' => null ) );
		self::set_state( $post_id, 'queued' );
		self::launch_extractor();
	}

	/**
	 * Start the AI reader (workers/extractor/unfold_worker.py) on this computer when a job is queued, so
	 * nothing has to keep running between uploads. It handles every waiting job, then exits.
	 * Needs UNFOLD_EXTRACT_PYTHON and UNFOLD_EXTRACT_WORKER in wp-config.php; the worker finds its
	 * own token and AI credentials (never passed through WordPress). Skipped while a worker is
	 * already polling (a separate lab server, or one started a moment ago).
	 */
	public static function launch_extractor() {
		$is_win = ( 'Windows' === PHP_OS_FAMILY );
		if ( ! defined( 'UNFOLD_EXTRACT_PYTHON' ) || ! defined( 'UNFOLD_EXTRACT_WORKER' ) ) {
			return false;
		}
		if ( $is_win ? ! function_exists( 'popen' ) : ! function_exists( 'exec' ) ) {
			return false;
		}
		// When PHP may only read inside the site (open_basedir, as on WordPress Studio) it cannot look
		// at the Python files, but it can still start them.
		if ( '' === (string) ini_get( 'open_basedir' ) && ( ! is_file( UNFOLD_EXTRACT_PYTHON ) || ! is_file( UNFOLD_EXTRACT_WORKER ) ) ) {
			return false;
		}
		if ( time() - (int) get_option( 'unfold_worker_seen', 0 ) < 30 || get_transient( 'unfold_extractor_started' ) ) {
			return false;
		}
		set_transient( 'unfold_extractor_started', 1, 30 );
		$log = WP_CONTENT_DIR . '/unfold-private/extractor.log';
		if ( $is_win ) {
			$q   = static function ( $a ) {
				return '"' . str_replace( array( '"', '&', '|', '<', '>', '^', '%' ), '', (string) $a ) . '"';
			};
			$run = $q( UNFOLD_EXTRACT_PYTHON ) . ' -u ' . $q( UNFOLD_EXTRACT_WORKER ) . ' --until-empty > ' . $q( $log ) . ' 2>&1';
			$url = str_replace( array( '"', '&', '|', '<', '>', '^', '%' ), '', esc_url_raw( home_url() ) );
			pclose( popen( 'start "" /B cmd /C "set "UNFOLD_SITE=' . $url . '" && ' . $run . '"', 'r' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			$run = escapeshellarg( UNFOLD_EXTRACT_PYTHON ) . ' -u ' . escapeshellarg( UNFOLD_EXTRACT_WORKER ) . ' --until-empty > ' . escapeshellarg( $log ) . ' 2>&1';
			exec( 'UNFOLD_SITE=' . escapeshellarg( esc_url_raw( home_url() ) ) . ' nohup ' . $run . ' &' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		return true;
	}

	/** Oldest queued job, or a claimed one that timed out. Marks it claimed. */
	public static function claim_job() {
		$query = static function ( $state ) {
			return get_posts(
				array(
					'post_type'   => Unfold_Post_Type::TYPE,
					'post_status' => 'any',
					'numberposts' => 20,
					'orderby'     => 'modified',
					'order'       => 'ASC',
					'fields'      => 'ids',
					'meta_query'  => array( array( 'key' => '_unfold_state', 'value' => $state ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
		};
		foreach ( array( 'queued', 'extracting' ) as $target_state ) {
			foreach ( $query( $target_state ) as $id ) {
				$job = get_post_meta( $id, '_unfold_job', true );
				if ( ! is_array( $job ) ) {
					continue;
				}
				if ( 'extracting' === $target_state ) {
					if ( time() - (int) ( $job['claimed_at'] ?? 0 ) < self::CLAIM_TIMEOUT ) {
						continue;
					}
					// Rotate job id so a timed-out worker cannot overwrite a newer run.
					$job['id'] = wp_generate_password( 24, false, false );
				}
				$job['claimed_at'] = time();
				update_post_meta( $id, '_unfold_job', $job );
				self::set_state( $id, 'extracting' );
				return array( 'post_id' => $id, 'job' => $job );
			}
		}
		return null;
	}

	public static function job_matches( $post_id, $job_id ) {
		$job = get_post_meta( $post_id, '_unfold_job', true );
		return is_array( $job ) && hash_equals( (string) $job['id'], (string) $job_id ) && 'extracting' === self::state( $post_id );
	}

	/** Worker result: store the extracted data, attach referenced files, hand to the owner. */
	public static function complete_job( $post_id, $data, $meta ) {
		$old = Unfold_Store::get( $post_id );
		if ( is_array( $old ) && ! empty( $old['meta']['credit'] ) && is_array( $data['meta'] ?? null ) ) {
			$data['meta']['credit'] = $old['meta']['credit'];
		}
		$data['review'] = array_merge(
			is_array( $data['review'] ?? null ) ? $data['review'] : array(),
			array(
				'extraction_model'     => sanitize_text_field( $meta['model'] ?? '' ),
				'extracted_at'         => gmdate( 'Y-m-d' ),
				'student_confirmed_at' => null,
				'instructor_approved'  => false,
				'instructor_notes'     => null,
			)
		);
		$raw    = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$errors = Unfold_Store::save( $post_id, $raw );

		if ( ! empty( $data['meta']['title'] ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $data['meta']['title'] ) ) );
		}
		$attached = self::attach_referenced( $post_id, $data );
		$job      = get_post_meta( $post_id, '_unfold_job', true );
		$job      = is_array( $job ) ? $job : array();
		$job['finished_at'] = time();
		$job['usage']       = array_map( 'intval', (array) ( $meta['usage'] ?? array() ) );
		update_post_meta( $post_id, '_unfold_job', $job );
		delete_transient( 'unfold_typical_read' );
		self::log( $post_id, 'extracted', sanitize_text_field( $meta['model'] ?? '' ), array( 'attached' => $attached, 'schema_errors' => count( $errors ) ) );
		self::set_state( $post_id, 'review' );
		return array( 'errors' => $errors, 'attached' => $attached );
	}

	public static function fail_job( $post_id, $message ) {
		$job          = get_post_meta( $post_id, '_unfold_job', true );
		$job          = is_array( $job ) ? $job : array();
		$job['error'] = sanitize_text_field( $message );
		update_post_meta( $post_id, '_unfold_job', $job );
		self::set_state( $post_id, 'failed', $job['error'] );
	}

	/**
	 * Copy images / CSV / video referenced by the project data from the ZIP into the
	 * media library, attached to the project. Matching is by file name.
	 */
	public static function attach_referenced( $post_id, $data ) {
		$zip_file = self::zip_path( $post_id );
		if ( ! $zip_file ) {
			return 0;
		}
		$wanted = array();
		if ( is_array( $data['data_files'] ?? null ) ) {
			foreach ( $data['data_files'] as $f ) {
				if ( is_array( $f ) && isset( $f['file'] ) && is_string( $f['file'] ) ) {
					$wanted[] = $f['file'];
				}
			}
		}
		if ( is_array( $data['results']['media'] ?? null ) ) {
			foreach ( $data['results']['media'] as $m ) {
				if ( is_array( $m ) && isset( $m['file'] ) && is_string( $m['file'] ) ) {
					$wanted[] = $m['file'];
				}
			}
		}
		$wanted = array_filter( array_unique( array_map( 'wp_basename', $wanted ) ) );
		if ( ! $wanted ) {
			return 0;
		}
		$have = Unfold_Render::attachments( $post_id );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $zip_file, ZipArchive::RDONLY ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$count = 0;
		$tmp   = self::project_dir( $post_id ) . '/attach';
		wp_mkdir_p( $tmp );
		foreach ( self::manifest( $post_id )['files'] ?? array() as $f ) {
			$base = wp_basename( $f['path'] );
			if ( ! in_array( $base, $wanted, true ) || isset( $have[ $base ] ) || $f['blocked'] || $f['size'] > self::MAX_ATTACH_BYTES || ! in_array( $f['kind'], array( 'image', 'video', 'data' ), true ) ) {
				continue;
			}
			$type = wp_check_filetype( $base );
			if ( empty( $type['type'] ) ) {
				continue; // Not an allowed upload type in WordPress.
			}
			$stream = $zip->getStream( $f['path'] );
			if ( ! $stream ) {
				continue;
			}
			$path = $tmp . '/' . sanitize_file_name( $base );
			$out  = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $out ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				continue;
			}
			$written  = 0;
			$overflow = false;
			while ( ! feof( $stream ) ) {
				$chunk = fread( $stream, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false === $chunk ) {
					$overflow = true;
					break;
				}
				$written += strlen( $chunk );
				if ( $written > self::MAX_ATTACH_BYTES ) {
					$overflow = true;
					break;
				}
				if ( '' !== $chunk && false === fwrite( $out, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					$overflow = true;
					break;
				}
			}
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $overflow ) {
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
				continue;
			}
			$id = media_handle_sideload( array( 'name' => $base, 'tmp_name' => $path ), $post_id );
			if ( ! is_wp_error( $id ) ) {
				update_post_meta( $id, '_unfold_source_name', $base ); // WordPress may rename duplicates (file-2.png).
				$have[ $base ] = get_post( $id );
				$count++;
			} elseif ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$zip->close();
		@rmdir( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		return $count;
	}

	/** Video formats web browsers cannot play. */
	const UNPLAYABLE_VIDEO = array( 'avi', 'mkv', 'wmv', 'flv', 'mpg', 'mpeg', '3gp' );

	/**
	 * Why a figure or video listed in results.media is not shown (or may not play), by file name.
	 * Read from the ZIP inventory, so it stays right after the owner edits the list.
	 *
	 * @return array file name => plain explanation
	 */
	public static function media_notes( $post_id, $data ) {
		if ( ! is_array( $data['results']['media'] ?? null ) ) {
			return array();
		}
		$have  = Unfold_Render::attachments( $post_id );
		$files = array();
		foreach ( self::manifest( $post_id )['files'] ?? array() as $f ) {
			$files[ wp_basename( $f['path'] ) ] = $f;
		}
		$notes = array();
		foreach ( $data['results']['media'] as $m ) {
			if ( ! is_array( $m ) || ! is_string( $m['file'] ?? null ) ) {
				continue;
			}
			$name = wp_basename( $m['file'] );
			if ( '' === $name ) {
				continue;
			}
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( isset( $have[ $name ] ) ) {
				if ( 'mov' === $ext ) {
					$notes[ $name ] = __( 'MOV videos may not play in every browser. MP4 is the safest format.', 'unfold' );
				}
				continue;
			}
			$f = $files[ $name ] ?? null;
			if ( ! $f ) {
				$notes[ $name ] = __( 'Not found in the ZIP. Check the file name.', 'unfold' );
			} elseif ( in_array( $ext, self::UNPLAYABLE_VIDEO, true ) ) {
				$notes[ $name ] = __( 'This video format does not play in web browsers. Convert it to MP4 and upload again.', 'unfold' );
			} elseif ( ! empty( $f['blocked'] ) || ! in_array( $f['kind'], array( 'image', 'video' ), true ) ) {
				$notes[ $name ] = __( 'Not an image or video Unfold can show (PNG, JPG, GIF, WebP, MP4, WebM).', 'unfold' );
			} elseif ( $f['size'] > self::MAX_ATTACH_BYTES ) {
				/* translators: 1: file size in MB, 2: limit in MB */
				$notes[ $name ] = sprintf( __( 'Too large to show (%1$s MB, the limit is %2$s MB). Upload a shorter or compressed version.', 'unfold' ), number_format_i18n( $f['size'] / 1048576, 1 ), (int) ( self::MAX_ATTACH_BYTES / 1048576 ) );
			} else {
				$notes[ $name ] = __( 'Not copied from the ZIP yet. It is copied when the AI reader finishes.', 'unfold' );
			}
		}
		return $notes;
	}

	public static function manifest( $post_id ) {
		$m = get_post_meta( $post_id, '_unfold_manifest', true );
		return is_array( $m ) ? $m : array( 'files' => array(), 'totals' => array() );
	}

	/* ---------------------------------------------------------------------
	 * Owner & instructor actions
	 * ------------------------------------------------------------------ */

	public static function submit( $post_id ) {
		$data = Unfold_Store::get( $post_id );
		if ( ! $data ) {
			$decoded = json_decode( Unfold_Store::get_raw( $post_id ), true );
			$data    = is_array( $decoded ) ? $decoded : null;
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'unfold_invalid', __( 'Fix the problems in the form before submitting.', 'unfold' ), array( 'status' => 400 ) );
		}
		if ( ! isset( $data['review'] ) || ! is_array( $data['review'] ) ) {
			$data['review'] = array();
		}
		$data['review']['student_confirmed_at'] = gmdate( 'Y-m-d' );
		$encoded                                = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		Unfold_Store::save( $post_id, $encoded );
		if ( null === Unfold_Store::get( $post_id ) ) {
			update_post_meta( $post_id, UNFOLD_META_JSON, wp_slash( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		}
		// Projects uploaded by an instructor/reviewer skip the pending-review queue and publish directly.
		if ( self::author_is_reviewer( $post_id ) || current_user_can( Unfold_Accounts::CAP_REVIEW ) ) {
			return self::review( $post_id, 'approve', '' );
		}
		self::set_state( $post_id, 'submitted' );
		return true;
	}

	public static function review( $post_id, $decision, $note ) {
		$data = Unfold_Store::get( $post_id );
		if ( ! $data ) {
			$decoded = json_decode( Unfold_Store::get_raw( $post_id ), true );
			$data    = is_array( $decoded ) ? $decoded : null;
		}
		if ( 'approve' === $decision ) {
			if ( ! is_array( $data ) ) {
				$data = array(
					'schema_version' => '0.2',
					'meta'           => array( 'title' => get_the_title( $post_id ) ),
					'review'         => array(),
				);
			}
			if ( ! isset( $data['review'] ) || ! is_array( $data['review'] ) ) {
				$data['review'] = array();
			}
			$data['review']['instructor_approved'] = true;
			$data['review']['instructor_notes']    = $note ?: ( $data['review']['instructor_notes'] ?? null );
			$encoded                               = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			Unfold_Store::save( $post_id, $encoded );
			if ( null === Unfold_Store::get( $post_id ) ) {
				update_post_meta( $post_id, UNFOLD_META_JSON, wp_slash( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
			}
			self::log( $post_id, 'review', $note, array( 'decision' => 'approve' ) );
			self::set_state( $post_id, 'approved', $note );
		} elseif ( 'changes' === $decision ) {
			if ( '' === trim( $note ) ) {
				return new WP_Error( 'unfold_note', __( 'Write what needs to change.', 'unfold' ), array( 'status' => 400 ) );
			}
			if ( $data ) {
				$data['review']['instructor_approved'] = false;
				Unfold_Store::save( $post_id, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			}
			self::log( $post_id, 'review', $note, array( 'decision' => 'changes' ) );
			self::set_state( $post_id, 'changes', $note );
		} elseif ( 'unpublish' === $decision ) {
			self::log( $post_id, 'review', $note, array( 'decision' => 'unpublish' ) );
			self::set_state( $post_id, 'changes', $note ?: __( 'Unpublished by the instructor.', 'unfold' ) );
		} else {
			return new WP_Error( 'unfold_decision', __( 'Unknown decision.', 'unfold' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/** Latest note from an instructor asking for changes. */
	public static function last_review_note( $post_id ) {
		foreach ( array_reverse( self::events( $post_id ) ) as $e ) {
			if ( 'review' === $e['action'] ) {
				return $e;
			}
		}
		return null;
	}
}
