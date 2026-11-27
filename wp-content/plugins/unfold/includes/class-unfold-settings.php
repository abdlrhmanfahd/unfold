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
		$hint = get_option( 'unfold_worker_token_hint' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Unfold settings', 'unfold' ); ?></h1>
			<?php if ( $token ) : ?>
				<div class="notice notice-success"><p><strong><?php esc_html_e( 'New worker token — copy it now, it will not be shown again:', 'unfold' ); ?></strong><br><code style="user-select:all"><?php echo esc_html( $token ); ?></code></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'unfold_settings' ); ?>
				<input type="hidden" name="action" value="unfold_settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="unfold_allowed_domains"><?php esc_html_e( 'Sign-up email domains', 'unfold' ); ?></label></th>
						<td><input type="text" class="regular-text" id="unfold_allowed_domains" name="unfold_allowed_domains" value="<?php echo esc_attr( (string) get_option( 'unfold_allowed_domains', '' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Only emails at these domains can create an account, e.g. kau.edu.sa, stu.kau.edu.sa. Leave empty to allow any email.', 'unfold' ); ?></p></td>
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
		</div>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'unfold_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unfold' ) );
		}
		update_option( 'unfold_allowed_domains', sanitize_text_field( wp_unslash( $_POST['unfold_allowed_domains'] ?? '' ) ) );
		if ( ! empty( $_POST['unfold_rotate_token'] ) ) {
			set_transient( 'unfold_new_token_' . get_current_user_id(), self::rotate_worker_token(), 300 );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Unfold_Post_Type::TYPE . '&page=unfold-settings' ) );
		exit;
	}
}
