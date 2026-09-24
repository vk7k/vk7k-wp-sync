<?php
/**
 * VK7K Sync Search & Replace Engine
 *
 * Recursively replaces strings in plain text, PHP serialized objects/arrays, and JSON structures
 * while preserving serialization integrity and string length headers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Replacer {

	/**
	 * Search and replace pairs: array( 'from' => 'to' )
	 *
	 * @var array
	 */
	protected $replacements = array();

	/**
	 * Constructor.
	 *
	 * @param array $replacements Array of search => replace strings.
	 */
	public function __construct( $replacements = array() ) {
		$this->set_replacements( $replacements );
	}

	/**
	 * Set replacements.
	 *
	 * @param array $replacements
	 */
	public function set_replacements( $replacements ) {
		$this->replacements = array();
		foreach ( (array) $replacements as $from => $to ) {
			$from = (string) $from;
			$to   = (string) $to;
			if ( ! empty( $from ) && $from !== $to ) {
				// Prevent dangerous replacement of protocol-only prefixes or tiny strings
				if ( strlen( $from ) < 6 || in_array( $from, array( 'http://', 'https://', '://', ':///', '//' ), true ) ) {
					continue;
				}
				$this->replacements[ $from ] = $to;
			}
		}

		// Sort keys by length DESC to ensure longest match is replaced first (prevent partial/nested collision)
		uksort( $this->replacements, function( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );
	}

	/**
	 * Build a comprehensive multi-variant search and replace matrix for domain pairs.
	 * Handles HTTP/HTTPS, www/non-www, JSON-escaped slashes (FSE/Gutenberg), and URL-encoded strings.
	 *
	 * @param string $source_url Source site URL (e.g. https://www.cafu.cl)
	 * @param string $target_url Target site URL (e.g. http://cafu.test)
	 * @return array Key-value pairs for replacement sorted longest-match first
	 */
	public static function build_url_matrix( $source_url, $target_url ) {
		$source_clean = untrailingslashit( trim( (string) $source_url ) );
		$target_clean = untrailingslashit( trim( (string) $target_url ) );

		if ( empty( $source_clean ) || empty( $target_clean ) || $source_clean === $target_clean ) {
			return array();
		}

		$source_host = wp_parse_url( $source_clean, PHP_URL_HOST );
		$target_host = wp_parse_url( $target_clean, PHP_URL_HOST );

		if ( empty( $source_host ) || empty( $target_host ) ) {
			return array( $source_clean => $target_clean );
		}

		$source_port = wp_parse_url( $source_clean, PHP_URL_PORT );
		$target_port = wp_parse_url( $target_clean, PHP_URL_PORT );

		$source_path = wp_parse_url( $source_clean, PHP_URL_PATH ) ?: '';
		$target_path = wp_parse_url( $target_clean, PHP_URL_PATH ) ?: '';

		$source_port_str = $source_port ? ':' . $source_port : '';
		$target_port_str = $target_port ? ':' . $target_port : '';

		// Determine host variations for source (www and non-www)
		$source_hosts = array( $source_host );
		if ( 0 === strpos( $source_host, 'www.' ) ) {
			$non_www = substr( $source_host, 4 );
			if ( ! empty( $non_www ) ) {
				$source_hosts[] = $non_www;
			}
		} else {
			// If not a local TLD (.test, .local, localhost) and has a TLD dot, add www.
			if ( false !== strpos( $source_host, '.' ) && ! preg_match( '/\.(test|local|localhost|invalid|example)$/i', $source_host ) && ! filter_var( $source_host, FILTER_VALIDATE_IP ) ) {
				$source_hosts[] = 'www.' . $source_host;
			}
		}
		$source_hosts = array_unique( $source_hosts );

		// Target variants
		$target_canonical      = $target_clean;
		$target_json           = str_replace( '/', '\/', $target_clean );
		$target_urlenc         = rawurlencode( $target_clean );
		$target_proto_rel      = '//' . $target_host . $target_port_str . $target_path;
		$target_proto_rel_json = str_replace( '/', '\/', $target_proto_rel );

		$matrix = array();

		foreach ( $source_hosts as $h ) {
			$h_full        = $h . $source_port_str . $source_path;
			$h_full_json   = str_replace( '/', '\/', $h_full );
			$h_full_urlenc = rawurlencode( $h_full );

			// 1. Standard Protocol variants (HTTPS, HTTP)
			$matrix[ 'https://' . $h_full ] = $target_canonical;
			$matrix[ 'http://' . $h_full ]  = $target_canonical;

			// 2. Protocol-relative variants (//domain.com)
			$matrix[ '//' . $h_full ]       = $target_proto_rel;

			// 3. JSON-escaped slashes (FSE block attributes, wp_navigation, block templates)
			$matrix[ 'https:\/\/' . $h_full_json ] = $target_json;
			$matrix[ 'http:\/\/' . $h_full_json ]  = $target_json;
			$matrix[ '\/\/' . $h_full_json ]       = $target_proto_rel_json;

			// 4. URL-encoded variants (query params, redirection links, analytics parameters)
			$matrix[ rawurlencode( 'https://' . $h_full ) ] = $target_urlenc;
			$matrix[ rawurlencode( 'http://' . $h_full ) ]  = $target_urlenc;
		}

		// Filter invalid or identical entries
		$clean_matrix = array();
		foreach ( $matrix as $from => $to ) {
			$from = (string) $from;
			$to   = (string) $to;
			if ( ! empty( $from ) && $from !== $to && strlen( $from ) >= 6 ) {
				$clean_matrix[ $from ] = $to;
			}
		}

		// Sort longest match first
		uksort( $clean_matrix, function( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		return $clean_matrix;
	}

	/**
	 * Run recursive search and replace on any data type.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	public function replace( $data ) {
		if ( empty( $this->replacements ) ) {
			return $data;
		}

		if ( is_string( $data ) ) {
			return $this->replace_string( $data );
		}

		if ( is_array( $data ) ) {
			$new_array = array();
			foreach ( $data as $key => $value ) {
				$new_key = is_string( $key ) ? $this->replace_string( $key ) : $key;
				$new_array[ $new_key ] = $this->replace( $value );
			}
			return $new_array;
		}

		if ( is_object( $data ) ) {
			$class_name = get_class( $data );
			if ( 'stdClass' === $class_name ) {
				$new_obj = new stdClass();
				foreach ( get_object_vars( $data ) as $key => $value ) {
					$new_key = is_string( $key ) ? $this->replace_string( $key ) : $key;
					$new_obj->$new_key = $this->replace( $value );
				}
				return $new_obj;
			}
			// For non-stdClass objects, try reflection or public properties
			try {
				$reflection = new ReflectionObject( $data );
				$properties = $reflection->getProperties();
				foreach ( $properties as $property ) {
					if ( method_exists( $property, 'setAccessible' ) ) {
						$property->setAccessible( true );
					}
					$val     = $property->getValue( $data );
					$new_val = $this->replace( $val );
					$property->setValue( $data, $new_val );
				}
			} catch ( Exception $e ) {
				// If reflection fails, return unmodified
			}
			return $data;
		}

		return $data;
	}

	/**
	 * Replace within a string (handling plain text, serialized PHP, and JSON).
	 *
	 * @param string $string
	 * @return string
	 */
	public function replace_string( $string ) {
		if ( ! is_string( $string ) || empty( $string ) ) {
			return $string;
		}

		// 1. Check if serialized PHP
		if ( $this->is_serialized( $string ) ) {
			$unserialized = @unserialize( $string );
			if ( false !== $unserialized || 'b:0;' === $string ) {
				$processed = $this->replace( $unserialized );
				return serialize( $processed );
			}
			// If standard unserialize failed due to existing corruption, use regex serialized fixer
			return $this->replace_serialized_regex( $string );
		}

		// 2. Check if JSON
		if ( $this->is_json( $string ) ) {
			$json_data = json_decode( $string, true );
			if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $json_data ) || is_object( $json_data ) ) ) {
				$processed = $this->replace( $json_data );
				return wp_json_encode( $processed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}

		// 3. Plain string replacement
		foreach ( $this->replacements as $from => $to ) {
			$string = str_replace( $from, $to, $string );
		}

		return $string;
	}

	/**
	 * Fix and replace serialized string using regex when standard unserialize fails.
	 *
	 * @param string $data
	 * @return string
	 */
	protected function replace_serialized_regex( $data ) {
		foreach ( $this->replacements as $from => $to ) {
			if ( false === strpos( $data, $from ) ) {
				continue;
			}
			$data = preg_replace_callback(
				'/s:(\d+):"(.*?)";/s',
				function ( $matches ) use ( $from, $to ) {
					$content = $matches[2];
					if ( strpos( $content, $from ) !== false ) {
						$new_content = str_replace( $from, $to, $content );
						return 's:' . strlen( $new_content ) . ':"' . $new_content . '";';
					}
					return $matches[0];
				},
				$data
			);
		}
		return $data;
	}

	/**
	 * Check if string is serialized PHP.
	 *
	 * @param string $data
	 * @return bool
	 */
	public function is_serialized( $data ) {
		if ( ! is_string( $data ) ) {
			return false;
		}
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 ) {
			return false;
		}
		if ( ':' !== $data[1] ) {
			return false;
		}
		$lastc = substr( $data, -1 );
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}
		$token = $data[0];
		switch ( $token ) {
			case 's':
				if ( '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
			case 'a':
			case 'O':
			case 'o':
			case 'C':
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b':
			case 'i':
			case 'd':
				return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$/si", $data );
		}
		return false;
	}

	/**
	 * Check if string is JSON.
	 *
	 * @param string $string
	 * @return bool
	 */
	public function is_json( $string ) {
		if ( ! is_string( $string ) || empty( $string ) ) {
			return false;
		}
		$string = trim( $string );
		if ( ( '{' !== substr( $string, 0, 1 ) && '[' !== substr( $string, 0, 1 ) ) ||
			( '}' !== substr( $string, -1 ) && ']' !== substr( $string, -1 ) ) ) {
			return false;
		}
		json_decode( $string );
		return ( json_last_error() === JSON_ERROR_NONE );
	}
}
