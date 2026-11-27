<?php
/**
 * Validates project data against schema/project.schema.json.
 *
 * Implements the subset of JSON Schema the project schema uses: type (single or list),
 * enum, const-free required/properties/additionalProperties, items, minItems, minLength,
 * minimum, pattern and local $ref ("#/$defs/..."). Then runs cross-reference checks the
 * schema language can't express (ids that must exist, duplicate ids, ...).
 *
 * Every error is a readable string prefixed with a path such as "subsystems[2].parameters[0].status".
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Validator {

	/** Raw JSON larger than this is rejected before decoding. */
	const MAX_BYTES = 1048576;

	/** @var array|null */
	private static $schema = null;

	/** @var string[] */
	private $errors = array();

	public static function schema() {
		if ( null === self::$schema ) {
			$json         = file_get_contents( UNFOLD_DIR . 'schema/project.schema.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::$schema = json_decode( $json, true );
		}
		return self::$schema;
	}

	/**
	 * Parse and validate raw JSON text.
	 *
	 * @param string $raw JSON text.
	 * @return array { data: array|null, errors: string[] }
	 */
	public static function validate_raw( $raw ) {
		$raw = (string) $raw;
		// Strip a UTF-8 BOM that some editors add.
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( '' === trim( $raw ) ) {
			return array( 'data' => null, 'errors' => array( 'The JSON is empty. Paste a project JSON document or upload a .json file.' ) );
		}
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return array( 'data' => null, 'errors' => array( 'The JSON is larger than 1 MB. Large data belongs in data files, not in the project JSON.' ) );
		}

		$data = json_decode( $raw, true, 64 );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array(
				'data'   => null,
				'errors' => array( 'This is not valid JSON (' . json_last_error_msg() . '). ' . self::locate_syntax_error( $raw ) ),
			);
		}
		if ( ! is_array( $data ) || unfold_array_is_list( $data ) ) {
			return array( 'data' => null, 'errors' => array( 'The top level must be a JSON object { ... }, not a list or a single value.' ) );
		}

		$validator = new self();
		$validator->check( $data, self::schema(), '' );
		if ( empty( $validator->errors ) ) {
			$validator->cross_checks( $data );
		}
		return array(
			'data'   => empty( $validator->errors ) ? $data : null,
			'errors' => $validator->errors,
		);
	}

	/**
	 * Best-effort pointer to a JSON syntax error: finds the first prefix that stops parsing.
	 */
	private static function locate_syntax_error( $raw ) {
		// Common mistakes first: they give much clearer advice than a position.
		if ( preg_match( '/,\s*[}\]]/', $raw, $m, PREG_OFFSET_CAPTURE ) ) {
			return 'There is a trailing comma before a closing bracket near line ' . self::line_of( $raw, $m[0][1] ) . '.';
		}
		if ( preg_match( "/(^|[\\[{,:]\\s*)'/", $raw, $m, PREG_OFFSET_CAPTURE ) ) {
			return 'JSON strings must use double quotes ("), not single quotes, near line ' . self::line_of( $raw, $m[0][1] ) . '.';
		}
		return 'Check for missing commas, missing quotes around keys, or unbalanced brackets.';
	}

	private static function line_of( $raw, $offset ) {
		return substr_count( substr( $raw, 0, $offset ), "\n" ) + 1;
	}

	/* ---------------------------------------------------------------------
	 * Schema walk
	 * ------------------------------------------------------------------ */

	private function resolve( $schema ) {
		while ( isset( $schema['$ref'] ) ) {
			$ref = $schema['$ref'];
			if ( 0 !== strpos( $ref, '#/$defs/' ) ) {
				break;
			}
			$name   = substr( $ref, 8 );
			$schema = self::schema()['$defs'][ $name ] ?? array();
		}
		return $schema;
	}

	private function err( $path, $message ) {
		$this->errors[] = ( '' === $path ? '(top level)' : $path ) . ': ' . $message;
	}

	private static function json_type( $value ) {
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		if ( is_string( $value ) ) {
			return 'string';
		}
		if ( is_array( $value ) ) {
			return ( array() === $value || unfold_array_is_list( $value ) ) ? 'array' : 'object';
		}
		return 'unknown';
	}

	private static function type_matches( $actual, $expected, $value ) {
		if ( $actual === $expected ) {
			return true;
		}
		if ( 'number' === $expected && 'integer' === $actual ) {
			return true;
		}
		if ( 'integer' === $expected && 'number' === $actual && floor( $value ) == $value ) { // phpcs:ignore Universal.Operators.StrictComparisons
			return true; // 3.0 counts as an integer in JSON Schema.
		}
		// An empty JSON object {} decodes to an empty PHP array, which reads as an array.
		if ( 'object' === $expected && array() === $value ) {
			return true;
		}
		return false;
	}

	private static function describe( $value ) {
		$encoded = wp_json_encode( $value );
		if ( strlen( $encoded ) > 60 ) {
			$encoded = substr( $encoded, 0, 57 ) . '...';
		}
		return $encoded;
	}

	private function check( $value, $schema, $path ) {
		$schema = $this->resolve( $schema );
		$actual = self::json_type( $value );

		if ( isset( $schema['type'] ) ) {
			$types = (array) $schema['type'];
			$ok    = false;
			foreach ( $types as $t ) {
				if ( self::type_matches( $actual, $t, $value ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				$nice = array_map(
					static function ( $t ) {
						return 'null' === $t ? 'null' : 'a' . ( in_array( $t[0], array( 'a', 'i', 'o' ), true ) ? 'n ' : ' ' ) . $t;
					},
					$types
				);
				$this->err( $path, 'must be ' . implode( ' or ', $nice ) . ', got ' . $actual . ' ' . self::describe( $value ) . '.' );
				return;
			}
		}

		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$allowed = array_map(
				static function ( $v ) {
					return null === $v ? 'null' : '"' . $v . '"';
				},
				$schema['enum']
			);
			$this->err( $path, 'must be one of ' . implode( ', ', $allowed ) . ', got ' . self::describe( $value ) . '.' );
			return;
		}

		if ( is_string( $value ) ) {
			if ( isset( $schema['minLength'] ) && mb_strlen( trim( $value ) ) < $schema['minLength'] ) {
				$this->err( $path, 'must not be empty.' );
			}
			if ( isset( $schema['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\/', $schema['pattern'] ) . '/u', $value ) ) {
				$this->err( $path, self::pattern_hint( $schema['pattern'], $value ) );
			}
		}

		if ( ( is_int( $value ) || is_float( $value ) ) && isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
			$this->err( $path, 'must be at least ' . $schema['minimum'] . ', got ' . $value . '.' );
		}

		if ( 'array' === $actual || ( 'object' !== $actual && is_array( $value ) ) ) {
			$this->check_array( $value, $schema, $path );
		}
		if ( 'object' === $actual || ( array() === $value && in_array( 'object', (array) ( $schema['type'] ?? array() ), true ) ) ) {
			$this->check_object( $value, $schema, $path );
		}
	}

	private static function pattern_hint( $pattern, $value ) {
		if ( '^[a-z][a-z0-9_]*$' === $pattern ) {
			return 'must be a short id using lowercase letters, digits and underscores, starting with a letter (e.g. "speed_ctrl"), got ' . self::describe( $value ) . '.';
		}
		if ( false !== strpos( $pattern, '\\.html' ) ) {
			return 'must be a simulation file name like "pi-speed-conceptual.html" (lowercase letters, digits, - and _), got ' . self::describe( $value ) . '.';
		}
		return 'has an invalid format: ' . self::describe( $value ) . '.';
	}

	private function check_array( $value, $schema, $path ) {
		if ( isset( $schema['minItems'] ) && count( $value ) < $schema['minItems'] ) {
			$this->err( $path, 'must contain at least ' . $schema['minItems'] . ' item(s).' );
		}
		if ( isset( $schema['items'] ) ) {
			foreach ( $value as $i => $item ) {
				$this->check( $item, $schema['items'], $path . '[' . $i . ']' );
			}
		}
	}

	private function check_object( $value, $schema, $path ) {
		$props = $schema['properties'] ?? array();
		foreach ( $schema['required'] ?? array() as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				$this->err( $path, 'is missing the required key "' . $key . '". (Use null if the value is unknown.)' );
			}
		}
		foreach ( $value as $key => $child ) {
			$child_path = '' === $path ? (string) $key : $path . '.' . $key;
			if ( isset( $props[ $key ] ) ) {
				$this->check( $child, $props[ $key ], $child_path );
			} elseif ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
				$this->err( $path, 'has an unknown key "' . $key . '". Allowed keys: ' . implode( ', ', array_keys( $props ) ) . '.' );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Cross-reference checks (not expressible in the schema)
	 * ------------------------------------------------------------------ */

	/** Short-text limits that keep every project page equally readable (schema 0.2). */
	const MAX_NAME_WORDS  = 3;
	const MAX_PLAIN_WORDS = 12;
	const MAX_QUESTION    = 90;
	const MAX_LABEL       = 60;

	private static function words( $text ) {
		return count( preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY ) );
	}

	private function text_limits( array $d ) {
		if ( '0.2' !== ( $d['schema_version'] ?? '' ) ) {
			return;
		}
		$q = $d['meta']['question'] ?? null;
		if ( is_string( $q ) && mb_strlen( $q ) > self::MAX_QUESTION ) {
			$this->err( 'meta.question', 'keep the question to one short line (at most ' . self::MAX_QUESTION . ' characters, now ' . mb_strlen( $q ) . ').' );
		}
		$label = $d['meta']['headline']['label'] ?? null;
		if ( is_string( $label ) && mb_strlen( $label ) > self::MAX_LABEL ) {
			$this->err( 'meta.headline.label', 'keep the headline label short (at most ' . self::MAX_LABEL . ' characters, now ' . mb_strlen( $label ) . ').' );
		}
		foreach ( $d['subsystems'] as $i => $s ) {
			$n = self::words( $s['name'] );
			if ( $n > self::MAX_NAME_WORDS ) {
				$this->err( "subsystems[$i].name", 'use 1-' . self::MAX_NAME_WORDS . ' plain words, e.g. "Speed controller" (now ' . $n . ' words). The technical method belongs in Method.' );
			}
			$plain = $s['plain'] ?? null;
			if ( null === $plain || '' === trim( (string) $plain ) ) {
				$this->err( "subsystems[$i].plain", 'say what this part does in one short line, e.g. "Holds the car at 0.6 m/s."' );
			} elseif ( self::words( $plain ) > self::MAX_PLAIN_WORDS ) {
				$this->err( "subsystems[$i].plain", 'keep it to one short line (at most ' . self::MAX_PLAIN_WORDS . ' words, now ' . self::words( $plain ) . ').' );
			}
		}
	}

	private function cross_checks( array $d ) {
		$this->text_limits( $d );
		$part_ids = array_flip( array_map( static function ( $s ) { return $s['id']; }, $d['subsystems'] ) );
		foreach ( $d['results']['media'] ?? array() as $i => $m ) {
			if ( ! empty( $m['part'] ) && ! isset( $part_ids[ $m['part'] ] ) ) {
				$this->err( "results.media[$i].part", 'refers to "' . $m['part'] . '", which is not a subsystem id.' );
			}
		}
		$ids = array();
		foreach ( $d['subsystems'] as $i => $s ) {
			if ( isset( $ids[ $s['id'] ] ) ) {
				$this->err( "subsystems[$i].id", 'duplicate subsystem id "' . $s['id'] . '" (also used by subsystems[' . $ids[ $s['id'] ] . ']).' );
			}
			$ids[ $s['id'] ] = $i;
		}
		$known = function ( $id ) use ( $ids ) {
			return isset( $ids[ $id ] );
		};
		$id_list = implode( ', ', array_keys( $ids ) );

		foreach ( $d['connections'] as $i => $c ) {
			foreach ( array( 'from', 'to' ) as $end ) {
				if ( ! $known( $c[ $end ] ) ) {
					$this->err( "connections[$i].$end", 'refers to subsystem "' . $c[ $end ] . '", which does not exist. Known ids: ' . $id_list . '.' );
				}
			}
		}

		foreach ( $d['sensors'] as $i => $s ) {
			foreach ( $s['used_by'] as $j => $id ) {
				if ( ! $known( $id ) ) {
					$this->err( "sensors[$i].used_by[$j]", 'refers to subsystem "' . $id . '", which does not exist. Known ids: ' . $id_list . '.' );
				}
			}
		}

		foreach ( $d['data_files'] as $i => $f ) {
			if ( null !== ( $f['subsystem'] ?? null ) && ! $known( $f['subsystem'] ) ) {
				$this->err( "data_files[$i].subsystem", 'refers to subsystem "' . $f['subsystem'] . '", which does not exist.' );
			}
		}

		foreach ( $d['subsystems'] as $i => $s ) {
			if ( empty( $s['sim'] ) ) {
				continue;
			}
			$sim = $s['sim'];
			if ( ! empty( $sim['template'] ) ) {
				$runs = array_filter(
					$sim['data'] ?? array(),
					static function ( $d ) {
						return 'run' === $d['role'];
					}
				);
				if ( 'data_backed' === $sim['type'] && ! $runs ) {
					$this->err( "subsystems[$i].sim.data", 'a data-backed simulation must list at least one recorded run to compare with.' );
				}
			} elseif ( 'data_backed' === $sim['type'] ) {
				$this->err( "subsystems[$i].sim.template", 'choose the template that models this part, or remove the simulation.' );
			}
		}
	}
}

/**
 * array_is_list() exists only on PHP 8.1+.
 */
function unfold_array_is_list( array $a ) {
	if ( function_exists( 'array_is_list' ) ) {
		return array_is_list( $a );
	}
	$i = 0;
	foreach ( $a as $k => $_ ) {
		if ( $k !== $i++ ) {
			return false;
		}
	}
	return true;
}
