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

	const CAP_SUBMIT       = 'unfold_submit';
	const CAP_REVIEW       = 'unfold_review';
	const CAP_MANAGE_ROLES = 'unfold_manage_instructors';
	const VERIFY_TTL       = 2 * DAY_IN_SECONDS;
	const OTP_TTL          = 15 * MINUTE_IN_SECONDS;
	const OTP_MAX_ATTEMPTS = 5;
	const DEFAULT_DOMAINS  = array( 'kau.edu.sa', 'stu.kau.edu.sa' );

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
	 * Resolve SMTP settings from wp-config.php constants first, then wp-admin Settings options.
	 * Works with university Office 365 / Exchange, Gmail Workspace, or an internal lab relay.
	 */
	public static function smtp_config() {
		$host   = defined( 'UNFOLD_SMTP_HOST' ) ? (string) UNFOLD_SMTP_HOST : (string) get_option( 'unfold_smtp_host', '' );
		$port   = defined( 'UNFOLD_SMTP_PORT' ) ? (int) UNFOLD_SMTP_PORT : (int) get_option( 'unfold_smtp_port', 587 );
		$user   = defined( 'UNFOLD_SMTP_USER' ) ? (string) UNFOLD_SMTP_USER : (string) get_option( 'unfold_smtp_user', '' );
		$pass   = defined( 'UNFOLD_SMTP_PASS' ) ? (string) UNFOLD_SMTP_PASS : (string) get_option( 'unfold_smtp_pass', '' );
		$from   = defined( 'UNFOLD_SMTP_FROM' ) ? (string) UNFOLD_SMTP_FROM : (string) get_option( 'unfold_smtp_from', '' );
		$secure = defined( 'UNFOLD_SMTP_SECURE' ) ? strtolower( (string) UNFOLD_SMTP_SECURE ) : strtolower( (string) get_option( 'unfold_smtp_secure', 'tls' ) );
		if ( ! in_array( $secure, array( 'tls', 'ssl', 'none' ), true ) ) {
			$secure = 'tls';
		}
		return array(
			'host'       => trim( $host ),
			'port'       => $port > 0 ? $port : 587,
			'user'       => trim( $user ),
			'pass'       => $pass,
			'from'       => trim( $from ),
			'secure'     => $secure,
			'configured' => '' !== trim( $host ) && ( '' === trim( $user ) || '' !== $pass ),
		);
	}

	/** Configure PHPMailer for generic SMTP when configured (constants or Settings). */
	public static function smtp( $mailer ) {
		$cfg = self::smtp_config();
		if ( '' === $cfg['host'] ) {
			return;
		}
		if ( '' !== $cfg['user'] && '' === $cfg['pass'] ) {
			return;
		}
		$mailer->isSMTP();
		$mailer->Host = $cfg['host'];
		$mailer->Port = $cfg['port'];
		if ( 'none' === $cfg['secure'] ) {
			$mailer->SMTPSecure  = '';
			$mailer->SMTPAutoTLS = false;
		} else {
			$mailer->SMTPSecure = $cfg['secure'];
		}
		if ( '' !== $cfg['user'] ) {
			$mailer->SMTPAuth = true;
			$mailer->Username = $cfg['user'];
			$mailer->Password = $cfg['pass'];
		} else {
			$mailer->SMTPAuth = false;
		}
		if ( '' !== $cfg['from'] && is_email( $cfg['from'] ) ) {
			$mailer->setFrom( $cfg['from'], wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), false );
		}
	}

	/** Roles live in the database; reinstall when the plugin version changes. */
	public static function maybe_install_roles() {
		if ( get_option( 'unfold_roles_version' ) === UNFOLD_VERSION && get_role( 'unfold_lead_instructor' ) ) {
			return;
		}
		$old = get_users( array( 'role' => 'unfold_student', 'fields' => 'ID' ) ); // Earlier name of the member role.
		remove_role( 'unfold_member' );
		remove_role( 'unfold_instructor' );
		remove_role( 'unfold_lead_instructor' );
		add_role( 'unfold_member', __( 'Member', 'unfold' ), array( 'read' => true, self::CAP_SUBMIT => true ) );
		add_role( 'unfold_instructor', __( 'Instructor', 'unfold' ), array( 'read' => true, self::CAP_SUBMIT => true, self::CAP_REVIEW => true ) );
		add_role( 'unfold_lead_instructor', __( 'Lead Instructor', 'unfold' ), array( 'read' => true, self::CAP_SUBMIT => true, self::CAP_REVIEW => true, self::CAP_MANAGE_ROLES => true ) );
		foreach ( $old as $uid ) {
			( new WP_User( $uid ) )->set_role( 'unfold_member' );
		}
		remove_role( 'unfold_student' );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_SUBMIT );
			$admin->add_cap( self::CAP_REVIEW );
			$admin->add_cap( self::CAP_MANAGE_ROLES );
		}
		update_option( 'unfold_roles_version', UNFOLD_VERSION );
	}

	public static function can_manage_roles( $user = null ) {
		if ( is_numeric( $user ) ) {
			$user = get_userdata( (int) $user );
		} elseif ( ! $user ) {
			$user = wp_get_current_user();
		}
		return $user instanceof WP_User && $user->exists() && ( user_can( $user, self::CAP_MANAGE_ROLES ) || user_can( $user, 'manage_options' ) );
	}

	public static function role_key_for( $user ) {
		if ( ! ( $user instanceof WP_User ) || ! $user->exists() ) {
			return 'member';
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return 'admin';
		}
		if ( user_can( $user, self::CAP_MANAGE_ROLES ) ) {
			return 'lead';
		}
		if ( user_can( $user, self::CAP_REVIEW ) ) {
			return 'instructor';
		}
		return 'member';
	}

	/** Instructors eligible to receive student submissions for review. */
	public static function reviewers() {
		$users = get_users(
			array(
				'role__in' => array( 'unfold_lead_instructor', 'unfold_instructor', 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);
		$out   = array();
		foreach ( $users as $u ) {
			if ( ! user_can( $u, self::CAP_REVIEW ) ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $u->ID,
				'name'    => $u->display_name ?: $u->user_login,
				'role'    => self::role_key_for( $u ),
				'is_lead' => self::can_manage_roles( $u ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				$rank = array( 'lead' => 0, 'instructor' => 1, 'admin' => 2 );
				$ra   = $rank[ $a['role'] ] ?? 3;
				$rb   = $rank[ $b['role'] ] ?? 3;
				return $ra !== $rb ? ( $ra <=> $rb ) : strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	/** Non-admin portal accounts shown in Lead Instructor's Team & Roles tab. */
	public static function portal_users() {
		$users = get_users(
			array(
				'role__in' => array( 'unfold_lead_instructor', 'unfold_instructor', 'unfold_member' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);
		$me    = get_current_user_id();
		$out   = array();
		foreach ( $users as $u ) {
			if ( user_can( $u, 'manage_options' ) ) {
				continue;
			}
			$out[] = array(
				'id'       => (int) $u->ID,
				'name'     => $u->display_name ?: $u->user_login,
				'email'    => (string) $u->user_email,
				'role'     => self::role_key_for( $u ),
				'wp_role'  => $u->roles[0] ?? 'unfold_member',
				'verified' => ! self::is_unverified( $u->ID ),
				'is_self'  => (int) $u->ID === (int) $me,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				$rank = array( 'lead' => 0, 'instructor' => 1, 'member' => 2 );
				$ra   = $rank[ $a['role'] ] ?? 3;
				$rb   = $rank[ $b['role'] ] ?? 3;
				return $ra !== $rb ? ( $ra <=> $rb ) : strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	/** Grant or revoke instructor/lead roles from the portal (Lead Instructor or Admin only). */
	public static function set_portal_role( $actor_id, $target_user_id, $new_role ) {
		self::maybe_install_roles();
		$actor_id       = (int) $actor_id;
		$target_user_id = (int) $target_user_id;
		if ( ! self::can_manage_roles( $actor_id ) ) {
			return new WP_Error( 'unfold_forbidden', __( 'Only a Lead Instructor or administrator can manage instructor roles.', 'unfold' ), array( 'status' => 403 ) );
		}
		if ( $target_user_id <= 0 || $target_user_id === $actor_id ) {
			return new WP_Error( 'unfold_invalid_user', __( 'Choose another member account to update.', 'unfold' ), array( 'status' => 400 ) );
		}
		$target = get_userdata( $target_user_id );
		if ( ! ( $target instanceof WP_User ) || ! $target->exists() ) {
			return new WP_Error( 'unfold_missing_user', __( 'User not found.', 'unfold' ), array( 'status' => 404 ) );
		}
		if ( user_can( $target, 'manage_options' ) ) {
			return new WP_Error( 'unfold_protected', __( 'Site administrators can only be changed in wp-admin.', 'unfold' ), array( 'status' => 403 ) );
		}
		$is_admin = user_can( $actor_id, 'manage_options' );
		if ( user_can( $target, self::CAP_MANAGE_ROLES ) && ! $is_admin ) {
			return new WP_Error( 'unfold_protected', __( 'Only a site administrator can change a Lead Instructor.', 'unfold' ), array( 'status' => 403 ) );
		}
		$allowed = $is_admin
			? array( 'unfold_member', 'unfold_instructor', 'unfold_lead_instructor' )
			: array( 'unfold_member', 'unfold_instructor' );
		if ( ! in_array( $new_role, $allowed, true ) ) {
			return new WP_Error( 'unfold_role', __( 'Invalid role choice.', 'unfold' ), array( 'status' => 400 ) );
		}
		if ( 'unfold_member' !== $new_role && self::is_unverified( $target_user_id ) ) {
			return new WP_Error( 'unfold_unverified', __( 'Verify this account email before granting instructor access.', 'unfold' ), array( 'status' => 400 ) );
		}
		$target->set_role( $new_role );
		return array(
			'id'      => (int) $target->ID,
			'name'    => $target->display_name ?: $target->user_login,
			'role'    => self::role_key_for( $target ),
			'wp_role' => $new_role,
		);
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

	/** @return string[] lower-case domains set by the administrator, defaulting to KAU domains. */
	public static function allowed_domains() {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( (string) get_option( 'unfold_allowed_domains', '' ) ) ) as $d ) {
			$d = ltrim( trim( $d ), '@' );
			if ( $d ) {
				$out[] = $d;
			}
		}
		return $out ? array_values( array_unique( $out ) ) : self::DEFAULT_DOMAINS;
	}

	public static function email_allowed( $email ) {
		$email = trim( (string) $email );
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}
		$allowed = self::allowed_domains();
		$at      = strrchr( $email, '@' );
		$domain  = $at ? strtolower( substr( $at, 1 ) ) : '';
		return '' !== $domain && in_array( $domain, $allowed, true );
	}

	public static function is_unverified( $user_id ) {
		return '0' === get_user_meta( $user_id, 'unfold_email_verified', true );
	}

	/** Blocks sign-in until the email is confirmed (accounts made by the admin are not affected). */
	public static function block_unverified( $user, $password = '' ) {
		if ( $user instanceof WP_User && self::is_unverified( $user->ID ) && wp_check_password( (string) $password, $user->user_pass, $user->ID ) ) {
			return new WP_Error( 'unfold_unverified', __( 'Confirm your email first: open the link or enter the 6-digit code we sent you.', 'unfold' ) );
		}
		return $user;
	}

	private static function send_verification( $user_id ) {
		$user  = get_userdata( $user_id );
		$token = wp_generate_password( 32, false, false );
		$otp   = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		update_user_meta( $user_id, 'unfold_verify_hash', wp_hash_password( $token ) );
		update_user_meta( $user_id, 'unfold_verify_expires', time() + self::VERIFY_TTL );
		update_user_meta( $user_id, 'unfold_otp_hash', wp_hash_password( $otp ) );
		update_user_meta( $user_id, 'unfold_otp_expires', time() + self::OTP_TTL );
		update_user_meta( $user_id, 'unfold_otp_attempts', 0 );
		$link    = add_query_arg( array( 'unfold_verify' => $user_id . '.' . $token ), Unfold_Portals::url( 'login' ) );
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$message = sprintf(
			__( "Hi %1\$s,\n\nYour %2\$s verification code is: %3\$s (expires in 15 minutes).\n\nOr confirm your email by opening this link (valid for 48 hours):\n%4\$s\n\nIf you did not sign up, ignore this email.", 'unfold' ),
			$user->display_name,
			$site,
			$otp,
			$link
		);
		return wp_mail( $user->user_email, sprintf( __( 'Confirm your email for %s', 'unfold' ), $site ), $message );
	}

	private static function clear_verification_meta( $user_id ) {
		delete_user_meta( $user_id, 'unfold_verify_hash' );
		delete_user_meta( $user_id, 'unfold_verify_expires' );
		delete_user_meta( $user_id, 'unfold_otp_hash' );
		delete_user_meta( $user_id, 'unfold_otp_expires' );
		delete_user_meta( $user_id, 'unfold_otp_attempts' );
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
		self::clear_verification_meta( $uid );
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

	private static function post_str( $key ) {
		return ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	}

	public static function handle_forms() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || '' === self::post_str( 'unfold_form' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$form = sanitize_key( self::post_str( 'unfold_form' ) );
		if ( 'login' === $form ) {
			self::do_login();
		} elseif ( 'register' === $form ) {
			self::do_register();
		} elseif ( 'resend' === $form ) {
			self::do_resend();
		} elseif ( 'verify_otp' === $form ) {
			self::do_verify_otp();
		} elseif ( 'forgot' === $form ) {
			self::do_forgot();
		} elseif ( 'reset' === $form ) {
			self::do_reset();
		}
	}

	private static function nonce_ok( $field, $action ) {
		$val = self::post_str( $field );
		return '' !== $val && wp_verify_nonce( sanitize_text_field( $val ), $action );
	}

	private static function do_login() {
		if ( ! self::nonce_ok( '_unfold_login', 'unfold_login' ) ) {
			self::flash( 'login_error', __( 'Your session expired. Please try again.', 'unfold' ) );
			return;
		}
		$login = sanitize_text_field( self::post_str( 'log' ) );
		$user  = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => self::post_str( 'pwd' ),
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
		$login = sanitize_text_field( self::post_str( 'log' ) );
		$user  = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		// Same reply whatever happens, and at most one email per account per minute.
		if ( $user && self::is_unverified( $user->ID ) && ! get_transient( 'unfold_resend_' . $user->ID ) ) {
			set_transient( 'unfold_resend_' . $user->ID, 1, MINUTE_IN_SECONDS );
			self::send_verification( $user->ID );
		}
		self::flash( 'login_notice', __( 'If that account is waiting for confirmation, a new link and code are on their way.', 'unfold' ) );
		if ( '' !== $login ) {
			self::flash( 'verify_email', $login );
		}
	}

	private static function do_verify_otp() {
		if ( ! self::nonce_ok( '_unfold_verify_otp', 'unfold_verify_otp' ) ) {
			self::flash( 'verify_error', __( 'Your session expired. Please try again.', 'unfold' ) );
			return;
		}
		$login = sanitize_text_field( self::post_str( 'log' ) );
		$otp   = preg_replace( '/\s+/', '', self::post_str( 'otp' ) );
		self::flash( 'verify_email', $login );
		$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		if ( ! $user || ! self::is_unverified( $user->ID ) ) {
			self::flash( 'verify_error', __( 'Invalid or expired verification code.', 'unfold' ) );
			return;
		}
		$attempts = (int) get_user_meta( $user->ID, 'unfold_otp_attempts', true );
		if ( $attempts >= self::OTP_MAX_ATTEMPTS ) {
			self::flash( 'verify_error', __( 'Too many wrong attempts. Request a new confirmation code.', 'unfold' ) );
			self::flash( 'resend_for', $login );
			return;
		}
		$hash    = get_user_meta( $user->ID, 'unfold_otp_hash', true );
		$expires = (int) get_user_meta( $user->ID, 'unfold_otp_expires', true );
		if ( ! $hash || ! $otp || $expires <= time() || ! wp_check_password( $otp, $hash ) ) {
			update_user_meta( $user->ID, 'unfold_otp_attempts', $attempts + 1 );
			self::flash( 'verify_error', __( 'That verification code is invalid or expired. Check your KAU inbox or request a new code.', 'unfold' ) );
			self::flash( 'resend_for', $login );
			return;
		}
		update_user_meta( $user->ID, 'unfold_email_verified', '1' );
		self::clear_verification_meta( $user->ID );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		wp_safe_redirect( self::home_for( $user ) );
		exit;
	}

	private static function do_register() {
		if ( ! self::nonce_ok( '_unfold_register', 'unfold_register' ) ) {
			self::flash( 'register_errors', array( __( 'Your session expired. Please try again.', 'unfold' ) ) );
			return;
		}
		if ( ! empty( $_POST['uf_website'] ) ) {
			return; // Honeypot: bots fill every field.
		}
		$name  = sanitize_text_field( self::post_str( 'uf_name' ) );
		$email = sanitize_email( self::post_str( 'uf_email' ) );
		$pass  = self::post_str( 'uf_password' );

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
		if ( ! self::send_verification( $user_id ) ) {
			self::flash( 'register_errors', array( __( 'Account created, but the confirmation email could not be sent. Please try resending from the sign-in page.', 'unfold' ) ) );
			return;
		}
		self::flash( 'registered', $email );
	}

	private static function do_forgot() {
		if ( ! self::nonce_ok( '_unfold_forgot', 'unfold_forgot' ) ) {
			self::flash( 'forgot_error', __( 'Your session expired. Please try again.', 'unfold' ) );
			return;
		}
		$login = sanitize_text_field( self::post_str( 'log' ) );
		if ( '' === $login ) {
			self::flash( 'forgot_error', __( 'Enter your email address.', 'unfold' ) );
			return;
		}
		$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		$sent = false;
		if ( $user && ! get_transient( 'unfold_reset_req_' . $user->ID ) ) {
			set_transient( 'unfold_reset_req_' . $user->ID, 1, MINUTE_IN_SECONDS );
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				$link  = Unfold_Portals::url(
					'login',
					array(
						'action' => 'reset',
						'key'    => $key,
						'login'  => rawurlencode( $user->user_login ),
					)
				);
				$msg = sprintf(
					__( "Someone requested a password reset for your account on %1\$s.\n\nTo choose a new password, open this link:\n%2\$s\n\nIf you did not request this, you can safely ignore this email.", 'unfold' ),
					$site,
					$link
				);
				$sent = wp_mail( $user->user_email, sprintf( __( 'Reset your password for %s', 'unfold' ), $site ), $msg );
			}
		}
		$notice = __( 'If an account matches that email, a password reset link is on its way.', 'unfold' );
		if ( ! $sent && ! self::smtp_config()['configured'] ) {
			$notice .= ' ' . __( '(Note: outbound SMTP is not configured on this server yet; ask the lab administrator to configure SMTP in Settings.)', 'unfold' );
		}
		self::flash( 'forgot_notice', $notice );
	}

	private static function do_reset() {
		if ( ! self::nonce_ok( '_unfold_reset', 'unfold_reset' ) ) {
			self::flash( 'reset_error', __( 'Your session expired. Please try again.', 'unfold' ) );
			return;
		}
		$key          = sanitize_text_field( self::post_str( 'key' ) );
		$login        = sanitize_text_field( self::post_str( 'login' ) );
		$new_pass     = self::post_str( 'new_pass' );
		$confirm_pass = self::post_str( 'confirm_pass' );

		if ( strlen( $new_pass ) < 8 ) {
			self::flash( 'reset_error', __( 'Choose a password of at least 8 characters.', 'unfold' ) );
			return;
		}
		if ( $new_pass !== $confirm_pass ) {
			self::flash( 'reset_error', __( 'The two passwords do not match.', 'unfold' ) );
			return;
		}
		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			self::flash( 'reset_error', __( 'This password reset link is invalid or has expired. Request a new one.', 'unfold' ) );
			return;
		}
		reset_password( $user, $new_pass );
		wp_safe_redirect( Unfold_Portals::url( 'login', array( 'reset' => '1' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Shortcodes
	 * ------------------------------------------------------------------ */

	private static function card( $title, $intro, $body, $foot ) {
		wp_enqueue_style( 'unfold-portal' );
		wp_enqueue_script( 'unfold-auth' );
		return '<div class="unfold uf-auth"><div class="uf-auth-card"><h1 class="uf-auth-title">' . esc_html( $title ) . '</h1>'
			. ( $intro ? '<p class="uf-auth-intro">' . esc_html( $intro ) . '</p>' : '' ) . $body
			. ( $foot ? '<p class="uf-auth-foot">' . $foot . '</p>' : '' ) . '</div></div>';
	}

	private static function password_field( $label, $name, $autocomplete, $attrs = '', $hint = '' ) {
		return '<label class="uf-field"><span>' . esc_html( $label ) . '</span>'
			. '<span class="uf-pw-wrap"><input type="password" name="' . esc_attr( $name ) . '" autocomplete="' . esc_attr( $autocomplete ) . '" ' . $attrs . ' required>'
			. '<button type="button" class="uf-pw-toggle" data-uf-toggle-pw aria-pressed="false" aria-label="' . esc_attr__( 'Show password', 'unfold' ) . '">' . esc_html__( 'Show', 'unfold' ) . '</button></span>'
			. ( $hint ? '<small>' . esc_html( $hint ) . '</small>' : '' ) . '</label>';
	}

	private static function otp_form( $email ) {
		return '<form method="post" class="uf-form uf-otp-form" novalidate>'
			. wp_nonce_field( 'unfold_verify_otp', '_unfold_verify_otp', true, false )
			. '<input type="hidden" name="unfold_form" value="verify_otp">'
			. '<input type="hidden" name="log" value="' . esc_attr( $email ) . '">'
			. '<label class="uf-field"><span>' . esc_html__( '6-digit verification code', 'unfold' ) . '</span>'
			. '<input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required placeholder="000000"></label>'
			. '<button class="uf-btn uf-btn--block" type="submit">' . esc_html__( 'Verify email', 'unfold' ) . '</button>'
			. '</form>';
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
		$action = sanitize_key( (string) ( $_GET['action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'forgot' === $action ) {
			$f_err    = self::flash( 'forgot_error' );
			$f_notice = self::flash( 'forgot_notice' );
			$f_body   = ( $f_err ? '<div class="uf-alert uf-alert--error" role="alert">' . esc_html( $f_err ) . '</div>' : '' )
				. ( $f_notice ? '<div class="uf-alert uf-alert--ok" role="status">' . esc_html( $f_notice ) . '</div>' : '' )
				. '<form method="post" class="uf-form" novalidate>'
				. wp_nonce_field( 'unfold_forgot', '_unfold_forgot', true, false )
				. '<input type="hidden" name="unfold_form" value="forgot">'
				. '<label class="uf-field"><span>' . esc_html__( 'Email', 'unfold' ) . '</span><input type="text" name="log" autocomplete="username" required value="' . esc_attr( sanitize_text_field( self::post_str( 'log' ) ) ) . '"></label>'
				. '<button class="uf-btn uf-btn--block" type="submit">' . esc_html__( 'Send reset link', 'unfold' ) . '</button>'
				. '</form>';
			return self::card(
				__( 'Reset your password', 'unfold' ),
				__( 'Enter your KAU email address and we will send a link to choose a new password.', 'unfold' ),
				$f_body,
				'<a href="' . esc_url( Unfold_Portals::url( 'login' ) ) . '">' . esc_html__( 'Back to sign in', 'unfold' ) . '</a>'
			);
		}
		if ( 'reset' === $action ) {
			$r_key   = sanitize_text_field( (string) ( $_GET['key'] ?? self::post_str( 'key' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$r_login = sanitize_text_field( (string) ( $_GET['login'] ?? self::post_str( 'login' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$r_err   = self::flash( 'reset_error' );
			$r_body  = ( $r_err ? '<div class="uf-alert uf-alert--error" role="alert">' . esc_html( $r_err ) . '</div>' : '' )
				. '<form method="post" class="uf-form" novalidate>'
				. wp_nonce_field( 'unfold_reset', '_unfold_reset', true, false )
				. '<input type="hidden" name="unfold_form" value="reset">'
				. '<input type="hidden" name="key" value="' . esc_attr( $r_key ) . '">'
				. '<input type="hidden" name="login" value="' . esc_attr( $r_login ) . '">'
				. self::password_field( __( 'New password', 'unfold' ), 'new_pass', 'new-password', 'minlength="8"', __( 'At least 8 characters.', 'unfold' ) )
				. self::password_field( __( 'Confirm password', 'unfold' ), 'confirm_pass', 'new-password', 'minlength="8"' )
				. '<button class="uf-btn uf-btn--block" type="submit">' . esc_html__( 'Update password', 'unfold' ) . '</button>'
				. '</form>';
			return self::card(
				__( 'Choose a new password', 'unfold' ),
				'',
				$r_body,
				'<a href="' . esc_url( Unfold_Portals::url( 'login' ) ) . '">' . esc_html__( 'Back to sign in', 'unfold' ) . '</a>'
			);
		}

		$err         = self::flash( 'login_error' );
		$notice      = self::flash( 'login_notice' );
		if ( ! $notice && ! empty( $_GET['reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$notice = __( 'Your password has been updated. You can sign in now.', 'unfold' );
		}
		$resend      = self::flash( 'resend_for' );
		$verify_err  = self::flash( 'verify_error' );
		$verify_mail = self::flash( 'verify_email' ) ?: $resend;
		$body        = ( $err ? '<div class="uf-alert uf-alert--error" role="alert">' . esc_html( $err ) . '</div>' : '' )
			. ( $verify_err ? '<div class="uf-alert uf-alert--error" role="alert">' . esc_html( $verify_err ) . '</div>' : '' )
			. ( $notice ? '<div class="uf-alert uf-alert--ok" role="status">' . esc_html( $notice ) . '</div>' : '' );
		if ( $verify_mail ) {
			$body .= self::otp_form( $verify_mail );
		}
		if ( $resend ) {
			$body .= '<form method="post" class="uf-inline-form">' . wp_nonce_field( 'unfold_resend', '_unfold_resend', true, false )
				. '<input type="hidden" name="unfold_form" value="resend"><input type="hidden" name="log" value="' . esc_attr( $resend ) . '">'
				. '<button class="uf-linkbtn" type="submit">' . esc_html__( 'Send the confirmation link and code again', 'unfold' ) . '</button></form>';
		}
		$body .= '<form method="post" class="uf-form" novalidate>'
			. wp_nonce_field( 'unfold_login', '_unfold_login', true, false )
			. '<input type="hidden" name="unfold_form" value="login">'
			. '<label class="uf-field"><span>' . esc_html__( 'Email', 'unfold' ) . '</span><input type="text" name="log" autocomplete="username" required value="' . esc_attr( sanitize_text_field( self::post_str( 'log' ) ) ) . '"></label>'
			. self::password_field( __( 'Password', 'unfold' ), 'pwd', 'current-password' )
			. '<label class="uf-check"><input type="checkbox" name="rememberme" value="1"> ' . esc_html__( 'Keep me signed in', 'unfold' ) . '</label>'
			. '<button class="uf-btn uf-btn--block" type="submit">' . esc_html__( 'Sign in', 'unfold' ) . '</button>'
			. '</form>';
		$foot = esc_html__( 'New here?', 'unfold' ) . ' <a href="' . esc_url( Unfold_Portals::url( 'register' ) ) . '">' . esc_html__( 'Create an account', 'unfold' ) . '</a> · <a href="' . esc_url( Unfold_Portals::url( 'login', array( 'action' => 'forgot' ) ) ) . '">' . esc_html__( 'Forgot password?', 'unfold' ) . '</a>'
			. '<br><span class="uf-muted">' . esc_html__( 'Instructors: use the account and password set up from the email the site administrator sent you.', 'unfold' ) . '</span>';
		return self::card( __( 'Sign in', 'unfold' ), '', $body, $foot );
	}

	public static function register_shortcode() {
		if ( is_user_logged_in() ) {
			return self::login_shortcode();
		}
		$done        = self::flash( 'registered' );
		$verify_mail = $done ?: self::flash( 'verify_email' );
		$verify_err  = self::flash( 'verify_error' );
		if ( $verify_mail ) {
			$body = ( $verify_err ? '<div class="uf-alert uf-alert--error" role="alert">' . esc_html( $verify_err ) . '</div>' : '' )
				. '<p>' . esc_html( sprintf( __( 'We sent a 6-digit verification code (valid for 15 minutes) and a confirmation link to %s. Enter the code below or open the link in your KAU inbox.', 'unfold' ), $verify_mail ) ) . '</p>'
				. self::otp_form( $verify_mail )
				. '<form method="post" class="uf-inline-form">' . wp_nonce_field( 'unfold_resend', '_unfold_resend', true, false )
				. '<input type="hidden" name="unfold_form" value="resend"><input type="hidden" name="log" value="' . esc_attr( $verify_mail ) . '">'
				. '<button class="uf-linkbtn" type="submit">' . esc_html__( 'Send a new code', 'unfold' ) . '</button></form>';
			return self::card(
				__( 'Verify your KAU email', 'unfold' ),
				'',
				$body,
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
			. self::password_field( __( 'Password', 'unfold' ), 'uf_password', 'new-password', 'minlength="8"', __( 'At least 8 characters.', 'unfold' ) )
			. '<label class="uf-hp" aria-hidden="true">Website <input type="text" name="uf_website" tabindex="-1" autocomplete="off"></label>'
			. '<button class="uf-btn uf-btn--block" type="submit">' . esc_html__( 'Create account', 'unfold' ) . '</button>'
			. '</form>';
		$foot = esc_html__( 'Already have an account?', 'unfold' ) . ' <a href="' . esc_url( Unfold_Portals::url( 'login' ) ) . '">' . esc_html__( 'Sign in', 'unfold' ) . '</a>';
		return self::card( __( 'Create an account', 'unfold' ), __( 'To upload a project. Instructor accounts are created by the site administrator.', 'unfold' ), $body, $foot );
	}
}
