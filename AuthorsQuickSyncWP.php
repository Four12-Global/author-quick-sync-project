<?php
/**
 * Plugin Name: Author Quick Sync Project
 * Description: REST endpoint to receive Author/Speaker data from Airtable and create/update `author_speaker` taxonomy terms including JetEngine meta.
 * Author: Four12 Global
 * Version: 2.1.0
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/vendor/Parsedown.php';

class F12_Author_Quick_Sync {

	const ROUTE_NAMESPACE = 'four12/v1';
	const ROUTE           = '/author-sync';
	const TAXONOMY        = 'author_speaker';
	const SKU_META_KEY    = 'sku';
	const DEBUG           = false; // set to false in production

	/**
	 * Bootstraps class.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
	}

	/**
	 * Registers the REST route.
	 */
	public static function register_route() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_request' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' ); // Application‑password user needs this cap
				},
			]
		);
	}

	/**
	 * Handles the incoming POST from Airtable.
	 */
	public static function handle_request( WP_REST_Request $request ) {
		$raw   = $request->get_body();
		$data  = json_decode( $raw, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return self::error( 'invalid_json', 'Request body must be valid JSON.' );
		}

		$sku    = sanitize_text_field( $data['sku'] ?? '' );
		$fields = $data['fields'] ?? [];

		if ( empty( $sku ) ) {
			return self::error( 'missing_sku', 'SKU is required.' );
		}

		$markdown_keys = [ 'author_description', 'as_description', 'news_description' ];
		foreach ( $fields as $key => &$val ) {
			if ( in_array( $key, $markdown_keys, true ) && is_string( $val ) ) {
				$val = self::md_to_safe_html( $val );
			}
		}
		unset( $val ); // break the reference

		$name        = sanitize_text_field( $fields['name'] ?? '' );
		$slug        = sanitize_title( $fields['slug'] ?? $name );
		$description = $fields['author_description'] ?? '';

		if ( empty( $name ) ) {
			return self::error( 'missing_name', 'Author/Speaker name is required.' );
		}

		$existing_term_id = self::get_term_id_by_sku( $sku );

		// Fallback to slug lookup if no SKU match.
		if ( ! $existing_term_id ) {
			$term = get_term_by( 'slug', $slug, self::TAXONOMY );
			$existing_term_id = $term ? (int) $term->term_id : 0;
		}

		$action        = $existing_term_id ? 'updated' : 'created';
		$changed_core  = [];
		$changed_meta  = [];

		$args = [
			'name'        => $name,
			'slug'        => $slug,
			'description' => $description,
		];

		if ( $existing_term_id ) {
			$old = get_term( $existing_term_id, self::TAXONOMY );
			if ( $old ) {
				if ( $old->name !== $name ) {
					$changed_core[] = 'name';
				}
				if ( $old->description !== $description ) {
					$changed_core[] = 'description';
				}
			}

			$result = wp_update_term( $existing_term_id, self::TAXONOMY, $args );
			if ( is_wp_error( $result ) ) {
				return self::error( 'term_update_failed', $result->get_error_message() );
			}
			$term_id = (int) $result['term_id'];
		} else {
			$result = wp_insert_term( $name, self::TAXONOMY, $args );
			if ( is_wp_error( $result ) ) {
				return self::error( 'term_insert_failed', $result->get_error_message() );
			}
			$term_id      = (int) $result['term_id'];
			$changed_core = [ 'name', 'description', 'slug' ];
		}

		// Always store/update SKU term‑meta.
		if ( update_term_meta( $term_id, self::SKU_META_KEY, $sku ) ) {
			$changed_meta[] = self::SKU_META_KEY;
		}

		// Whitelisted meta fields that map 1‑to‑1.
		$meta_whitelist = [
			'profile_image',
			'as_description',
			'news_description',
			'status',
		];

		foreach ( $fields as $key => &$val ) {
			if ( in_array( $key, $meta_whitelist, true ) ) {
				$prev = get_term_meta( $term_id, $key, true );
				if ( $prev !== $val ) {
					update_term_meta( $term_id, $key, $val );
					$changed_meta[] = $key;
				}
			}
		}
		unset( $val ); // break the reference

		$response = [
			'success' => true,
			'data'    => [
				'term_id'        => $term_id,
				'term_url'       => get_term_link( $term_id ),
				'action'         => $action,
				'changed_fields' => [
					'core' => $changed_core,
					'meta' => $changed_meta,
				],
			],
		];

		return rest_ensure_response( $response );
	}

	/**
	 * Looks up a term by SKU meta.
	 */
	protected static function get_term_id_by_sku( string $sku ): int {
		$terms = get_terms( [
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'fields'     => 'ids',
			'number'     => 1,
			'meta_query' => [
				[
					'key'   => self::SKU_META_KEY,
					'value' => $sku,
					'compare' => '=',
				],
			],
		] );

		return ! empty( $terms ) ? (int) $terms[0] : 0;
	}

	/**
	 * Convert Markdown to safe HTML.
	 *
	 * @param string $text Raw Markdown (or HTML).
	 * @return string Sanitised HTML ready for output.
	 */
	protected static function md_to_safe_html( string $text ): string {
		$alreadyHtml = $text !== strip_tags( $text );

		if ( ! $alreadyHtml ) {
			$pd = new Parsedown();
			$pd->setSafeMode( true );          // strips naughty JS attributes
			$text = $pd->text( $text );
		}

		// Final belt-and-braces scrub
		return wp_kses_post( $text );
	}

	/**
	 * Uniform error wrapper.
	 */
	protected static function error( string $code, string $message ) {
		if ( self::DEBUG ) {
			error_log( "[AuthorQuickSync] $code: $message" );
		}
		return new WP_Error( $code, $message, [ 'status' => 400 ] );
	}
}

add_action( 'plugins_loaded', [ 'F12_Author_Quick_Sync', 'init' ] );
