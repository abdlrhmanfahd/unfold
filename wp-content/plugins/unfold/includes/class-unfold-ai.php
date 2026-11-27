<?php
/**
 * AI extraction configuration: built-in lab presets, permanent per-user BYOK keys,
 * and Quick Extract (no-AI local rules).
 */

defined( 'ABSPATH' ) || exit;

class Unfold_AI {

	const PROVIDERS = array( 'gemini', 'openai_compat', 'ollama', 'anthropic', 'local_rules' );

	const BYOK_PROVIDERS = array( 'gemini', 'openai_compat', 'ollama', 'anthropic' );

	const DEFAULT_MODELS = array(
		'gemini'        => 'gemini-2.5-flash',
		'openai_compat' => 'llama-3.3-70b-versatile',
		'ollama'        => 'qwen3:8b',
		'anthropic'     => 'claude-sonnet-4-6',
		'local_rules'   => 'local_rules',
	);

	const CURATED_MODELS = array(
		'gemini'        => array( 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash' ),
		'openai_compat' => array(
			'llama-3.3-70b-versatile',
			'llama-3.1-8b-instant',
			'gpt-4o-mini',
			'gpt-4.1-mini',
			'deepseek/deepseek-chat-v3-0324:free',
			'google/gemini-2.0-flash-exp:free',
		),
		'ollama'        => array( 'qwen3:32b', 'qwen3:14b', 'qwen3:8b', 'deepseek-r1:32b', 'deepseek-r1:14b', 'deepseek-r1:8b', 'qwen2.5:14b', 'qwen2.5:7b', 'qwen2.5:3b', 'llama3.1:8b' ),
		'anthropic'     => array( 'claude-sonnet-4-6', 'claude-haiku-4-5', 'claude-opus-4-1' ),
	);

	const OPTION_PRESETS   = 'unfold_ai_presets';
	const USER_META_KEY    = '_unfold_ai_config';
	const POST_META_CHOICE = '_unfold_ai_choice';

	/** Derive a 32-byte key from WordPress salts for encrypting stored API keys. */
	private static function encryption_key() {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'unfold-fallback-salt' );
		return hash( 'sha256', $salt . '|unfold-ai-v1', true );
	}

	/** Encrypt an API key before persisting it in options or user meta. */
	public static function encrypt_secret( $plain ) {
		$plain = trim( (string) $plain );
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$key     = self::encryption_key();
		$methods = function_exists( 'openssl_get_cipher_methods' ) ? openssl_get_cipher_methods() : array();
		if ( in_array( 'aes-256-gcm', $methods, true ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$enc = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false !== $enc && 16 === strlen( $tag ) ) {
				return 'v1:' . base64_encode( $iv . $tag . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		}
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false !== $enc ? 'v1c:' . base64_encode( $iv . $enc ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/** Decrypt an encrypted API key. Returns empty string on empty or invalid input. */
	public static function decrypt_secret( $cipher ) {
		$cipher = (string) $cipher;
		if ( '' === $cipher || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$key = self::encryption_key();
		if ( 0 === strpos( $cipher, 'v1:' ) ) {
			$raw = base64_decode( substr( $cipher, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false === $raw || strlen( $raw ) < 29 ) {
				return '';
			}
			$iv   = substr( $raw, 0, 12 );
			$tag  = substr( $raw, 12, 16 );
			$data = substr( $raw, 28 );
			$out  = openssl_decrypt( $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false !== $out ? $out : '';
		}
		if ( 0 === strpos( $cipher, 'v1c:' ) ) {
			$raw = base64_decode( substr( $cipher, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false === $raw || strlen( $raw ) < 17 ) {
				return '';
			}
			$iv   = substr( $raw, 0, 16 );
			$data = substr( $raw, 16 );
			$out  = openssl_decrypt( $data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return false !== $out ? $out : '';
		}
		return '';
	}

	public static function key_hint( $plain ) {
		$plain = trim( (string) $plain );
		return strlen( $plain ) >= 4 ? substr( $plain, -4 ) : '';
	}

	public static function sanitize_base_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$clean  = esc_url_raw( $url, array( 'http', 'https' ) );
		$scheme = (string) parse_url( $clean, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ? rtrim( $clean, '/' ) : '';
	}

	private static function default_presets() {
		return array(
			'lab_gemini' => array(
				'id'          => 'lab_gemini',
				'enabled'     => false,
				'label'       => 'Lab Gemini Free',
				'provider'    => 'gemini',
				'model'       => self::DEFAULT_MODELS['gemini'],
				'base_url'    => '',
				'api_key_enc' => '',
				'key_hint'    => '',
			),
			'lab_ollama' => array(
				'id'          => 'lab_ollama',
				'enabled'     => false,
				'label'       => 'Lab Local Ollama',
				'provider'    => 'ollama',
				'model'       => self::DEFAULT_MODELS['ollama'],
				'base_url'    => 'http://127.0.0.1:11434',
				'api_key_enc' => '',
				'key_hint'    => '',
			),
			'lab_openai' => array(
				'id'          => 'lab_openai',
				'enabled'     => false,
				'label'       => 'Lab Groq / OpenAI-Compatible',
				'provider'    => 'openai_compat',
				'model'       => self::DEFAULT_MODELS['openai_compat'],
				'base_url'    => 'https://api.groq.com/openai/v1',
				'api_key_enc' => '',
				'key_hint'    => '',
			),
		);
	}

	/** Full preset records (including encrypted keys) for admin settings & worker resolution. */
	public static function raw_presets() {
		$defaults = self::default_presets();
		$saved    = get_option( self::OPTION_PRESETS, null );
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}
		$out = array();
		foreach ( $defaults as $id => $def ) {
			$row      = is_array( $saved[ $id ] ?? null ) ? $saved[ $id ] : array();
			$provider = sanitize_key( (string) ( $row['provider'] ?? $def['provider'] ) );
			if ( ! in_array( $provider, self::BYOK_PROVIDERS, true ) ) {
				$provider = $def['provider'];
			}
			$model    = sanitize_text_field( (string) ( $row['model'] ?? $def['model'] ) );
			$out[ $id ] = array(
				'id'          => $id,
				'enabled'     => ! empty( $row['enabled'] ),
				'label'       => sanitize_text_field( (string) ( $row['label'] ?? $def['label'] ) ) ?: $def['label'],
				'provider'    => $provider,
				'model'       => '' !== $model ? $model : ( self::DEFAULT_MODELS[ $provider ] ?? '' ),
				'base_url'    => self::sanitize_base_url( $row['base_url'] ?? $def['base_url'] ),
				'api_key_enc' => (string) ( $row['api_key_enc'] ?? '' ),
				'key_hint'    => sanitize_text_field( (string) ( $row['key_hint'] ?? '' ) ),
			);
		}
		return $out;
	}

	public static function save_presets( $presets ) {
		update_option( self::OPTION_PRESETS, $presets, false );
	}

	/** Enabled built-in presets safe to expose to logged-in members (never includes keys). */
	public static function public_presets() {
		$list = array();
		foreach ( self::raw_presets() as $p ) {
			if ( empty( $p['enabled'] ) ) {
				continue;
			}
			$list[] = array(
				'id'       => $p['id'],
				'label'    => $p['label'],
				'provider' => $p['provider'],
				'model'    => $p['model'],
			);
		}
		return $list;
	}

	/** Safe user AI configuration (never leaks raw or encrypted API keys). */
	public static function get_user_config( $user_id ) {
		$user_id = (int) $user_id;
		$presets = self::public_presets();
		$saved   = $user_id > 0 ? get_user_meta( $user_id, self::USER_META_KEY, true ) : array();
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$default_mode = ! empty( $presets ) ? 'preset' : 'quick';
		$mode         = sanitize_key( (string) ( $saved['mode'] ?? $default_mode ) );
		if ( ! in_array( $mode, array( 'preset', 'byok', 'quick' ), true ) ) {
			$mode = $default_mode;
		}
		if ( 'preset' === $mode && empty( $presets ) ) {
			$mode = 'quick';
		}
		$preset_ids = wp_list_pluck( $presets, 'id' );
		$preset_id  = sanitize_key( (string) ( $saved['preset_id'] ?? '' ) );
		if ( ! empty( $preset_ids ) && ( '' === $preset_id || ! in_array( $preset_id, $preset_ids, true ) ) ) {
			$preset_id = $preset_ids[0];
		}
		$provider = sanitize_key( (string) ( $saved['provider'] ?? 'gemini' ) );
		if ( ! in_array( $provider, self::BYOK_PROVIDERS, true ) ) {
			$provider = 'gemini';
		}
		$model = sanitize_text_field( (string) ( $saved['model'] ?? '' ) );
		if ( '' === $model ) {
			$model = self::DEFAULT_MODELS[ $provider ] ?? '';
		}
		$enc = (string) ( $saved['api_key_enc'] ?? '' );
		return array(
			'mode'      => $mode,
			'preset_id' => $preset_id,
			'provider'  => $provider,
			'model'     => $model,
			'base_url'  => self::sanitize_base_url( $saved['base_url'] ?? '' ),
			'has_key'   => '' !== $enc,
			'key_hint'  => sanitize_text_field( (string) ( $saved['key_hint'] ?? '' ) ),
		);
	}

	/** Persist a member's AI preferences & encrypted BYOK API key permanently in user meta. */
	public static function save_user_config( $user_id, $input ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! is_array( $input ) ) {
			return self::get_user_config( $user_id );
		}
		$prev = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $prev ) ) {
			$prev = array();
		}
		$api_key_enc = (string) ( $prev['api_key_enc'] ?? '' );
		$key_hint    = (string) ( $prev['key_hint'] ?? '' );
		if ( ! empty( $input['clear_key'] ) ) {
			$api_key_enc = '';
			$key_hint    = '';
		} elseif ( isset( $input['api_key'] ) && is_string( $input['api_key'] ) && '' !== trim( $input['api_key'] ) ) {
			$plain       = trim( $input['api_key'] );
			$api_key_enc = self::encrypt_secret( $plain );
			$key_hint    = self::key_hint( $plain );
		}

		$cur  = self::get_user_config( $user_id );
		$mode = sanitize_key( (string) ( $input['mode'] ?? $cur['mode'] ) );
		if ( ! in_array( $mode, array( 'preset', 'byok', 'quick' ), true ) ) {
			$mode = $cur['mode'];
		}
		$provider = sanitize_key( (string) ( $input['provider'] ?? $cur['provider'] ) );
		if ( ! in_array( $provider, self::BYOK_PROVIDERS, true ) ) {
			$provider = 'gemini';
		}
		$model    = sanitize_text_field( (string) ( $input['model'] ?? $cur['model'] ) );
		$base_url = array_key_exists( 'base_url', $input )
			? self::sanitize_base_url( $input['base_url'] )
			: (string) ( $prev['base_url'] ?? '' );

		update_user_meta(
			$user_id,
			self::USER_META_KEY,
			array(
				'mode'        => $mode,
				'preset_id'   => sanitize_key( (string) ( $input['preset_id'] ?? $cur['preset_id'] ) ),
				'provider'    => $provider,
				'model'       => '' !== $model ? $model : ( self::DEFAULT_MODELS[ $provider ] ?? '' ),
				'base_url'    => $base_url,
				'api_key_enc' => $api_key_enc,
				'key_hint'    => $key_hint,
			)
		);
		return self::get_user_config( $user_id );
	}

	/** Save the extraction mode chosen for a specific project job. */
	public static function save_project_choice( $post_id, $choice, $user_id = 0 ) {
		$post_id = (int) $post_id;
		$user_id = (int) $user_id ?: (int) get_post_field( 'post_author', $post_id );
		if ( ! is_array( $choice ) ) {
			$cfg    = self::get_user_config( $user_id );
			$choice = array( 'mode' => $cfg['mode'], 'preset_id' => $cfg['preset_id'] );
		}
		$mode = sanitize_key( (string) ( $choice['mode'] ?? 'quick' ) );
		if ( ! in_array( $mode, array( 'preset', 'byok', 'quick' ), true ) ) {
			$mode = 'quick';
		}
		$stored = array(
			'mode'      => $mode,
			'preset_id' => sanitize_key( (string) ( $choice['preset_id'] ?? '' ) ),
			'user_id'   => $user_id,
		);
		update_post_meta( $post_id, self::POST_META_CHOICE, $stored );
		return $stored;
	}

	/** Resolve provider, model, base_url, and decrypted key for the worker claiming a job. */
	public static function resolve_for_job( $post_id ) {
		$choice = get_post_meta( (int) $post_id, self::POST_META_CHOICE, true );
		if ( ! is_array( $choice ) || empty( $choice['mode'] ) ) {
			return null;
		}
		$mode  = (string) $choice['mode'];
		$quick = array(
			'provider' => 'local_rules',
			'model'    => 'local_rules',
			'base_url' => '',
			'api_key'  => '',
		);
		if ( 'quick' === $mode ) {
			return $quick;
		}
		if ( 'preset' === $mode ) {
			$pid     = (string) ( $choice['preset_id'] ?? '' );
			$presets = self::raw_presets();
			if ( '' !== $pid && ! empty( $presets[ $pid ]['enabled'] ) ) {
				$p = $presets[ $pid ];
				return array(
					'provider' => $p['provider'],
					'model'    => $p['model'],
					'base_url' => (string) ( $p['base_url'] ?? '' ),
					'api_key'  => self::decrypt_secret( (string) ( $p['api_key_enc'] ?? '' ) ),
				);
			}
			return $quick;
		}
		if ( 'byok' === $mode ) {
			$uid   = ! empty( $choice['user_id'] ) ? (int) $choice['user_id'] : (int) get_post_field( 'post_author', $post_id );
			$saved = $uid > 0 ? get_user_meta( $uid, self::USER_META_KEY, true ) : array();
			if ( ! is_array( $saved ) ) {
				return $quick;
			}
			$provider = sanitize_key( (string) ( $saved['provider'] ?? 'gemini' ) );
			if ( ! in_array( $provider, self::BYOK_PROVIDERS, true ) ) {
				$provider = 'gemini';
			}
			$model = sanitize_text_field( (string) ( $saved['model'] ?? '' ) );
			if ( '' === $model ) {
				$model = self::DEFAULT_MODELS[ $provider ] ?? '';
			}
			return array(
				'provider' => $provider,
				'model'    => $model,
				'base_url' => self::sanitize_base_url( $saved['base_url'] ?? '' ),
				'api_key'  => self::decrypt_secret( (string) ( $saved['api_key_enc'] ?? '' ) ),
			);
		}
		return null;
	}

	private static function is_safe_model_endpoint( $url ) {
		$clean = self::sanitize_base_url( $url );
		if ( '' === $clean ) {
			return false;
		}
		$parts  = parse_url( $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( 'https' === $scheme ) {
			return '' !== $host;
		}
		return 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/** Fetch live models available for a provider + key, falling back cleanly to curated defaults. */
	public static function fetch_models( $provider, $base_url = '', $api_key = '' ) {
		$provider = sanitize_key( (string) $provider );
		if ( ! in_array( $provider, self::BYOK_PROVIDERS, true ) ) {
			$provider = 'gemini';
		}
		$curated = self::CURATED_MODELS[ $provider ] ?? array();
		$api_key = trim( (string) $api_key );
		$live    = array();
		$err     = '';

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return array( 'models' => $curated, 'live' => false, 'error' => '' );
		}

		if ( 'gemini' === $provider ) {
			if ( '' === $api_key ) {
				return array( 'models' => $curated, 'live' => false, 'error' => '' );
			}
			$res = wp_remote_get(
				add_query_arg( 'key', $api_key, 'https://generativelanguage.googleapis.com/v1beta/models' ),
				array( 'timeout' => 8 )
			);
			if ( is_wp_error( $res ) ) {
				$err = $res->get_error_message();
			} elseif ( 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
				$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
				foreach ( (array) ( $body['models'] ?? array() ) as $m ) {
					$methods = (array) ( $m['supportedGenerationMethods'] ?? array() );
					$name    = (string) ( $m['name'] ?? '' );
					if ( in_array( 'generateContent', $methods, true ) && 0 === strpos( $name, 'models/' ) ) {
						$id = sanitize_text_field( substr( $name, 7 ) );
						if ( '' !== $id && false === stripos( $id, 'embedding' ) ) {
							$live[] = $id;
						}
					}
				}
			} else {
				$err = 'HTTP ' . (int) wp_remote_retrieve_response_code( $res );
			}
		} elseif ( 'ollama' === $provider ) {
			$base = self::sanitize_base_url( $base_url ) ?: 'http://127.0.0.1:11434';
			if ( self::is_safe_model_endpoint( $base ) ) {
				$res = wp_remote_get( $base . '/api/tags', array( 'timeout' => 5 ) );
				if ( is_wp_error( $res ) ) {
					$err = $res->get_error_message();
				} elseif ( 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
					$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
					foreach ( (array) ( $body['models'] ?? array() ) as $m ) {
						$id = sanitize_text_field( (string) ( $m['name'] ?? '' ) );
						if ( '' !== $id ) {
							$live[] = $id;
						}
					}
				} else {
					$err = 'HTTP ' . (int) wp_remote_retrieve_response_code( $res );
				}
			}
		} elseif ( 'openai_compat' === $provider ) {
			$base = self::sanitize_base_url( $base_url ) ?: 'https://api.groq.com/openai/v1';
			if ( '' !== $api_key && self::is_safe_model_endpoint( $base ) ) {
				$res = wp_remote_get(
					$base . '/models',
					array(
						'timeout' => 8,
						'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
					)
				);
				if ( is_wp_error( $res ) ) {
					$err = $res->get_error_message();
				} elseif ( 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
					$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
					foreach ( (array) ( $body['data'] ?? array() ) as $m ) {
						$id = sanitize_text_field( (string) ( $m['id'] ?? '' ) );
						if ( '' !== $id && false === stripos( $id, 'whisper' ) && false === stripos( $id, 'tts' ) && false === stripos( $id, 'embedding' ) ) {
							$live[] = $id;
						}
					}
				} else {
					$err = 'HTTP ' . (int) wp_remote_retrieve_response_code( $res );
				}
			}
		} elseif ( 'anthropic' === $provider && '' !== $api_key ) {
			$res = wp_remote_get(
				'https://api.anthropic.com/v1/models',
				array(
					'timeout' => 8,
					'headers' => array(
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
					),
				)
			);
			if ( is_wp_error( $res ) ) {
				$err = $res->get_error_message();
			} elseif ( 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
				$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
				foreach ( (array) ( $body['data'] ?? array() ) as $m ) {
					$id = sanitize_text_field( (string) ( $m['id'] ?? '' ) );
					if ( '' !== $id ) {
						$live[] = $id;
					}
				}
			} else {
				$err = 'HTTP ' . (int) wp_remote_retrieve_response_code( $res );
			}
		}

		$live = array_values( array_unique( array_filter( $live ) ) );
		if ( ! empty( $live ) ) {
			// Put any curated favorites that exist in the live list first, then the rest of live.
			$top    = array_values( array_intersect( $curated, $live ) );
			$merged = array_values( array_unique( array_merge( $top, $live ) ) );
			return array( 'models' => array_slice( $merged, 0, 80 ), 'live' => true, 'error' => '' );
		}
		return array( 'models' => $curated, 'live' => false, 'error' => $err );
	}
}
