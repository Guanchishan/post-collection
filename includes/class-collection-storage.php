<?php
/**
 * Verify persisted collection state, including SQL errors hidden by core APIs.
 *
 * @package Post_Collection
 */
namespace PostCollection;

defined( 'ABSPATH' ) || exit;

class Collection_Storage {
	/**
	 * Set exact term IDs (or append them) and read back uncached relationships.
	 *
	 * wp_set_object_terms() can return IDs even when a relationship INSERT fails.
	 *
	 * @return int[]|\WP_Error Persisted term IDs, or a retryable storage error.
	 */
	public static function set_terms( $post_id, array $terms, $taxonomy, $append = false ) {
		$terms = array_values( array_unique( array_map( 'intval', $terms ) ) );
		foreach ( $terms as $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				return new \WP_Error( 'collection_term_missing', __( 'A required collection or tag no longer exists.', 'post-collection' ) );
			}
		}
		$expected = $terms;
		if ( $append ) {
			$previous = self::get_terms( $post_id, $taxonomy );
			if ( is_wp_error( $previous ) ) {
				return $previous;
			}
			$expected = array_values( array_unique( array_merge( $previous, $terms ) ) );
		}
		$result = wp_set_object_terms( $post_id, $terms, $taxonomy, $append );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$actual = self::get_terms( $post_id, $taxonomy );
		if ( is_wp_error( $actual ) ) {
			return $actual;
		}
		sort( $expected );
		sort( $actual );
		wp_cache_delete( $post_id, $taxonomy . '_relationships' );
		if ( $expected !== $actual ) {
			return new \WP_Error( 'collection_relationship_write_failed', __( 'Could not save the article collection or tags. Please retry.', 'post-collection' ) );
		}
		return $actual;
	}

	private static function get_terms( $post_id, $taxonomy ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT tt.term_id FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id WHERE r.object_id = %d AND tt.taxonomy = %s",
			$post_id, $taxonomy
		) );
		if ( $wpdb->last_error ) {
			return new \WP_Error( 'collection_relationship_read_failed', __( 'Could not verify the article collection or tags.', 'post-collection' ) );
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/** Persist the undo stack and verify it without trusting metadata filters/cache. */
	public static function set_history( $post_id, array $history ) {
		global $wpdb;
		update_post_meta( $post_id, Collection_Transfer::HISTORY, wp_slash( $history ) );
		$values = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$post_id, Collection_Transfer::HISTORY
		) );
		wp_cache_delete( $post_id, 'post_meta' );
		if ( $wpdb->last_error || 1 !== count( $values ) || $history !== maybe_unserialize( $values[0] ) ) {
			return new \WP_Error( 'collection_transfer_state_failed', __( 'Could not save the article move history. Please retry.', 'post-collection' ) );
		}
		return true;
	}
}
