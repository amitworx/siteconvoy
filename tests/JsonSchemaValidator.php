<?php
/**
 * Schema Validation Helper for Tests.
 *
 * Provides recursive structural validation for JSON-serialized MigrationPlan
 * contracts against schemas/migration-plan.json.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

/**
 * Class JsonSchemaValidator
 */
class JsonSchemaValidator {

	/**
	 * Validate decoded JSON data against a JSON schema array.
	 *
	 * @param mixed                $data   Decoded JSON data.
	 * @param array<string, mixed> $schema JSON schema array.
	 * @param string               $path   Current path for error reporting.
	 * @return array<int, string> List of validation error strings.
	 */
	public static function validate( $data, array $schema, $path = '$' ) {
		$errors = array();

		// 1. Validate type.
		if ( isset( $schema['type'] ) ) {
			$expected_types = is_array( $schema['type'] ) ? $schema['type'] : array( $schema['type'] );
			$matched        = false;

			foreach ( $expected_types as $type ) {
				if ( 'null' === $type && null === $data ) {
					$matched = true;
					break;
				} elseif ( 'string' === $type && is_string( $data ) ) {
					$matched = true;
					break;
				} elseif ( 'integer' === $type && is_int( $data ) ) {
					$matched = true;
					break;
				} elseif ( 'boolean' === $type && is_bool( $data ) ) {
					$matched = true;
					break;
				} elseif ( 'object' === $type && is_array( $data ) ) {
					// In JSON decoded to associative array, an object is represented as array.
					// An empty array in json_decode could be [] or {}.
					// But if it has integer sequential keys, it was [].
					if ( empty( $data ) ) {
						// Note: for strictness, empty array can be an empty object {}.
						$matched = true;
					} elseif ( array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) {
						$matched = true;
					}
					if ( $matched ) {
						break;
					}
				} elseif ( 'array' === $type && is_array( $data ) ) {
					if ( empty( $data ) || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
						$matched = true;
						break;
					}
				}
			}

			if ( ! $matched ) {
				$actual_type = gettype( $data );
				if ( 'NULL' === $actual_type ) {
					$actual_type = 'null';
				} elseif ( is_array( $data ) ) {
					$actual_type = ( empty( $data ) || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ? 'array' : 'object';
				}
				$errors[] = sprintf( '%s: expected type [%s], got %s', $path, implode( ', ', $expected_types ), $actual_type );
				return $errors;
			}
		}

		if ( null === $data ) {
			return $errors;
		}

		// 2. Validate const.
		if ( array_key_exists( 'const', $schema ) ) {
			if ( $data !== $schema['const'] ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
				$errors[] = sprintf( '%s: expected const %s, got %s', $path, var_export( $schema['const'], true ), var_export( $data, true ) );
			}
		}

		// 3. Validate enum.
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
			if ( ! in_array( $data, $schema['enum'], true ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
				$errors[] = sprintf( '%s: expected value in [%s], got %s', $path, implode( ', ', $schema['enum'] ), var_export( $data, true ) );
			}
		}

		// 4. Validate maxProperties.
		if ( isset( $schema['maxProperties'] ) && is_array( $data ) ) {
			if ( count( $data ) > (int) $schema['maxProperties'] ) {
				$errors[] = sprintf( '%s: expected at most %d properties, found %d', $path, $schema['maxProperties'], count( $data ) );
			}
		}

		// 5. Validate object properties and required keys.
		if ( is_array( $data ) && ( empty( $data ) || array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) ) {
			if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
				foreach ( $schema['required'] as $req_prop ) {
					if ( ! array_key_exists( $req_prop, $data ) ) {
						$errors[] = sprintf( '%s: missing required property "%s"', $path, $req_prop );
					}
				}
			}

			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				foreach ( $data as $prop => $val ) {
					if ( isset( $schema['properties'][ $prop ] ) ) {
						$sub_errors = self::validate( $val, $schema['properties'][ $prop ], $path . '.' . $prop );
						$errors     = array_merge( $errors, $sub_errors );
					}
				}
			}
		}

		// 6. Validate array items.
		if ( is_array( $data ) && ( empty( $data ) || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ) {
			if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				foreach ( $data as $idx => $item ) {
					$sub_errors = self::validate( $item, $schema['items'], sprintf( '%s[%d]', $path, $idx ) );
					$errors     = array_merge( $errors, $sub_errors );
				}
			}
		}

		// 7. Validate allOf / if-then conditional schemas.
		if ( isset( $schema['allOf'] ) && is_array( $schema['allOf'] ) ) {
			foreach ( $schema['allOf'] as $condition ) {
				if ( isset( $condition['if'] ) && isset( $condition['then'] ) ) {
					$if_errors = self::validate( $data, $condition['if'], $path );
					if ( empty( $if_errors ) ) {
						$then_errors = self::validate( $data, $condition['then'], $path );
						$errors      = array_merge( $errors, $then_errors );
					}
				}
			}
		}

		return $errors;
	}
}
