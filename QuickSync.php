<?php
/**
 * Plugin Name: Four12 – Quick Sync
 * Description: Instantly syncs "Author / Speaker" records from Airtable into the <code>author_speaker</code> taxonomy through an authenticated REST endpoint.
 * Author: Four12 Global
 * Version: 1.0.1
 */

defined( 'ABSPATH' ) || exit;

/* ─────────────────────────────────────────────────────────
   1.  TAXONOMY REGISTRATION  (slug: author_speaker)
   ───────────────────────────────────────────────────────── */
add_action( 'init', function () {

	$args = [
		'label'        => 'Authors / Speakers',
		'labels'       => [
			'name'          => 'Authors / Speakers',
			'singular_name' => 'Author / Speaker',
		],
		'public'       => false,
		'show_ui'      => true,
		'show_in_rest' => true,      // exposes /wp-json/wp/v2/author_speaker
		'meta_box_cb'  => false,
	];

	register_taxonomy( 'author_speaker', 'post', $args );
} );

/* ─────────────────────────────────────────────────────────
   2.  REST ENDPOINT            /wp-json/four12/v1/tax-sync
   ───────────────────────────────────────────────────────── */
add_action( 'rest_api_init', function () {

	register_rest_route( 'four12/v1', '/tax-sync', [
		'methods'             => 'POST',
		'callback'            => 'fqs_handle_tax_sync',
		'permission_callback' => function () {
			// Application‑Password user (api_sync) must be authenticated
			// and have capability to edit terms/posts.
			return current_user_can( 'edit_posts' );
		},
	] );
} );

/* -------------------------------------------------------------------------
   3.  ENDPOINT HANDLER
   ------------------------------------------------------------------------- */
function fqs_handle_tax_sync( WP_REST_Request $req ) {

	$body       = $req->get_json_params();
	$record_id  = sanitize_text_field( $body['recordId'] ?? '' );
	$fields     = $body['fields'] ?? [];

	if ( ! $record_id || ! isset( $fields['author_title'] ) ) {
		return new WP_REST_Response( [ 'error' => 'Bad payload' ], 400 );
	}

	/* ▸ Locate existing term by airtable_id meta */
	$existing = get_terms( [
		'taxonomy'   => 'author_speaker',
		'hide_empty' => false,
		'meta_query' => [
			[
				'key'   => 'airtable_id',
				'value' => $record_id,
			],
		],
	] );
	$term_id = $existing[0]->term_id ?? 0;

	/* ▸ Trash request */
	if ( isset( $fields['status'] ) && $fields['status'] === 'trash' ) {
		if ( $term_id ) wp_delete_term( $term_id, 'author_speaker' );
		return rest_ensure_response( [ 'action' => 'trashed', 'recordId' => $record_id ] );
	}

	/* ▸ Build args */
	$args = [
		'description' => wp_kses_post( $fields['author_description'] ?? '' ),
		'slug'        => sanitize_title( $fields['author_title'] ),
		'meta_input'  => [ 'airtable_id' => $record_id ],
	];

	/* ▸ Insert or update */
	if ( ! $term_id ) {
		$insert = wp_insert_term( wp_unslash( $fields['author_title'] ), 'author_speaker', $args );
		if ( is_wp_error( $insert ) ) {
			// If the term exists by slug, find its ID and continue
			if ( $insert->get_error_code() === 'term_exists' ) {
				$exists = term_exists( sanitize_title( $fields['author_title'] ), 'author_speaker' );
				$term_id = is_array( $exists ) ? $exists['term_id'] : $exists;
			} else {
				return $insert;
			}
		} else {
			$term_id = $insert['term_id'];		
		}
	} else {
		$args['name'] = wp_unslash( $fields['author_title'] );
		$update       = wp_update_term( $term_id, 'author_speaker', $args );
		if ( is_wp_error( $update ) ) return $update;
	}

	/* ▸ Profile image (sideload) */
	if ( ! empty( $fields['profile_image_url'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$image_id = media_sideload_image( esc_url_raw( $fields['profile_image_url'] ), 0, null, 'id' );
		if ( ! is_wp_error( $image_id ) ) {
			update_term_meta( $term_id, 'profile_image', $image_id );
		}
	}

	return rest_ensure_response( [ 'term_id' => $term_id, 'status' => 'synced' ] );
}