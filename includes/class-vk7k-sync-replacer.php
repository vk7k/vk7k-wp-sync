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
	 * Build a comprehensive multi-variant search and replace matrix for domain pairs and subdirectories.
	 * Handles HTTP/HTTPS, www/non-www, JSON-escaped slashes (FSE/Gutenberg), URL-encoded strings,
	 * and WordPress subdirectory installations (e.g. /wp or /wp26 for core/uploads vs root domain).
	 *
	 * @param string $source_url Source site URL (e.g. https://remoto.ejemplo.com)
	 * @param string $target_url Target site URL (e.g. http://local.ejemplo.test)
	 * @param array  $options Optional extra settings (source_subdirs, target_subdirs, auto_detect)
	 * @return array Key-value pairs for replacement sorted longest-match first
	 */
	public static function build_url_matrix( $source_url, $target_url, $options = array() ) {
		$source_clean = untrailingslashit( trim( (string) $source_url ) );
		$target_clean = untrailingslashit( trim( (string) $target_url ) );

		if ( empty( $source_clean ) || empty( $target_clean ) ) {
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

		// 1. Resolve subdirectories (from options, path segments, or auto-detection)
		$source_subdirs = isset( $options['source_subdirs'] ) ? (array) $options['source_subdirs'] : array();
		$target_subdirs = isset( $options['target_subdirs'] ) ? (array) $options['target_subdirs'] : array();

		// If source_path or target_path has a subdirectory segment, include it
		if ( ! empty( $source_path ) && '/' !== $source_path ) {
			$source_subdirs[] = trim( $source_path, '/' );
		}
		if ( ! empty( $target_path ) && '/' !== $target_path ) {
			$target_subdirs[] = trim( $target_path, '/' );
		}

		$auto_detect = ! isset( $options['auto_detect'] ) || ! empty( $options['auto_detect'] );
		if ( $auto_detect && class_exists( 'VK7K_Sync_DB' ) ) {
			$local_home = untrailingslashit( home_url() );
			$local_host = wp_parse_url( $local_home, PHP_URL_HOST );

			$detected = VK7K_Sync_DB::detect_content_subdirectories();
			if ( ! empty( $detected ) ) {
				if ( $source_host === $local_host ) {
					$source_subdirs = array_merge( $source_subdirs, $detected );
				} elseif ( $target_host === $local_host && empty( $target_path ) ) {
					// In PULL or post-pull normalization: if local target is root and subdirectories exist in content, they originate from source
					$source_subdirs = array_merge( $source_subdirs, $detected );
				}
			}

			$clean_src_h = preg_replace( '/^www\./', '', $source_host );
			$clean_tgt_h = preg_replace( '/^www\./', '', $target_host );

			// Check transients (using normalized hostnames)
			foreach ( array( $source_host, $clean_src_h ) as $sh ) {
				$cached = get_transient( 'vk7k_sync_subdirs_' . md5( $sh ) );
				if ( ! empty( $cached ) && is_array( $cached ) ) {
					$source_subdirs = array_merge( $source_subdirs, $cached );
				}
			}

			foreach ( array( $target_host, $clean_tgt_h ) as $th ) {
				$cached_tgt = get_transient( 'vk7k_sync_subdirs_' . md5( $th ) );
				if ( ! empty( $cached_tgt ) && is_array( $cached_tgt ) ) {
					$target_subdirs = array_merge( $target_subdirs, $cached_tgt );
				}
			}

			// If active target has detected subdirs, merge them
			if ( class_exists( 'VK7K_Sync_Auth' ) ) {
				$active_target = VK7K_Sync_Auth::get_active_target();
				if ( ! empty( $active_target['remote_url'] ) ) {
					$rem_host = wp_parse_url( $active_target['remote_url'], PHP_URL_HOST );
					$clean_rh = preg_replace( '/^www\./', '', $rem_host );
					if ( ( $source_host === $rem_host || $clean_src_h === $clean_rh ) && ! empty( $active_target['detected_subdirs'] ) ) {
						$source_subdirs = array_merge( $source_subdirs, (array) $active_target['detected_subdirs'] );
					}
					if ( ( $target_host === $rem_host || $clean_tgt_h === $clean_rh ) && ! empty( $active_target['detected_subdirs'] ) ) {
						$target_subdirs = array_merge( $target_subdirs, (array) $active_target['detected_subdirs'] );
					}
				}
			}
		}

		$source_subdirs = array_values( array_unique( array_filter( array_map( function( $s ) {
			return trim( (string) $s, '/' );
		}, $source_subdirs ) ) ) );

		$target_subdirs = array_values( array_unique( array_filter( array_map( function( $s ) {
			return trim( (string) $s, '/' );
		}, $target_subdirs ) ) ) );

		// If URLs are identical and no subdirectories to map, nothing to replace
		if ( $source_clean === $target_clean && empty( $source_subdirs ) && empty( $target_subdirs ) ) {
			return array();
		}

		// Determine host variations for source (www and non-www)
		$source_hosts = array( $source_host );
		if ( 0 === strpos( $source_host, 'www.' ) ) {
			$non_www = substr( $source_host, 4 );
			if ( ! empty( $non_www ) ) {
				$source_hosts[] = $non_www;
			}
		} else {
			if ( false !== strpos( $source_host, '.' ) && ! preg_match( '/\.(test|local|localhost|invalid|example)$/i', $source_host ) && ! filter_var( $source_host, FILTER_VALIDATE_IP ) ) {
				$source_hosts[] = 'www.' . $source_host;
			}
		}
		$source_hosts = array_unique( $source_hosts );

		$matrix = array();

		// Helper closure to register all multi-variant forms of a replacement pair
		$add_pair = function( $from_full, $to_full ) use ( &$matrix ) {
			$from_clean = untrailingslashit( (string) $from_full );
			$to_clean   = untrailingslashit( (string) $to_full );

			if ( empty( $from_clean ) || empty( $to_clean ) || $from_clean === $to_clean ) {
				return;
			}

			$parsed_from = wp_parse_url( $from_clean );
			$parsed_to   = wp_parse_url( $to_clean );

			$from_h    = isset( $parsed_from['host'] ) ? $parsed_from['host'] : '';
			$from_port = isset( $parsed_from['port'] ) ? ':' . $parsed_from['port'] : '';
			$from_p    = isset( $parsed_from['path'] ) ? $parsed_from['path'] : '';

			$to_h    = isset( $parsed_to['host'] ) ? $parsed_to['host'] : '';
			$to_port = isset( $parsed_to['port'] ) ? ':' . $parsed_to['port'] : '';
			$to_p    = isset( $parsed_to['path'] ) ? $parsed_to['path'] : '';

			$from_domain_path = $from_h . $from_port . $from_p;
			$to_domain_path   = $to_h . $to_port . $to_p;

			// Target variants
			$target_canon          = ( isset( $parsed_to['scheme'] ) ? $parsed_to['scheme'] . '://' : 'https://' ) . $to_domain_path;
			$target_json           = str_replace( '/', '\/', $target_canon );
			$target_urlenc         = rawurlencode( $target_canon );
			$target_proto_rel      = '//' . $to_domain_path;
			$target_proto_rel_json = str_replace( '/', '\/', $target_proto_rel );

			$from_domain_path_json = str_replace( '/', '\/', $from_domain_path );

			// 1. Standard Protocol variants (HTTPS, HTTP)
			$matrix[ 'https://' . $from_domain_path ] = $target_canon;
			$matrix[ 'http://' . $from_domain_path ]  = $target_canon;

			// 2. Protocol-relative variants (//domain.com)
			$matrix[ '//' . $from_domain_path ]       = $target_proto_rel;

			// 3. JSON-escaped slashes (FSE block attributes, wp_navigation, block templates)
			$matrix[ 'https:\/\/' . $from_domain_path_json ] = $target_json;
			$matrix[ 'http:\/\/' . $from_domain_path_json ]  = $target_json;
			$matrix[ '\/\/' . $from_domain_path_json ]       = $target_proto_rel_json;

			// 4. URL-encoded variants (query params, redirection links, analytics parameters)
			$matrix[ rawurlencode( 'https://' . $from_domain_path ) ] = $target_urlenc;
			$matrix[ rawurlencode( 'http://' . $from_domain_path ) ]  = $target_urlenc;
		};

		// A. Source has subdirectories, target does not (e.g. PULL or local normalization)
		if ( ! empty( $source_subdirs ) && empty( $target_subdirs ) ) {
			foreach ( $source_subdirs as $sub ) {
				// Relative paths without domain (e.g. href="/wp26/wp-content/...")
				$matrix[ "/{$sub}/wp-content" ]                  = '/wp-content';
				$matrix[ "\/{$sub}\/wp-content" ]                = '\/wp-content';
				$matrix[ rawurlencode( "/{$sub}/wp-content" ) ]  = rawurlencode( '/wp-content' );

				$matrix[ "/{$sub}/wp-includes" ]                 = '/wp-includes';
				$matrix[ "\/{$sub}\/wp-includes" ]               = '\/wp-includes';

				$matrix[ "/{$sub}/wp-admin" ]                    = '/wp-admin';
				$matrix[ "\/{$sub}\/wp-admin" ]                  = '\/wp-admin';

				foreach ( $source_hosts as $h ) {
					$from_base = "https://{$h}" . $source_port_str . "/{$sub}";
					$add_pair( "{$from_base}/wp-content", "{$target_clean}/wp-content" );
					$add_pair( "{$from_base}/wp-includes", "{$target_clean}/wp-includes" );
					$add_pair( "{$from_base}/wp-admin", "{$target_clean}/wp-admin" );
					$add_pair( "{$from_base}/", "{$target_clean}/" );
					$add_pair( "{$from_base}", "{$target_clean}" );
				}
			}
		}

		// B. Target has subdirectories, source does not (e.g. PUSH to remote subdirectory)
		if ( ! empty( $target_subdirs ) && empty( $source_subdirs ) ) {
			foreach ( $target_subdirs as $sub ) {
				// Relative paths without domain
				$matrix[ "/wp-content" ]                  = "/{$sub}/wp-content";
				$matrix[ "\/wp-content" ]                = "\/{$sub}\/wp-content";
				$matrix[ rawurlencode( "/wp-content" ) ]  = rawurlencode( "/{$sub}/wp-content" );

				$matrix[ "/wp-includes" ]                 = "/{$sub}/wp-includes";
				$matrix[ "\/wp-includes" ]               = "\/{$sub}\/wp-includes";

				$matrix[ "/wp-admin" ]                    = "/{$sub}/wp-admin";
				$matrix[ "\/wp-admin" ]                  = "\/{$sub}\/wp-admin";

				foreach ( $source_hosts as $h ) {
					$from_base = "https://{$h}" . $source_port_str . $source_path;
					$to_base   = "{$target_clean}/{$sub}";
					$add_pair( "{$from_base}/wp-content", "{$to_base}/wp-content" );
					$add_pair( "{$from_base}/wp-includes", "{$to_base}/wp-includes" );
					$add_pair( "{$from_base}/wp-admin", "{$to_base}/wp-admin" );
				}
			}
		}

		// C. Both sides have subdirectories
		if ( ! empty( $source_subdirs ) && ! empty( $target_subdirs ) ) {
			foreach ( $source_hosts as $h ) {
				foreach ( $source_subdirs as $src_sub ) {
					$from_base = "https://{$h}" . $source_port_str . "/{$src_sub}";
					foreach ( $target_subdirs as $tgt_sub ) {
						$to_base = "{$target_clean}/{$tgt_sub}";
						$add_pair( "{$from_base}/wp-content", "{$to_base}/wp-content" );
						$add_pair( "{$from_base}/wp-includes", "{$to_base}/wp-includes" );
						$add_pair( "{$from_base}/wp-admin", "{$to_base}/wp-admin" );
						$add_pair( "{$from_base}/", "{$to_base}/" );
						$add_pair( "{$from_base}", "{$to_base}" );
					}
				}
			}
		}

		// D. Standard root domain replacement (handles permalinks, general URLs)
		foreach ( $source_hosts as $h ) {
			$from_root = "https://{$h}" . $source_port_str . $source_path;
			$add_pair( $from_root, $target_clean );
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

		// Sort longest match first (crucial: /wp26/wp-content is replaced before domain root)
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
			// Do not attempt to inspect or alter incomplete classes (missing class definition during unserialize)
			if ( $data instanceof __PHP_Incomplete_Class || 'stdClass' !== get_class( $data ) && false !== strpos( get_class( $data ), 'Incomplete' ) ) {
				return $data;
			}

			static $visited_objects = array();
			$obj_id = spl_object_hash( $data );
			if ( isset( $visited_objects[ $obj_id ] ) ) {
				return $data; // Circular reference detected, prevent OOM
			}
			$visited_objects[ $obj_id ] = true;

			$class_name = get_class( $data );
			if ( 'stdClass' === $class_name ) {
				$new_obj = new stdClass();
				foreach ( get_object_vars( $data ) as $key => $value ) {
					$new_key = is_string( $key ) ? $this->replace_string( $key ) : $key;
					$new_obj->$new_key = $this->replace( $value );
				}
				unset( $visited_objects[ $obj_id ] );
				return $new_obj;
			}

			// For custom vendor objects, return as-is to preserve private internal state
			unset( $visited_objects[ $obj_id ] );
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
