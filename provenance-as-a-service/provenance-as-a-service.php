<?php
/**
 * ---
 * generated: true
 * generated_at: 2025-10-05T12:00:00Z
 * generator_model: gemini-cli-agent
 * prompt_id: PLG-001
 * system_prompt_summary: "Create a WordPress plugin skeleton with header and activation hooks."
 * status: pending_review
 * human_reviewer: null
 * ---
 *
 * @package           Provenance_As_A_Service
 * @author            Gemini CLI Agent
 * @copyright         2025, Gemini
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Provenance as a Service
 * Plugin URI:        https://github.com/user/provenance-as-a-service
 * Description:       Provides content provenance, canary detection, and monetization features for creators.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Gemini CLI Agent
 * Author URI:        https://gemini.google.com/
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       provenance-as-a-service
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/includes/class-paas-manifest-utils.php';


/**
 * Activation hook.
 *
 * Creates all tables.
 */
function paas_activate() {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Manifests table
    $table_name_manifests = $wpdb->prefix . 'paas_manifests';
    $sql_manifests = "CREATE TABLE $table_name_manifests (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        content_uri varchar(255) DEFAULT '' NOT NULL,
        content_hash varchar(255) DEFAULT '' NOT NULL,
        manifest_json longtext NOT NULL,
        signature_b64 longtext NOT NULL,
        pubkey_pem longtext NOT NULL,
        verified tinyint(1) NOT NULL,
        created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        anchored tinyint(1) NOT NULL,
        anchor_info longtext NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_manifests );

    // Canaries table
    $table_name_canaries = $wpdb->prefix . 'paas_canaries';
    $sql_canaries = "CREATE TABLE $table_name_canaries (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        manifest_id mediumint(9) NOT NULL,
        type enum('text','image') NOT NULL,
        value longtext NOT NULL,
        created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_canaries );

    // Detection Logs table
    $table_name_detection_logs = $wpdb->prefix . 'paas_detection_logs';
    $sql_detection_logs = "CREATE TABLE $table_name_detection_logs (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        canary_id mediumint(9) NOT NULL,
        source_url longtext NOT NULL,
        provider varchar(255) DEFAULT '' NOT NULL,
        score float NOT NULL,
        detected_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        actioned tinyint(1) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_detection_logs );

    // Download Requests table
    $table_name_download_requests = $wpdb->prefix . 'paas_download_requests';
    $sql_download_requests = "CREATE TABLE $table_name_download_requests (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        file_id bigint(20) NOT NULL,
        buyer_email varchar(255) DEFAULT '' NOT NULL,
        payment_status enum('pending','paid','failed') NOT NULL,
        key_issued tinyint(1) NOT NULL,
        issued_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_download_requests );
}
register_activation_hook( __FILE__, 'paas_activate' );

/**
 * Deactivation hook.
 */
function paas_deactivate() {
    // Placeholder for deactivation logic
}
register_deactivation_hook( __FILE__, 'paas_deactivate' );

/**
 * Handles the save_post action.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an existing post being updated or not.
 */
function paas_save_post_handler( $post_id, $post, $update ) {
    // Ignore revisions and auto-drafts
    if ( wp_is_post_revision( $post_id ) || $post->post_status === 'auto-draft' ) {
        return;
    }

    // Check if the post has a featured image
    if ( has_post_thumbnail( $post_id ) ) {
        $attachment_id = get_post_thumbnail_id( $post_id );
        $content_uri = wp_get_attachment_url( $attachment_id );
        $file_path = get_attached_file( $attachment_id );

        if ( $file_path && file_exists( $file_path ) ) {
            $content_hash = 'sha256:' . hash_file( 'sha256', $file_path );
            $author_id = $post->post_author;
            $author_data = get_userdata( $author_id );

            // Build the manifest data array based on SPEC.md
            $manifest_data = [
                'manifest_version' => '1.0',
                'post_id' => $post_id,
                'content_uri' => $content_uri,
                'content_hash' => $content_hash,
                'content_type' => get_post_mime_type( $attachment_id ),
                'created_at' => get_post_time( 'c', true, $post ),
                'author' => [
                    'user_id' => $author_id,
                    'display_name' => $author_data->display_name,
                    // pubkey_fingerprint and did will be added later
                    'pubkey_fingerprint' => null, 
                    'did' => null,
                ],
                'license' => 'All Rights Reserved. No training without paid license.',
                'canaries' => [], // Canaries will be added in a future task
                'provenance' => [],
            ];

            // Canonicalize the manifest
            $canonical_manifest = PAAS_Manifest_Utils::paas_canonicalize_json( $manifest_data );

            // Save for debugging and next steps
            update_post_meta( $post_id, '_paas_content_uri', $content_uri );
            update_post_meta( $post_id, '_paas_content_hash', $content_hash );
            update_post_meta( $post_id, '_paas_canonical_manifest', $canonical_manifest );
        }
    }
}
add_action( 'save_post', 'paas_save_post_handler', 10, 3 );

/**
 * Register the REST API routes.
 */
function paas_register_rest_routes() {
    register_rest_route( 'provenance/v1', '/signed-manifest', [
        'methods' => 'POST',
        'callback' => 'paas_handle_signed_manifest',
        'permission_callback' => function () {
            // For now, only logged-in users can submit.
            // You might want to add more specific capability checks.
            return is_user_logged_in();
        }
    ]);
}
add_action( 'rest_api_init', 'paas_register_rest_routes' );

/**
 * Add public key field to user profile.
 *
 * @param WP_User $user The user object.
 */
function paas_add_pubkey_field( $user ) {
    ?>
    <h3>Provenance & Keys</h3>
    <table class="form-table">
        <tr>
            <th><label for="pubkey_pem">Public Key (PEM format)</label></th>
            <td>
                <textarea name="pubkey_pem" id="pubkey_pem" rows="5" cols="30"><?php echo esc_attr( get_the_author_meta( 'pubkey_pem', $user->ID ) ); ?></textarea><br />
                <span class="description">Please enter your ECDSA P-256 public key in PEM format.</span>
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'show_user_profile', 'paas_add_pubkey_field' );
add_action( 'edit_user_profile', 'paas_add_pubkey_field' );

/**
 * Save public key field from user profile.
 *
 * @param int $user_id The user ID.
 * @return bool
 */
function paas_save_pubkey_field( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return false;
    }
    update_user_meta( $user_id, 'pubkey_pem', $_POST['pubkey_pem'] );
}
add_action( 'personal_options_update', 'paas_save_pubkey_field' );
add_action( 'edit_user_profile_update', 'paas_save_pubkey_field' );

/**
 * Append a verification badge to the post content.
 *
 * @param string $content The post content.
 * @return string
 */
function paas_append_verification_badge( $content ) {
    if ( is_single() && get_post_type() === 'post' ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'paas_manifests';
        $post_id = get_the_ID();

        $manifest = $wpdb->get_row( $wpdb->prepare( 
            "SELECT * FROM $table_name WHERE post_id = %d ORDER BY created_at DESC LIMIT 1", 
            $post_id 
        ) );

        $badge_html = '<div class="paas-verification-badge-container">_PLACEHOLDER_</div>';

        if ( $manifest && $manifest->verified ) {
            $badge_text = 'Verified';
            $badge_class = 'verified';
        } else if ( $manifest ) {
            $badge_text = 'Verification Failed';
            $badge_class = 'failed';
        } else {
            $badge_text = 'Not Signed';
            $badge_class = 'not-signed';
        }

        $full_badge = str_replace('_PLACEHOLDER_', 
            sprintf('<span class="paas-badge %s">%s</span><button type="button" class="paas-verify-btn" data-post-id="%d">Verify Now</button><p class="paas-verify-status"></p>', 
                esc_attr($badge_class), 
                esc_html($badge_text),
                $post_id
            ),
            $badge_html
        );

        return $content . $full_badge;
    }
    return $content;
}
add_filter( 'the_content', 'paas_append_verification_badge' );

/**
 * AJAX handler for verifying a manifest.
 */
function paas_ajax_verify_manifest() {
    check_ajax_referer( 'paas_verify_nonce', 'nonce' );

    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    if ( ! $post_id ) {
        wp_send_json_error( [ 'message' => 'Invalid Post ID.' ] );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'paas_manifests';
    $manifest = $wpdb->get_row( $wpdb->prepare( 
        "SELECT * FROM $table_name WHERE post_id = %d ORDER BY created_at DESC LIMIT 1", 
        $post_id 
    ) );

    if ( ! $manifest ) {
        wp_send_json_error( [ 'message' => 'No manifest found for this post.' ] );
    }

    $signature = base64_decode( $manifest->signature_b64 );
    $pubkey = openssl_pkey_get_public( $manifest->pubkey_pem );
    $verified = openssl_verify( $manifest->manifest_json, $signature, $pubkey, OPENSSL_ALGO_SHA256 );

    if ( $verified === 1 ) {
        wp_send_json_success( [ 'message' => 'Verification successful!' ] );
    } else {
        wp_send_json_error( [ 'message' => 'Verification failed. The signature does not match.' ] );
    }
}
add_action( 'wp_ajax_paas_verify_manifest', 'paas_ajax_verify_manifest' );
add_action( 'wp_ajax_nopriv_paas_verify_manifest', 'paas_ajax_verify_manifest' );


/**
 * Enqueue scripts for the admin area.
 */
function paas_admin_enqueue_scripts( $hook ) {
    global $post;

    if ( $hook == 'post-new.php' || $hook == 'post.php' ) {
        if ( 'post' === $post->post_type ) {
            wp_enqueue_script(
                'paas-main',
                plugin_dir_url( __FILE__ ) . 'assets/js/paas-main.js',
                [],
                '0.1.0',
                true
            );
        }
    }
}
add_action( 'admin_enqueue_scripts', 'paas_admin_enqueue_scripts' );

/**
 * Enqueue scripts and styles for the public-facing pages.
 */
function paas_public_enqueue_assets() {
    if ( is_single() && get_post_type() === 'post' ) {
        wp_enqueue_style(
            'paas-style',
            plugin_dir_url( __FILE__ ) . 'assets/css/paas-style.css',
            [],
            '0.1.0'
        );
        wp_enqueue_script(
            'paas-public',
            plugin_dir_url( __FILE__ ) . 'assets/js/paas-public.js',
            [],
            '0.1.0',
            true
        );

        // Pass data to the script
        wp_localize_script( 'paas-public', 'paas_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'paas_verify_nonce' ),
        ] );
    }
}
add_action( 'wp_enqueue_scripts', 'paas_public_enqueue_assets' );

/**
 * Add meta box to the post editor screen.
 */
function paas_add_meta_boxes() {
    add_meta_box(
        'paas_provenance_meta_box',
        'Provenance & Signing',
        'paas_render_meta_box',
        'post', // or your custom post type
        'normal',
        'high'
    );
}
add_action( 'add_meta_boxes', 'paas_add_meta_boxes' );

/**
 * Render the meta box content.
 *
 * @param WP_Post $post The post object.
 */
function paas_render_meta_box( $post ) {
    // Add a nonce field so we can check for it later.
    wp_nonce_field( 'paas_save_meta_box_data', 'paas_meta_box_nonce' );

    $canonical_manifest = get_post_meta( $post->ID, '_paas_canonical_manifest', true );

    ?>
    <style>
        #paas-provenance-meta-box .provenance-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 1rem; }
        #paas-provenance-meta-box textarea { width: 100%; font-family: monospace; }
        #paas-provenance-meta-box button { margin-right: 1rem; }
    </style>
    <div class="provenance-grid">
        <div>
            <h4>1. Generate Keys</h4>
            <button type="button" id="paas-generate-keys" class="button">Generate New Key Pair</button>
        </div>
        <div>
            <label for="paas-public-key"><strong>Public Key (PEM)</strong></label>
            <textarea id="paas-public-key" rows="4"></textarea>
            <label for="paas-private-key"><strong>Private Key (PEM)</strong> - Keep this safe!</label>
            <textarea id="paas-private-key" rows="4"></textarea>
        </div>

        <div>
            <h4>2. Sign Manifest</h4>
            <button type="button" id="paas-sign-manifest" class="button">Sign Manifest</button>
        </div>
        <div>
            <label for="paas-manifest-to-sign"><strong>Canonical Manifest to Sign</strong></label>
            <textarea id="paas-manifest-to-sign" rows="6" readonly><?php echo esc_textarea( $canonical_manifest ); ?></textarea>
            <label for="paas-signature"><strong>Generated Signature (Base64)</strong></label>
            <textarea id="paas-signature" rows="2"></textarea>
        </div>

        <div>
            <h4>3. Submit to Server</h4>
            <button type="button" id="paas-submit-signature" class="button-primary">Submit Signature</button>
        </div>
        <div>
            <p id="paas-submit-status"></p>
        </div>
    </div>
    <?php
}

/**
 * Handles the incoming signed manifest.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function paas_handle_signed_manifest( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    $post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
    $client_manifest = isset( $params['manifest'] ) ? $params['manifest'] : null;
    $signature_b64 = isset( $params['signature'] ) ? $params['signature'] : '' ;
    $pubkey_pem = isset( $params['pubkey_pem'] ) ? $params['pubkey_pem'] : '' ;

    if ( ! $post_id || ! $client_manifest || ! $signature_b64 || ! $pubkey_pem ) {
        return new WP_Error( 'bad_request', 'Missing required parameters.', [ 'status' => 400 ] );
    }

    // 1. Get the server-side canonical manifest
    $server_canonical_manifest = get_post_meta( $post_id, '_paas_canonical_manifest', true );
    if ( ! $server_canonical_manifest ) {
        return new WP_Error( 'not_found', 'Server-side manifest not found for this post.', [ 'status' => 404 ] );
    }

    // 2. Verify the client manifest matches the server-generated one.
    // The client should have canonicalized the manifest before signing.
    if ( $client_manifest !== $server_canonical_manifest ) {
        return new WP_Error( 'manifest_mismatch', 'Client manifest does not match server-generated manifest.', [ 'status' => 400 ] );
    }

    // 3. Verify the signature
    $signature = base64_decode( $signature_b64 );
    $pubkey = openssl_pkey_get_public( $pubkey_pem );
    $verified = openssl_verify( $client_manifest, $signature, $pubkey, OPENSSL_ALGO_SHA256 );

    if ( $verified !== 1 ) {
        return new WP_Error( 'invalid_signature', 'Signature verification failed.', [ 'status' => 400 ] );
    }

    // 4. Save the verified manifest to the database
    global $wpdb;
    $table_name = $wpdb->prefix . 'paas_manifests';

    $result = $wpdb->insert(
        $table_name,
        [
            'post_id' => $post_id,
            'content_uri' => get_post_meta( $post_id, '_paas_content_uri', true ),
            'content_hash' => get_post_meta( $post_id, '_paas_content_hash', true ),
            'manifest_json' => $client_manifest,
            'signature_b64' => $signature_b64,
            'pubkey_pem' => $pubkey_pem,
            'verified' => 1,
            'created_at' => current_time( 'mysql', 1 ),
            'anchored' => 0,
            'anchor_info' => ''
        ]
    );

    if ( $result === false ) {
        return new WP_Error( 'db_error', 'Could not save the manifest to the database.', [ 'status' => 500 ] );
    }

    return new WP_REST_Response( [ 'status' => 'ok', 'manifest_id' => $wpdb->insert_id, 'verified' => true ], 200 );
}

