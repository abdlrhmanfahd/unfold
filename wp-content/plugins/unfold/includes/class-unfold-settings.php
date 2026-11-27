<?php
/**
 * Unfold settings — administrator only (wp-admin → Projects → Settings).
 * Instructors never see these.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_unfold_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_unfold_test_email', array( __CLASS__, 'test_email' ) );
	}

	/** Worker token: stored only as a hash; the plain value is shown once when generated. */
	public static function rotate_worker_token() {
		$token = 'ufw_' . bin2hex( random_bytes( 24 ) );
		update_option( 'unfold_worker_token_hash', wp_hash_password( $token ), false );
		update_option( 'unfold_worker_token_hint', substr( $token, -4 ), false );
		return $token;
	}

	public static function worker_token_ok( $token ) {
		$hash = get_option( 'unfold_worker_token_hash' );
		return $hash && is_string( $token ) && strlen( $token ) > 20 && wp_check_password( $token, $hash );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . Unfold_Post_Type::TYPE, __( 'Unfold settings', 'unfold' ), __( 'Settings', 'unfold' ), 'manage_options', 'unfold-settings', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		$token = get_transient( 'unfold_new_token_' . get_current_user_id() );
		delete_transient( 'unfold_new_token_' . get_current_user_id() );
		$test_res = get_transient( 'unfold_test_email_' . get_current_user_id() );
		delete_transient( 'unfold_test_email_' . get_current_user_id() );
		$hint    = get_option( 'unfold_worker_token_hint' );
		$domains = (string) get_option( 'unfold_allowed_domains', '' );
		if ( '' === trim( $domains ) ) {
			$domains = implode( ', ', Unfold_Accounts::DEFAULT_DOMAINS );
		}
		$smtp = Unfold_Accounts::smtp_config();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Unfold settings', 'unfold' ); ?></h1>
			<?php if ( $token ) : ?>
				<div class="notice notice-success"><p><strong><?php esc_html_e( 'New worker token — copy it now, it will not be shown again:', 'unfold' ); ?></strong><br><code style="user-select:all"><?php echo esc_html( $token ); ?></code></p></div>
			<?php endif; ?>
			<?php if ( is_array( $test_res ) ) : ?>
				<div class="notice <?php echo ! empty( $test_res['ok'] ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $test_res['message'] ?? '' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'unfold_settings' ); ?>
				<input type="hidden" name="action" value="unfold_settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="unfold_allowed_domains"><?php esc_html_e( 'Sign-up email domains', 'unfold' ); ?></label></th>
						<td><input type="text" class="regular-text" id="unfold_allowed_domains" name="unfold_allowed_domains" value="<?php echo esc_attr( $domains ); ?>">
							<p class="description"><?php esc_html_e( 'Only emails at these domains can create an account (defaults to kau.edu.sa, stu.kau.edu.sa).', 'unfold' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email / SMTP (no Brevo required)', 'unfold' ); ?></th>
						<td>
							<p class="description" style="margin-bottom:8px"><?php esc_html_e( 'Configure university Office 365 (smtp.office365.com:587 TLS), Gmail Workspace (smtp.gmail.com:587 TLS), or an internal lab SMTP relay so verification codes and links reach real KAU inboxes. Constants in wp-config.php override these fields.', 'unfold' ); ?></p>
							<p><label><?php esc_html_e( 'SMTP Host', 'unfold' ); ?><br>
								<input type="text" class="regular-text" name="unfold_smtp_host" placeholder="smtp.office365.com" value="<?php echo esc_attr( $smtp['host'] ); ?>" <?php disabled( defined( 'UNFOLD_SMTP_HOST' ) ); ?>></label></p>
							<p><label><?php esc_html_e( 'Port', 'unfold' ); ?><br>
								<input type="number" class="small-text" name="unfold_smtp_port" value="<?php echo esc_attr( (string) $smtp['port'] ); ?>" <?php disabled( defined( 'UNFOLD_SMTP_PORT' ) ); ?>></label>
								<label style="margin-left:16px"><?php esc_html_e( 'Encryption', 'unfold' ); ?>
								<select name="unfold_smtp_secure" <?php disabled( defined( 'UNFOLD_SMTP_SECURE' ) ); ?>>
									<option value="tls" <?php selected( $smtp['secure'], 'tls' ); ?>>STARTTLS (587)</option>
									<option value="ssl" <?php selected( $smtp['secure'], 'ssl' ); ?>>SSL/TLS (465)</option>
									<option value="none" <?php selected( $smtp['secure'], 'none' ); ?>>None (lab relay)</option>
								</select></label></p>
							<p><label><?php esc_html_e( 'SMTP Username', 'unfold' ); ?><br>
								<input type="text" class="regular-text" name="unfold_smtp_user" autocomplete="off" value="<?php echo esc_attr( $smtp['user'] ); ?>" <?php disabled( defined( 'UNFOLD_SMTP_USER' ) ); ?>></label></p>
							<p><label><?php esc_html_e( 'SMTP Password / App Password', 'unfold' ); ?><br>
								<input type="password" class="regular-text" name="unfold_smtp_pass" autocomplete="new-password" placeholder="<?php echo '' !== $smtp['pass'] ? esc_attr__( 'Saved (leave blank to keep)', 'unfold' ) : ''; ?>" value="" <?php disabled( defined( 'UNFOLD_SMTP_PASS' ) ); ?>></label></p>
							<p><label><?php esc_html_e( 'From Email Address', 'unfold' ); ?><br>
								<input type="email" class="regular-text" name="unfold_smtp_from" placeholder="unfold-lab@kau.edu.sa" value="<?php echo esc_attr( $smtp['from'] ); ?>" <?php disabled( defined( 'UNFOLD_SMTP_FROM' ) ); ?>></label></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Built-in AI Presets (Lab Models)', 'unfold' ); ?></th>
						<td>
							<p class="description" style="margin-bottom:10px"><?php esc_html_e( 'Optional ready-to-use AI models students can pick without entering their own key. API keys are encrypted at rest and never exposed in the browser.', 'unfold' ); ?></p>
							<?php foreach ( Unfold_AI::raw_presets() as $pid => $p ) : ?>
								<div style="border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin-bottom:10px;max-width:620px;background:#fff">
									<p style="margin-top:0"><label><input type="checkbox" name="unfold_ai_presets[<?php echo esc_attr( $pid ); ?>][enabled]" value="1" <?php checked( ! empty( $p['enabled'] ) ); ?>> <strong><?php echo esc_html( $pid ); ?></strong></label></p>
									<p><label><?php esc_html_e( 'Display Label', 'unfold' ); ?><br>
										<input type="text" class="regular-text" name="unfold_ai_presets[<?php echo esc_attr( $pid ); ?>][label]" value="<?php echo esc_attr( $p['label'] ); ?>"></label></p>
									<p><label><?php esc_html_e( 'Provider', 'unfold' ); ?>
										<select name="unfold_ai_presets[<?php echo esc_attr( $pid ); ?>][provider]">
											<option value="gemini" <?php selected( $p['provider'], 'gemini' ); ?>>Gemini</option>
											<option value="ollama" <?php selected( $p['provider'], 'ollama' ); ?>>Ollama (local)</option>
											<option value="openai_compat" <?php selected( $p['provider'], 'openai_compat' ); ?>>OpenAI-Compatible (Groq / OpenRouter)</option>
											<option value="anthropic" <?php selected( $p['provider'], 'anthropic' ); ?>>Anthropic (Claude)</option>
										</select></label>
										<label style="margin-left:12px"><?php esc_html_e( 'Model', 'unfold' ); ?>
										<input type="text" class="regular-text" style="width:220px" name="unfold_ai_presets[<?php echo esc_attr( $pid ); ?>][model]" value="<?php echo esc_attr( $p['model'] ); ?>"></label></p>
									<p><label><?php esc_html_e( 'Base URL (Ollama / OpenAI-compatible)', 'unfold' ); ?><br>
										<input type="url" class="regular-text" name="unfold_ai_presets[<?php echo esc_attr( $pid ); ?>][base_url]" placeholder="https://api.groq.com/openai/v1" value="<?php echo esc_attr( $p['base_url'] ); ?>"></label></p>
									<p style="margin-bottom:0"><label><?php esc_html_e( 'API Key', 'unfold' ); ?><br>
										<input type="password" class="regular-text" name="unfold_ai_presets[<?php echo esc_attr( $pid ); ?>][api_key]" autocomplete="new-password" placeholder="<?php echo '' !== $p['api_key_enc'] ? esc_attr( sprintf( __( 'Saved (…%s) — leave blank to keep', 'unfold' ), $p['key_hint'] ?: '****' ) ) : esc_attr__( 'Optional for local Ollama', 'unfold' ); ?>" value=""></label>
										<?php if ( '' !== $p['api_key_enc'] ) : ?>
											<label style="margin-left:10px"><input type="checkbox" name="unfold_ai_presets[<?php echo esc_attr( $pid ); ?>][clear_key]" value="1"> <?php esc_html_e( 'Clear saved key', 'unfold' ); ?></label>
										<?php endif; ?>
									</p>
								</div>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI extraction worker', 'unfold' ); ?></th>
						<td><p><?php echo $hint ? esc_html( sprintf( __( 'A token ending in …%s is active.', 'unfold' ), $hint ) ) : esc_html__( 'No token yet: the worker cannot connect.', 'unfold' ); ?>
							<?php $seen = (int) get_option( 'unfold_worker_seen', 0 ); echo ' ' . ( $seen && time() - $seen < 120 ? '<strong style="color:#067647">' . esc_html__( 'Worker is running.', 'unfold' ) . '</strong>' : esc_html( $seen ? sprintf( __( 'Worker last seen %s ago (not running).', 'unfold' ), human_time_diff( $seen ) ) : __( 'Worker has never connected.', 'unfold' ) ) ); ?></p>
							<label><input type="checkbox" name="unfold_rotate_token" value="1"> <?php echo $hint ? esc_html__( 'Generate a new token (the old one stops working)', 'unfold' ) : esc_html__( 'Generate a token', 'unfold' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Send test email', 'unfold' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'unfold_test_email' ); ?>
				<input type="hidden" name="action" value="unfold_test_email">
				<input type="email" class="regular-text" name="unfold_test_to" required placeholder="yourname@kau.edu.sa" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
				<?php submit_button( __( 'Send test email', 'unfold' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Lab System Status', 'unfold' ); ?></h2>
			<?php
			$world_seen   = (int) get_option( 'unfold_world_worker_seen', 0 );
			$extract_seen = (int) get_option( 'unfold_worker_seen', 0 );
			$world_auto   = defined( 'UNFOLD_WORLD_PYTHON' ) && defined( 'UNFOLD_WORLD_WORKER' );
			$extract_auto = defined( 'UNFOLD_EXTRACT_PYTHON' ) && defined( 'UNFOLD_EXTRACT_WORKER' );
			?>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr><th><?php esc_html_e( 'Site URL & PHP', 'unfold' ); ?></th><td><code><?php echo esc_html( home_url( '/' ) ); ?></code> · PHP <?php echo esc_html( PHP_VERSION ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Allowed Sign-up Domains', 'unfold' ); ?></th><td><code><?php echo esc_html( implode( ', ', Unfold_Accounts::allowed_domains() ) ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'SMTP Status', 'unfold' ); ?></th><td><?php echo $smtp['configured'] ? '<span style="color:#067647">' . esc_html( sprintf( __( 'Configured (%1$s:%2$d, %3$s)', 'unfold' ), $smtp['host'], $smtp['port'], $smtp['secure'] ) ) . '</span>' : '<span style="color:#B42318">' . esc_html__( 'Not configured yet (set SMTP above so KAU verification emails send).', 'unfold' ) . '</span>'; ?></td></tr>
					<tr><th><?php esc_html_e( '3D World Worker', 'unfold' ); ?></th><td><?php echo esc_html( $world_seen ? sprintf( __( 'Last seen %s ago', 'unfold' ), human_time_diff( $world_seen ) ) : __( 'Not seen yet', 'unfold' ) ); ?> · <?php echo $world_auto ? esc_html__( 'Auto-launch configured', 'unfold' ) : esc_html__( 'Auto-launch not configured in wp-config.php', 'unfold' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'AI Extractor Worker', 'unfold' ); ?></th><td><?php echo esc_html( $extract_seen ? sprintf( __( 'Last seen %s ago', 'unfold' ), human_time_diff( $extract_seen ) ) : __( 'Not seen yet', 'unfold' ) ); ?> · <?php echo $extract_auto ? esc_html__( 'Auto-launch configured', 'unfold' ) : esc_html__( 'Auto-launch not configured in wp-config.php', 'unfold' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'unfold_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unfold' ) );
		}
		$domains = sanitize_text_field( wp_unslash( $_POST['unfold_allowed_domains'] ?? '' ) );
		update_option( 'unfold_allowed_domains', '' !== trim( $domains ) ? $domains : implode( ', ', Unfold_Accounts::DEFAULT_DOMAINS ) );
		if ( ! defined( 'UNFOLD_SMTP_HOST' ) ) {
			update_option( 'unfold_smtp_host', sanitize_text_field( wp_unslash( $_POST['unfold_smtp_host'] ?? '' ) ) );
		}
		if ( ! defined( 'UNFOLD_SMTP_PORT' ) ) {
			$port = absint( $_POST['unfold_smtp_port'] ?? 587 );
			update_option( 'unfold_smtp_port', $port > 0 ? $port : 587 );
		}
		if ( ! defined( 'UNFOLD_SMTP_SECURE' ) ) {
			$sec = sanitize_key( wp_unslash( $_POST['unfold_smtp_secure'] ?? 'tls' ) );
			update_option( 'unfold_smtp_secure', in_array( $sec, array( 'tls', 'ssl', 'none' ), true ) ? $sec : 'tls' );
		}
		if ( ! defined( 'UNFOLD_SMTP_USER' ) ) {
			update_option( 'unfold_smtp_user', sanitize_text_field( wp_unslash( $_POST['unfold_smtp_user'] ?? '' ) ) );
		}
		if ( ! defined( 'UNFOLD_SMTP_PASS' ) ) {
			$pass = is_string( $_POST['unfold_smtp_pass'] ?? null ) ? wp_unslash( $_POST['unfold_smtp_pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( '' !== $pass ) {
				update_option( 'unfold_smtp_pass', $pass, false );
			}
		}
		if ( ! defined( 'UNFOLD_SMTP_FROM' ) ) {
			update_option( 'unfold_smtp_from', sanitize_email( wp_unslash( $_POST['unfold_smtp_from'] ?? '' ) ) );
		}
		$posted_presets = isset( $_POST['unfold_ai_presets'] ) && is_array( $_POST['unfold_ai_presets'] ) ? wp_unslash( $_POST['unfold_ai_presets'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$next_presets   = array();
		foreach ( Unfold_AI::raw_presets() as $pid => $cur_p ) {
			$in          = is_array( $posted_presets[ $pid ] ?? null ) ? $posted_presets[ $pid ] : array();
			$provider    = sanitize_key( (string) ( $in['provider'] ?? $cur_p['provider'] ) );
			if ( ! in_array( $provider, Unfold_AI::BYOK_PROVIDERS, true ) ) {
				$provider = $cur_p['provider'];
			}
			$model       = sanitize_text_field( (string) ( $in['model'] ?? '' ) );
			$api_key_enc = $cur_p['api_key_enc'];
			$key_hint    = $cur_p['key_hint'];
			if ( ! empty( $in['clear_key'] ) ) {
				$api_key_enc = '';
				$key_hint    = '';
			} elseif ( isset( $in['api_key'] ) && is_string( $in['api_key'] ) && '' !== trim( $in['api_key'] ) ) {
				$plain       = trim( $in['api_key'] );
				$api_key_enc = Unfold_AI::encrypt_secret( $plain );
				$key_hint    = Unfold_AI::key_hint( $plain );
			}
			$next_presets[ $pid ] = array(
				'id'          => $pid,
				'enabled'     => ! empty( $in['enabled'] ),
				'label'       => sanitize_text_field( (string) ( $in['label'] ?? $cur_p['label'] ) ) ?: $cur_p['label'],
				'provider'    => $provider,
				'model'       => '' !== $model ? $model : ( Unfold_AI::DEFAULT_MODELS[ $provider ] ?? '' ),
				'base_url'    => Unfold_AI::sanitize_base_url( $in['base_url'] ?? $cur_p['base_url'] ),
				'api_key_enc' => $api_key_enc,
				'key_hint'    => $key_hint,
			);
		}
		Unfold_AI::save_presets( $next_presets );
		if ( ! empty( $_POST['unfold_rotate_token'] ) ) {
			set_transient( 'unfold_new_token_' . get_current_user_id(), self::rotate_worker_token(), 300 );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Unfold_Post_Type::TYPE . '&page=unfold-settings' ) );
		exit;
	}

	public static function test_email() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'unfold_test_email' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unfold' ) );
		}
		$to  = sanitize_email( wp_unslash( $_POST['unfold_test_to'] ?? '' ) );
		$err = '';
		$cb  = static function ( $wp_error ) use ( &$err ) {
			if ( $wp_error instanceof WP_Error ) {
				$err = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $cb );
		$sent = is_email( $to ) && wp_mail( $to, __( 'Unfold SMTP test email', 'unfold' ), __( 'SMTP is working on your Unfold lab server.', 'unfold' ) );
		remove_action( 'wp_mail_failed', $cb );
		set_transient(
			'unfold_test_email_' . get_current_user_id(),
			array(
				'ok'      => $sent,
				'message' => $sent
					? sprintf( __( 'Test email sent to %s.', 'unfold' ), $to )
					: sprintf( __( 'Could not send test email: %s', 'unfold' ), $err ?: __( 'Check SMTP settings or local mail transport.', 'unfold' ) ),
			),
			120
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Unfold_Post_Type::TYPE . '&page=unfold-settings' ) );
		exit;
	}
}
