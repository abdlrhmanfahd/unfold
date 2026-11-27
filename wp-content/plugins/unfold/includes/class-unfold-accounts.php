<?php
/**
 * Roles, sign-up (with email confirmation) and sign-in.
 *
 * - unfold_member:     uploads and edits own projects in the Workspace.
 * - unfold_instructor: accepts or returns projects (and can upload too).
 *                      Created only by the administrator (wp-admin → Users → Add New),
 *                      who receives WordPress' "set your password" email.
 * Only administrators see wp-admin.
 *
 * Sign-up creates the account locked; it unlocks when the owner clicks the link
 * emailed to the address, which proves the email belongs to them.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Accounts {

	const CAP_SUBMIT = 'unfold_submit';
	const CAP_REVIEW = 'unfold_review';
	const VERIFY_TTL = 2 * DAY_IN_SECONDS;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install_roles' ) );
		add_action( 'init', array( __CLASS__, 'handle_forms' ) );
		add_action( 'init', array( __CLASS__, 'handle_verify_link' ) );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_unverified' ), 10, 2 );
		add_shortcode( 'unfold_login', array( __CLASS__, 'login_shortcode' ) );
		add_shortcode( 'unfold_register', array( __CLASS__, 'register_shortcode' ) );
		add_action( 'phpmailer_init', array( __CLASS__, 'smtp' ) );
	}

	/**
	 * Send mail through an SMTP service (e.g. Brevo, 300 emails/day free) when the
	 * administrator adds these lines to wp-config.php. Without them WordPress' default mail is used.
	 *   define( 'UNFOLD_SMTP_HOST', 'smtp-relay.brevo.com' );
	 *   define( 'UNFOLD_SMTP_USER', '...' );      // Brevo SMTP login
	 *   define( 'UNFOLD_SMTP_PASS', '...' );      // Brevo SMTP key
	 *   define( 'UNFOLD_SMTP_FROM', 'no-reply@your-domain' );
	 */
	public static function smtp( $mailer ) {
		if ( ! defined( 'UNFOLD_SMTP_HOST' ) || ! defined( 'UNFOLD_SMTP_USER' ) || ! defined( 'UNFOLD_SMTP_PASS' ) ) {
			return;
		}
		$mailer->isSMTP();
		$mailer->Host       = UNFOLD_SMTP_HOST;
		$mailer->Port       = defined( 'UNFOLD_SMTP_PORT' ) ? (int) UNFOLD_SMTP_PORT : 587;
		$mailer->SMTPAuth   = true;
		$mailer->SMTPSecure = 'tls';
		$mailer->Username   = UNFOLD_SMTP_USER;
		$mailer->Password   = UNFOLD_SMTP_PASS;
		if ( defined( 'UNFOLD_SMTP_FROM' ) ) {
			$mailer->setFrom( UNFOLD_SMTP_FROM, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), false );
		}
	}

	/** Roles live in the database; reinstall when the plugin version changes. */
	public static function maybe_install_roles() {
		if ( get_option( 'unfold_roles_version' ) === UNFOLD_VERSION ) {
			return;
		}
		$old = get_users( array( 'role' => 'unfold_student', 'fields' => 'ID' ) ); // Earlier name of the member role.
		remove_role( 'unfold_member' );
		remove_role( 'unfold_instructor' );
		add_role( 'unfold_member', __( 'Member', 'unfold' ), array( 'read' => true, self::CAP_SUBMIT => true ) );
		add_role( 'unfold_instructor', __( 'Instructor', 'unfold' ), array( 'read' => true, self::CAP_SUBMIT => true, self::CAP_REVIEW => true ) );
		foreach ( $old as $uid ) {
			( new WP_User( $uid ) )->set_role( 'unfold_member' );
		}
		remove_role( 'unfold_student' );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_SUBMIT );
			$admin->add_cap( self::CAP_REVIEW );
		}
		update_option( 'unfold_roles_version', UNFOLD_VERSION );
	}

	public static function is_portal_only_user( $user = null ) {
		$user = $user ?: wp_get_current_user();
		return $user && $user->exists() && ! user_can( $user, 'manage_options' ) && ( user_can( $user, self::CAP_SUBMIT ) || user_can( $user, self::CAP_REVIEW ) );
	}

	public static function home_for( $user ) {
		if ( user_can( $user, self::CAP_REVIEW ) ) {
			return Unfold_Portals::url( 'instructor' );
		}
		if ( user_can( $user, self::CAP_SUBMIT ) ) {
			return Unfold_Portals::url( 'workspace' );
		}
		return home_url( '/' );
	}

	public static function keep_out_of_admin() {
		if ( wp_doing_ajax() || ! self::is_portal_only_user() ) {
			return;
		}
		wp_safe_redirect( self::home_for( wp_get_current_user() ) );
		exit;
	}

	public static function admin_bar( $show ) {
		return self::is_portal_only_user() ? false : $show;
	}

	public static function login_redirect( $redirect, $requested, $user ) {
		return ( $user instanceof WP_User && self::is_portal_only_user( $user ) ) ? self::home_for( $user ) : $redirect;
	}

	/* ---------------------------------------------------------------------
	 * Email rule and confirmation
	 * ------------------------------------------------------------------ */

	/** @return string[] lower-case domains set by the administrator; empty = any email. */
	public static function allowed_domains() {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( (string) get_option( 'unfold_allowed_domains', '' ) ) ) as $d ) {
			$d = ltrim( trim( $d ), '@' );
			if ( $d ) {
				$out[] = $d;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function email_allowed( $email ) {
		$allowed = self::allowed_domains();
		$domain  = strtolower( substr( strrchr( (string) $email, '@' ), 1 ) );
		return ! $allowed || ( $domain && in_array( $domain, $allowed, true ) );
	}

	public static function is_unverified( $user_id ) {
		return '0' === get_user_meta( $user_id, 'unfold_email_verified', true );
	}

	/** Blocks sign-in until the email is confirmed (accounts made by the admin are not affected). */
	public static function block_unverified( $user, $password = '' ) {
		if ( $user instanceof WP_User && self::is_unverified( $user->ID ) && wp_check_password( (string) $password, $user->user_pass, $user->ID ) ) {
			return new WP_Error( 'unfold_unverified', __( 'Confirm your email first: open the link we sent you.', 'unfold' ) );
		}
		return $user;
	}

	private static function send_verification( $user_id ) {
		$user  = get_userdata( $user_id );
		$token = wp_generate_password( 32, false, false );
		update_user_meta( $user_id, 'unfold_verify_hash', wp_hash_password( $token ) );
		update_user_meta( $user_id, 'unfold_verify_expires', time() + self::VERIFY_TTL );
		$link    = add_query_arg( array( 'unfold_verify' => $user_id . '.' . $token ), Unfold_Portals::url( 'login' ) );
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$message = sprintf( __( "Hi %1\$s,\n\nConfirm your email to finish creating your %2\$s account:\n\n%3\$s\n\nThe link works for 48 hours. If you did not sign up, ignore this email.", 'unfold' ), $user->display_name, $site, $link );
		return wp_mail( $user->user_email, sprintf( __( 'Confirm your email for %s', 'unfold' ), $site ), $message );
	}

	public static function handle_verify_link() {
		if ( empty( $_GET['unfold_verify'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the token is the proof.
			return;
		}
		list( $uid, $token ) = array_pad( explode( '.', sanitize_text_field( wp_unslash( $_GET['unfold_verify'] ) ), 2 ), 2, '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$uid  = absint( $uid );
		$hash = get_user_meta( $uid, 'unfold_verify_hash', true );
		$ok   = $uid && $hash && $token && (int) get_user_meta( $uid, 'unfold_verify_expires', true ) > time() && wp_check_password( $token, $hash );
		if ( ! $ok ) {
			self::flash( 'login_error', __( 'That confirmation link is invalid or has expired. Sign in to get a new one.', 'unfold' ) );
			return;
		}
		update_user_meta( $uid, 'unfold_email_verified', '1' );
		delete_user_meta( $uid, 'unfold_verify_hash' );
		delete_user_meta( $uid, 'unfold_verify_expires' );
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, false, is_ssl() );
		wp_safe_redirect( self::home_for( get_userdata( $uid ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Forms
	 * ------------------------------------------------------------------ */

	private static function flash( $key, $value = null ) {
		static $store = array();
		if ( null !== $value ) {
			$store[ $key ] = $value;
		}
		return $store[ $key ] ?? null;
	}

	public static function handle_forms() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['unfold_form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$form = sanitize_key( wp_unslash( $_POST['unfold_form'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'login' === $form ) {
			self::do_login();
		} elseif ( 'register' === $form ) {
			self::do_register();
		} elseif ( 'resend' === $form ) {
			self::do_resend();
		}
	}

	private static function nonce_ok( $field, $action ) {
		return isset( $_POST[ $field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), $action );
	}

	private static function do_login() {
		if ( ! self::nonce_ok( '_unfold_login', 'unfold_login' ) ) {
			self::flash( 'login_error', __( 'Your session expired. Please try again.', 'unfold' ) );
			return;
		}
		$login = sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) );
		$user  = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'remember'      => ! empty( $_POST['rememberme'] ),
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			if ( 'unfold_unverified' === $user->get_error_code() ) {
				self::flash( 'login_error', $user->get_error_message() );
				self::flash( 'resend_for', $login );
			} else {
				// Same message for unknown user and wrong password (no account probing).
				self::flash( 'login_error', __( 'That email and password do not match an account.', 'unfold' ) );
			}
			return;
		}
		wp_safe_redirect( self::home_for( $user ) );
		exit;
	}

	private static function do_resend() {
		if ( ! self::nonce_ok( '_unfold_resend', 'unfold_resend' ) ) {
			return;
		}
		$login = sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) );
		$user  = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		// Same reply whatever happens, and at most one email per account per minute.
		if ( $user && self::is_unverified( $user->ID ) && ! get_transient( 'unfold_resend_' . $user->ID ) ) {
			set_transient( 'unfold_resend_' . $user->ID, 1, MINUTE_IN_SECONDS );
			self::send_verification( $user->ID );
		}
		self::flash( 'login_notice', __( 'If that account is waiting for confirmation, a new link is on its way.', 'unfold' ) );
	}

	private static function do_register() {
		if ( ! self::nonce_ok( '_unfold_register', 'unfold_register' ) ) {
			self::flash( 'register_errors', array( __( 'Your session expired. Please try again.', 'unfold' ) ) );
			return;
		}
		if ( ! empty( $_POST['uf_website'] ) ) {
			return; // Honeypot: bots fill every field.
		}
		$name  = sanitize_text_field( wp_unslash( $_POST['uf_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['uf_email'] ?? '' ) );
		$pass  = (string) wp_unslash( $_POST['uf_password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$errors = array();
		if ( mb_strlen( $name ) < 2 ) {
			$errors[] = __( 'Enter your full name.', 'unfold' );
		}
		if ( ! is_email( $email ) ) {
			$errors[] = __( 'Enter a valid email address.', 'unfold' );
		} elseif ( ! self::email_allowed( $email ) ) {
			$errors[] = sprintf( __( 'Use an email ending in %s.', 'unfold' ), '@' . implode( ' or @', self::allowed_domains() ) );
		} elseif ( email_exists( $email ) ) {
			$errors[] = __( 'An account with this email already exists. Sign in instead.', 'unfold' );
		}
		if ( strlen( $pass ) < 8 ) {
			$errors[] = __( 'Choose a password of at least 8 characters.', 'unfold' );
		}
		if ( $errors ) {
			self::flash( 'register_errors', $errors );
			self::flash( 'register_values', compact( 'name', 'email' ) );
			return;
		}

		$base  = sanitize_user( strtolower( strtok( $email, '@' ) ), true ) ?: 'member';
		$login = $base;
		for ( $i = 2; username_exists( $login ); $i++ ) {
			$login = $base . $i;
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'display_name' => $name,
				'first_name'   => $name,
				'role'         => 'unfold_member',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			self::flash( 'register_errors', array( $user_id->get_error_message() ) );
			return;
		}
		update_user_meta( $user_id, 'unfold_email_verified', '0' );
		self::send_verification( $user_id );
		self::flash( 'registered', $email );
	}

	/* ---------------------------------------------------------------------
	 * Shortcodes
	 * ------------------------------------------------------------------ */

	private static function card( $title, $intro, $body, $foot ) {
		wp_enqueue_style( 'unfold-portal' );
		return '<div class="unfold uf-auth"><div class="uf-auth-card"><h1 class="uf-auth-title">' . esc_html( $title ) . '</h1>'
			. ( $intro ? '<p class="uf-auth-intro">' . esc_html( $intro ) . '</p>' : '' ) . $body
			. ( $foot ? '<p class="uf-auth-foot">' . $foot . '</p>' : '' ) . '</div></div>';
	}

	public static function login_shortcode() {
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			return self::card(
				sprintf( __( 'Signed in as %s', 'unfold' ), $u->display_name ),
				'',
				'<p><a class="uf-btn" href="' . esc_url( self::home_for( $u ) ) . '">' . esc_html__( 'Continue', 'unfold' ) . '</a></p>',
				'<a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Sign out', 'unfold' ) . '</a>'
			);
		}
		$err    = self::flash( 'login_error' );
		$notice = self::flash( 'login_notice' );
		$resend = self::flash( 'resend_for' );
		$body   = ( $err ? '<div class="uf-alert uf-alert--error" role="alert">' . esc_html( $err ) . '</div>' : '' )
			. ( $notice ? '<div class="uf-alert uf-alert--ok" role="status">' . esc_html( $notice ) . '</div>' : '' );
		if ( $resend ) {
			$body .= '<form method="post" class="uf-inline-form">' . wp_nonce_field( 'unfold_resend', '_unfold_resend', true, false )
				. '<input type="hidden" name="unfold_form" value="resend"><input type="hidden" name="log" value="' . esc_attr( $resend ) . '">'
				. '<button class="uf-linkbtn" type="submit">' . esc_html__( 'Send the confirmation link again', 'unfold' ) . '</button></form>';
		}
		$body .= '<form method="post" class="uf-form" novalidate>'
			. wp_nonce_field( 'unfold_login', '_unfold_login', true, false )
			. '<input type="hidden" name="unfold_form" value="login">'
			. '<label class="uf-field"><span>' . esc_html__( 'Email', 'unfold' ) . '</span><input type="text" name="log" autocomplete="username" required value="' . esc_attr( sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ) ) . '"></label>' // phpcs:ignore WordPress.Security.NonceVerification
			. '<label class="uf-field"><span>' . esc_html__( 'Password', 'unfold' ) . '</span><input type="password" name="pwd" autocomplete="current-password" required></label>'
			. '<label class="uf-check"><input type="checkbox" name="rememberme" value="1"> ' . esc_html__( 'Keep me signed in', 'unfold' ) . '</label>'
			. '<button class="uf-btn uf-btn--block" type="submit">' . esc_html__( 'Sign in', 'unfold' ) . '</button>'
			. '</form>';
		$foot = esc_html__( 'New here?', 'unfold' ) . ' <a href="' . esc_url( Unfold_Portals::url( 'register' ) ) . '">' . esc_html__( 'Create an account', 'unfold' ) . '</a> · <a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'Forgot password?', 'unfold' ) . '</a>'
			. '<br><span class="uf-muted">' . esc_html__( 'Instructors: use the account and password set up from the email the site administrator sent you.', 'unfold' ) . '</span>';
		return self::card( __( 'Sign in', 'unfold' ), '', $body, $foot );
	}

	public static function register_shortcode() {
		if ( is_user_logged_in() ) {
			return self::login_shortcode();
		}
		$done = self::flash( 'registered' );
		if ( $done ) {
			return self::card(
				__( 'Check your email', 'unfold' ),
				'',
				'<p>' . esc_html( sprintf( __( 'We sent a confirmation link to %s. Open it to activate your account; it works for 48 hours.', 'unfold' ), $done ) ) . '</p>',
				'<a href="' . esc_url( Unfold_Portals::url( 'login' ) ) . '">' . esc_html__( 'Back to sign in', 'unfold' ) . '</a>'
			);
		}
		$errors  = (array) self::flash( 'register_errors' );
		$v       = (array) self::flash( 'register_values' );
		$domains = self::allowed_domains();
		$body    = '';
		if ( $errors ) {
			$body .= '<div class="uf-alert uf-alert--error" role="alert"><ul>';
			foreach ( $errors as $e ) {
				$body .= '<li>' . esc_html( $e ) . '</li>';
			}
			$body .= '</ul></div>';
		}
		$body .= '<form method="post" class="uf-form" novalidate>'
			. wp_nonce_field( 'unfold_register', '_unfold_register', true, false )
			. '<input type="hidden" name="unfold_form" value="register">'
			. '<label class="uf-field"><span>' . esc_html__( 'Full name', 'unfold' ) . '</span><input type="text" name="uf_name" autocomplete="name" required value="' . esc_attr( $v['name'] ?? '' ) . '"></label>'
			. '<label class="uf-field"><span>' . esc_html__( 'Email', 'unfold' ) . '</span><input type="email" name="uf_email" autocomplete="email" required value="' . esc_attr( $v['email'] ?? '' ) . '">'
			. ( $domains ? '<small>' . esc_html( sprintf( __( 'Must end with @%s', 'unfold' ), implode( ' or @', $domains ) ) ) . '</small>' : '' ) . '</label>'
			. '<label class="uf-field"><span>' . esc_html__( 'Password', 'unfold' ) . '</span><input type="password" name="uf_password" autocomplete="new-password" minlength="8" required><small>' . esc_html__( 'At least 8 characters.', 'unfold' ) . '</small></label>'
			. '<label class="uf-hp" aria-hidden="true">Website <input type="text" name="uf_website" tabindex="-1" autocomplete="off"></label>'
			. '<button class="uf-btn uf-btn--block" type="submit">' . esc_html__( 'Create account', 'unfold' ) . '</button>'
			. '</form>';
		$foot = esc_html__( 'Already have an account?', 'unfold' ) . ' <a href="' . esc_url( Unfold_Portals::url( 'login' ) ) . '">' . esc_html__( 'Sign in', 'unfold' ) . '</a>';
		return self::card( __( 'Create an account', 'unfold' ), __( 'To upload a project. Instructor accounts are created by the site administrator.', 'unfold' ), $body, $foot );
	}
}
