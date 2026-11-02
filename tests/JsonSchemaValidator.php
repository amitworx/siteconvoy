<?php
/**
 * Schema Validation Helper for Tests.
 *
 * Provides recursive structural validation for JSON-serialized MigrationPlan
 * contracts against schemas/migration-plan.json.
 *
 * NOTE: This is a supported-keyword structural validator for test assertions,
 * validating types (stdClass objects vs arrays, strings, integers, booleans, null),
 * const, enum, maxProperties, required keys, properties, items, and allOf/if-then
 * conditionals. It is not an arbitrary full draft-2020-12 engine.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

/**
 * Class JsonSchemaValidator
 */
class JsonSchemaValidator {

	/**
	 * Validate decoded JSON data (using stdClass for objects and array for arrays)
	 * against a JSON schema array.
	 *
	 * @param mixed                $data   Decoded JSON data (objects as stdClass, arrays as array).
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
				} elseif ( 'object' === $type && is_object( $data ) ) {
					$matched = true;
					break;
				} elseif ( 'array' === $type && is_array( $data ) ) {
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				$actual_type = gettype( $data );
				if ( 'NULL' === $actual_type ) {
					$actual_type = 'null';
				} elseif ( is_object( $data ) ) {
					$actual_type = 'object';
				} elseif ( is_array( $data ) ) {
					$actual_type = 'array';
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

		// 4. Validate maxProperties (for objects).
		if ( isset( $schema['maxProperties'] ) && is_object( $data ) ) {
			$prop_count = count( get_object_vars( $data ) );
			if ( $prop_count > (int) $schema['maxProperties'] ) {
				$errors[] = sprintf( '%s: expected at most %d properties, found %d', $path, $schema['maxProperties'], $prop_count );
			}
		}

		// 4b. Validate minimum (for numbers/integers).
		if ( isset( $schema['minimum'] ) && ( is_int( $data ) || is_float( $data ) ) ) {
			if ( $data < $schema['minimum'] ) {
				$errors[] = sprintf( '%s: expected minimum %s, got %s', $path, (string) $schema['minimum'], (string) $data );
			}
		}

		// 4c. Validate pattern (for strings).
		if ( isset( $schema['pattern'] ) && is_string( $data ) ) {
			$delimiter = '#';
			$pattern   = $delimiter . str_replace( $delimiter, '\\' . $delimiter, $schema['pattern'] ) . $delimiter;
			if ( ! preg_match( $pattern, $data ) ) {
				$errors[] = sprintf( '%s: string does not match pattern "%s"', $path, $schema['pattern'] );
			}
		}

		// 5. Validate object properties and required keys.
		if ( is_object( $data ) ) {
			$data_vars = get_object_vars( $data );

			if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
				foreach ( $schema['required'] as $req_prop ) {
					if ( ! array_key_exists( $req_prop, $data_vars ) ) {
						$errors[] = sprintf( '%s: missing required property "%s"', $path, $req_prop );
					}
				}
			}

			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				foreach ( $data_vars as $prop => $val ) {
					if ( isset( $schema['properties'][ $prop ] ) ) {
						$sub_errors = self::validate( $val, $schema['properties'][ $prop ], $path . '.' . $prop );
						$errors     = array_merge( $errors, $sub_errors );
					}
				}
			}

			// Validate additionalProperties.
			if ( array_key_exists( 'additionalProperties', $schema ) ) {
				if ( false === $schema['additionalProperties'] ) {
					$allowed_props = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? array_keys( $schema['properties'] ) : array();
					foreach ( array_keys( $data_vars ) as $prop ) {
						if ( ! in_array( $prop, $allowed_props, true ) ) {
							$errors[] = sprintf( '%s: additional property "%s" is not allowed', $path, $prop );
						}
					}
				} elseif ( is_array( $schema['additionalProperties'] ) ) {
					$allowed_props = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? array_keys( $schema['properties'] ) : array();
					foreach ( $data_vars as $prop => $val ) {
						if ( ! in_array( $prop, $allowed_props, true ) ) {
							$sub_errors = self::validate( $val, $schema['additionalProperties'], $path . '.' . $prop );
							$errors     = array_merge( $errors, $sub_errors );
						}
					}
				}
			}
		}

		// 6. Validate array items.
		if ( is_array( $data ) ) {
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
