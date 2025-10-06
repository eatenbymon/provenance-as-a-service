<?php
/**
 * ---
 * generated: true
 * generated_at: 2025-10-05T12:00:00Z
 * generator_model: gemini-cli-agent
 * prompt_id: PLG-004
 * system_prompt_summary: "Implement a JSON canonicalization function (JCS/RFC8785)."
 * status: pending_review
 * human_reviewer: null
 * ---
 *
 * @package Provenance_As_A_Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class PAAS_Manifest_Utils {

    /**
     * Recursively sort an array by its keys.
     *
     * @param array $array The array to sort.
     */
    private static function recursive_ksort( &$array ) {
        if ( ! is_array( $array ) ) {
            return;
        }

        ksort( $array, SORT_STRING );

        foreach ( $array as &$value ) {
            if ( is_array( $value ) ) {
                self::recursive_ksort( $value );
            }
        }
    }

    /**
     * Canonicalize a PHP array into a JCS (RFC 8785) compliant JSON string.
     *
     * @param array $data The data to canonicalize.
     * @return string The canonical JSON string.
     */
    public static function paas_canonicalize_json( $data ) {
        if ( ! is_array( $data ) ) {
            return json_encode( $data );
        }

        // Deep clone the array to avoid modifying the original
        $cloned_data = unserialize( serialize( $data ) );

        self::recursive_ksort( $cloned_data );

        return json_encode( $cloned_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }
}
