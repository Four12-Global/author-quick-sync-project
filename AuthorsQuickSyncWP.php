<?php
// WordPress plugin: Four12 – Quick Sync
//
// Purpose:
// - Receives REST requests from Airtable to sync author-speaker taxonomy terms.
// - Uses 'author_description' (HTML) from Airtable as the term description.
//
// Steps:
// 1. Registers the REST endpoint (handled by JetEngine for taxonomy registration).
// 2. Handles POST requests to /four12/v1/tax-sync.
// 3. Finds or creates/updates the taxonomy term by SKU meta.
// 4. Updates the term description and meta fields.
// 5. Optionally sideloads a profile image.
// 6. Returns a detailed response for logging and debugging.
//
// Note: Taxonomy registration is handled by JetEngine, not this plugin.

/**
 * Plugin Name: Four12 – Quick Sync
 * Description: Instantly syncs "Author aker" records from Airtable into the <code>author_speaker</code> taxonomy through an authenticated REST endpoint.
 * Author: Four12 Global
 * Version: 1.0.1
 */

defined('ABSPATH') || exit;

// ─────────────────────────────────────────────────────────
//   1.  TAXONOMY REGISTRATION  (slug: author_speaker)
//   NOTE: Registration removed. Handled by JetEngine.
// ─────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────
//   2.  REST ENDPOINT            /four12/v1/tax-sync
// ─────────────────────────────────────────────────────────
add_action('rest_api_init', function () {

	register_rest_route('four12/v1', '/tax-sync', [
		'methods'             => 'POST',
		'callback'            => 'fqs_handle_tax_sync',
		'permission_callback' => function () {
			// Application‑Password user (api_sync) must be authenticated
			// and have capability to edit terms/posts.
			return current_user_can('edit_posts');
		},
	]);
});

// -------------------------------------------------------------------------
//   3.  ENDPOINT HANDLER
// -------------------------------------------------------------------------
/**
 * Handles syncing of Author/Speaker taxonomy via REST.
 *
 * @param WP_REST_Request $req The REST request object.
 * @return WP_REST_Response The REST response object.
 */
function fqs_handle_tax_sync($req)
{
	$log_prefix = '[QuickSync] ' . date('Y-m-d H:i:s') . ' ';
	try {
		// Ensure WordPress is loaded
		if (! function_exists('sanitize_text_field')) {
			require_once dirname(__FILE__, 4) . '/wp-load.php';
		}
		if (! function_exists('sanitize_text_field')) {
			error_log($log_prefix . 'WordPress functions unavailable.');
			return null;
		}
		$body   = $req->get_json_params();
		$sku    = sanitize_text_field($body['sku'] ?? '');
		$fields = $body['fields'] ?? [];
		$user   = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
		$user_info = $user && is_object($user) && method_exists($user, 'exists') && $user->exists() ? $user->user_login : 'unknown';

		// Log incoming request
		error_log($log_prefix . "Request by {$user_info}: " . json_encode($body));
		error_log($log_prefix . "Fields received: " . json_encode($fields));

		// Validate required parameters
		if (! $sku || ! isset($fields['author_title'])) {
			error_log($log_prefix . 'Bad payload. SKU or author_title missing.');
			return class_exists('WP_REST_Response') ? new WP_REST_Response(['error' => 'Bad payload: SKU or author_title missing.'], 400) : null;
		}

		/* ▸ Locate existing term by sku meta */
		$existing = get_terms([
			'taxonomy'   => 'author_speaker',
			'hide_empty' => false,
			'meta_query' => [
				[
					'key'   => 'sku',
					'value' => $sku,
				],
			],
		]);
		if (is_wp_error($existing)) {
			error_log($log_prefix . 'get_terms failed: ' . $existing->get_error_message());
			return class_exists('WP_REST_Response') ? new WP_REST_Response(['error' => 'get_terms failed: ' . $existing->get_error_message()], 500) : null;
		}
		$term_id = $existing[0]->term_id ?? 0;

		/* ▸ Build args */
		$args = [
			'description' => wp_kses_post($fields['author_description'] ?? ''),
			'slug'        => sanitize_title($fields['author_title']),
			'meta_input'  => ['sku' => $sku],
		];
		error_log($log_prefix . "Args for insert/update: " . json_encode($args));

		/* ▸ Insert or update */
		if (! $term_id) {
			$insert = wp_insert_term(wp_unslash($fields['author_title']), 'author_speaker', $args);
			if (is_wp_error($insert)) {
				// If the term exists by slug, find its ID and continue
				if ($insert->get_error_code() === 'term_exists') {
					$exists = term_exists(sanitize_title($fields['author_title']), 'author_speaker');
					$term_id = is_array($exists) ? $exists['term_id'] : $exists;
					if (! $term_id) {
						error_log($log_prefix . 'term_exists but could not resolve term_id for slug: ' . sanitize_title($fields['author_title']));
						return class_exists('WP_REST_Response') ? new WP_REST_Response(['error' => 'Could not resolve existing term_id for slug.'], 500) : null;
					}
				} else {
					error_log($log_prefix . 'wp_insert_term failed: ' . $insert->get_error_message());
					return class_exists('WP_REST_Response') ? new WP_REST_Response(['error' => 'wp_insert_term failed: ' . $insert->get_error_message()], 500) : null;
				}
			} else {
				$term_id = $insert['term_id'];
				error_log($log_prefix . "Inserted new term {$term_id} for SKU {$sku}");
			}
		} else {
			$args['name'] = wp_unslash($fields['author_title']);
			$update       = wp_update_term($term_id, 'author_speaker', $args);
			if (is_wp_error($update)) {
				error_log($log_prefix . 'wp_update_term failed: ' . $update->get_error_message());
				return class_exists('WP_REST_Response') ? new WP_REST_Response(['error' => 'wp_update_term failed: ' . $update->get_error_message()], 500) : null;
			}
			error_log($log_prefix . "Updated term {$term_id} for SKU {$sku}");
		}

		/* ▸ Save description as meta fields */
		$description = wp_kses_post($fields['author_description'] ?? '');
		error_log($log_prefix . "Saving description/meta: " . $description);
		update_term_meta($term_id, 'as_description', $description);
		update_term_meta($term_id, 'Description', $description);

		// media

		if ( ! empty( $fields['profile_image_link'] ) ) {
			$image_url = esc_url_raw( $fields['profile_image_link'] );
			$attachment_id = 0;

			// 1) Try to find an existing attachment by URL
			if ( function_exists( 'attachment_url_to_postid' ) ) {
				$attachment_id = attachment_url_to_postid( $image_url );
			}

			// 2) If it’s not in the library, fall back to sideloading
			if ( ! $attachment_id ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';

				$maybe_id = media_sideload_image( $image_url, 0, null, 'id' );
				if ( is_wp_error( $maybe_id ) ) {
					error_log( '[QuickSync] media_sideload_image failed for ' 
							. $image_url . ': ' 
							. $maybe_id->get_error_message() );
				} else {
					$attachment_id = $maybe_id;
				}
			}

			// 3) If we now have an attachment ID, save it to term meta
			if ( $attachment_id ) {
				update_term_meta( $term_id, 'profile_image', $attachment_id );
			}
		}

		// Return more details in the response
		$term = get_term($term_id, 'author_speaker');
		$meta = get_term_meta($term_id);
		$response = [
			'term_id' => $term_id,
			'status' => 'synced',
			'term' => $term,
			'meta' => $meta,
		];
		error_log($log_prefix . "Sync success for SKU {$sku}: " . json_encode($response));
		return function_exists('rest_ensure_response') ? rest_ensure_response($response) : $response;
	} catch (Throwable $e) {
		error_log($log_prefix . 'UNCAUGHT ERROR: ' . $e->getMessage());
		return class_exists('WP_REST_Response') ? new WP_REST_Response(['error' => 'Internal server error', 'details' => $e->getMessage()], 500) : null;
	}
}
