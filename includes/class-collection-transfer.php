<?php
/**
 * Lossless moves between Friends and taxonomy-backed collections.
 *
 * @package Post_Collection
 */
namespace PostCollection;

defined( 'ABSPATH' ) || exit;

class Collection_Transfer {
	const HISTORY = '_post_collection_transfer';

	/**
	 * Move in place, or copy without modifying the source. Never download a URL.
	 *
	 * @param int      $post_id Source post.
	 * @param \WP_Term $collection Destination collection.
	 * @param bool     $copy Whether to copy.
	 * @return array|\WP_Error Transfer details.
	 */
	public static function save( $post_id, \WP_Term $collection, $copy = false ) {
		return self::locked( function () use ( $post_id, $collection, $copy ) {
			return self::save_unlocked( $post_id, $collection, $copy );
		} );
	}

	/** Serialize transfers so duplicate checks, history and undo cannot race. */
	private static function locked( $callback ) {
		$lock = 'post_collection_transfer_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) < time() - 300 ) {
				delete_option( $lock );
			}
			return new \WP_Error( 'collection_transfer_busy', __( 'Another collection transfer is running. Please try again.', 'post-collection' ) );
		}
		try {
			return $callback();
		} finally {
			delete_option( $lock );
		}
	}

	private static function save_unlocked( $post_id, \WP_Term $collection, $copy ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'friend_post_cache', Post_Collection::CPT ), true ) ) {
			return new \WP_Error( 'invalid_transfer_post', __( 'Only Friends articles and collected posts can be transferred.', 'post-collection' ) );
		}
		if ( Post_Collection::COLLECTION_TAXONOMY !== $collection->taxonomy || ! get_term( $collection->term_id, $collection->taxonomy ) || get_term_meta( $collection->term_id, 'friends_post_collection_inactive', true ) ) {
			return new \WP_Error( 'invalid_transfer_collection', __( 'Invalid or inactive collection.', 'post-collection' ) );
		}
		global $wpdb;
		// Deduplicate by original URL within this collection, including private/trash.
		// A duplicate never consumes or overwrites the source article.
		if ( $post->guid ) {
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id WHERE p.post_type = %s AND p.guid = %s AND t.taxonomy = %s AND t.term_id = %d ORDER BY p.ID LIMIT 1",
				Post_Collection::CPT, $post->guid, Post_Collection::COLLECTION_TAXONOMY, $collection->term_id
			) );
			if ( $wpdb->last_error ) {
				return new \WP_Error( 'collection_duplicate_check_failed', __( 'Could not check for an existing article.', 'post-collection' ) );
			}
			if ( $existing ) {
				return array( 'post_id' => (int) $existing, 'created' => false, 'moved' => false, 'duplicate' => true );
			}
		}
		$old_terms = wp_get_object_terms( $post->ID, Post_Collection::COLLECTION_TAXONOMY, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $old_terms ) ) {
			return $old_terms;
		}
		if ( Post_Collection::CPT === $post->post_type && in_array( (int) $collection->term_id, array_map( 'intval', $old_terms ), true ) ) {
			return array( 'post_id' => (int) $post->ID, 'created' => false, 'moved' => false, 'duplicate' => true );
		}
		$state = array( 'post_type' => $post->post_type, 'post_status' => $post->post_status, 'post_author' => (int) $post->post_author, 'terms' => array_map( 'intval', $old_terms ), 'destination' => (int) $collection->term_id );
		if ( $copy ) {
			$id = self::copy_post( $post );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		} else {
			$id = $post->ID;
			// Record state on the server, never trust a browser-supplied original author.
			$history = get_post_meta( $id, self::HISTORY, true );
			$history = is_array( $history ) ? $history : array();
			$history[] = $state;
			$saved = Collection_Storage::set_history( $id, $history );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		$result = Collection_Storage::set_terms( $id, array( (int) $collection->term_id ), Post_Collection::COLLECTION_TAXONOMY );
		if ( ! is_wp_error( $result ) ) {
			$result = self::update_fields( $id, array( 'post_type' => Post_Collection::CPT, 'post_status' => 'private' ) );
		}
		if ( is_wp_error( $result ) ) {
			if ( $copy ) {
				wp_delete_post( $id, true );
			} else {
				// The type/status update is atomic; restore terms and remove only this
				// failed operation's history entry so an older undo remains available.
				array_pop( $history );
				$rollback = self::restore_bookkeeping( $id, $state['terms'], $history );
				if ( is_wp_error( $rollback ) ) {
					return $rollback;
				}
			}
			return $result;
		}
		return array( 'post_id' => (int) $id, 'created' => (bool) $copy, 'moved' => ! $copy, 'duplicate' => false );
	}

	/**
	 * Undo the last move, preserving the post ID, comments, revisions and provenance.
	 *
	 * @param int $post_id Transferred post ID.
	 * @return int|\WP_Error
	 */
	public static function restore( $post_id ) {
		return self::locked( function () use ( $post_id ) {
			return self::restore_unlocked( $post_id );
		} );
	}

	private static function restore_unlocked( $post_id ) {
		$post = get_post( $post_id );
		$history = get_post_meta( $post_id, self::HISTORY, true );
		if ( ! $post || Post_Collection::CPT !== $post->post_type || ! is_array( $history ) || ! $history ) {
			return new \WP_Error( 'no_collection_transfer', __( 'There is no move to undo for this article.', 'post-collection' ) );
		}
		$state = array_pop( $history );
		if ( 'friend_post_cache' === $state['post_type'] ) {
			$taxonomy = defined( '\Friends\Subscription::TAXONOMY' ) ? \Friends\Subscription::TAXONOMY : 'friends-virtual-user';
			$subscriptions = get_the_terms( $post_id, $taxonomy );
			$source_exists = $state['post_author'] ? (bool) get_userdata( $state['post_author'] ) : ( is_array( $subscriptions ) && ! empty( $subscriptions ) );
			if ( ! class_exists( '\Friends\Friends' ) || ! $source_exists ) {
				return new \WP_Error( 'friends_source_unavailable', __( 'Activate Friends and restore the original account or subscription before moving this article back.', 'post-collection' ) );
			}
		}
		$terms = wp_get_object_terms( $post_id, Post_Collection::COLLECTION_TAXONOMY, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		if ( array( (int) $state['destination'] ) !== array_map( 'intval', $terms ) || (int) $post->post_author !== (int) $state['post_author'] ) {
			return new \WP_Error( 'collection_transfer_changed', __( 'The article has changed collection or owner since this move. It cannot be undone automatically.', 'post-collection' ) );
		}
		foreach ( $state['terms'] as $term_id ) {
			if ( ! get_term( $term_id, Post_Collection::COLLECTION_TAXONOMY ) ) {
				return new \WP_Error( 'collection_source_missing', __( 'The original collection no longer exists.', 'post-collection' ) );
			}
		}
		// Save the shorter stack first. If this fails, neither terms nor post fields
		// have changed, so even consecutive moves remain safe to retry.
		$original_history = get_post_meta( $post_id, self::HISTORY, true );
		$result = Collection_Storage::set_history( $post_id, $history );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = Collection_Storage::set_terms( $post_id, $state['terms'], Post_Collection::COLLECTION_TAXONOMY );
		if ( ! is_wp_error( $result ) ) {
			$result = self::update_fields( $post_id, array( 'post_type' => $state['post_type'], 'post_status' => $state['post_status'], 'post_author' => $state['post_author'] ) );
		}
		if ( is_wp_error( $result ) ) {
			$rollback = self::restore_bookkeeping( $post_id, $terms, $original_history );
			return is_wp_error( $rollback ) ? $rollback : $result;
		}
		return (int) $post_id;
	}

	/** Compensate a failed move/undo, and never hide a failed compensation. */
	private static function restore_bookkeeping( $post_id, array $terms, array $history ) {
		$term_result = Collection_Storage::set_terms( $post_id, $terms, Post_Collection::COLLECTION_TAXONOMY );
		$history_result = Collection_Storage::set_history( $post_id, $history );
		if ( is_wp_error( $term_result ) || is_wp_error( $history_result ) ) {
			return new \WP_Error(
				'collection_transfer_recovery_required',
				__( 'The move failed and its saved state could not be fully restored. Review this article before retrying.', 'post-collection' ),
				array( 'post_id' => (int) $post_id )
			);
		}
		return true;
	}

	/** Update only transfer fields, without republishing or changing article dates. */
	private static function update_fields( $id, array $fields ) {
		global $wpdb;
		if ( false === $wpdb->update( $wpdb->posts, $fields, array( 'ID' => (int) $id ) ) ) {
			return new \WP_Error( 'collection_transfer_write_failed', __( 'Could not move the article. Its source was retained.', 'post-collection' ) );
		}
		clean_post_cache( $id );
		Post_Collection::refresh_post_term_counts( $id );
		return (int) $id;
	}

	/** Copy stored data, including provenance and raw HTML revisions; do not refetch. */
	private static function copy_post( \WP_Post $post ) {
		$data = get_post( $post->ID, ARRAY_A, 'raw' );
		unset( $data['ID'], $data['filter'] );
		$data['post_type'] = Post_Collection::CPT;
		$data['post_status'] = 'private';
		$id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new \WP_Error( 'collection_copy_failed', __( 'Could not copy the article.', 'post-collection' ) );
		}
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( in_array( $key, array( self::HISTORY, '_edit_lock', '_edit_last', '_post_collection_legacy_type' ), true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				if ( ! add_post_meta( $id, $key, wp_slash( maybe_unserialize( $value ) ) ) ) {
					wp_delete_post( $id, true );
					return new \WP_Error( 'collection_copy_meta_failed', __( 'Could not copy article metadata.', 'post-collection' ) );
				}
			}
		}
		$taxonomies = array_unique( array_merge( get_object_taxonomies( $post->post_type ), get_object_taxonomies( Post_Collection::CPT ), array( 'friend-subscription', 'friends-virtual-user', 'friend_tag', 'post_tag', 'post_format' ) ) );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			$result = is_wp_error( $terms ) ? $terms : Collection_Storage::set_terms( $id, array_map( 'intval', $terms ), $taxonomy );
			if ( is_wp_error( $result ) ) {
				wp_delete_post( $id, true );
				return $result;
			}
		}
		foreach ( wp_get_post_revisions( $post->ID, array( 'order' => 'ASC' ) ) as $revision ) {
			$data = get_post( $revision->ID, ARRAY_A, 'raw' );
			unset( $data['ID'], $data['filter'] );
			$data['post_parent'] = (int) $id;
			$data['post_name'] = $id . '-revision-v1';
			$result = wp_insert_post( wp_slash( $data ), true );
			if ( is_wp_error( $result ) || ! $result ) {
				wp_delete_post( $id, true );
				return new \WP_Error( 'collection_copy_revision_failed', __( 'Could not copy the original article revisions.', 'post-collection' ) );
			}
		}
		return (int) $id;
	}
}
