<?php
/**
 * Resumable, site-local upgrades from user-backed collections.
 *
 * @package Post_Collection
 */
namespace PostCollection;

defined( 'ABSPATH' ) || exit;

class Collection_Migration {

	const VERSION = 1;
	const OPTION = 'post_collection_schema_version';
	const STATE = 'post_collection_migration_state';

	/**
	 * Run one bounded batch. No remote fetches or publication hooks are invoked.
	 *
	 * @param int $limit Maximum number of posts to examine.
	 * @return bool|\WP_Error True when finished, false when another batch is needed.
	 */
	public static function run( $limit = 100 ) {
		global $wpdb;
		if ( self::VERSION === (int) get_option( self::OPTION ) ) {
			return true;
		}
		if ( ! taxonomy_exists( Post_Collection::COLLECTION_TAXONOMY ) ) {
			return new \WP_Error( 'collection_taxonomy_missing', __( 'The collection taxonomy is not registered.', 'post-collection' ) );
		}
		// add_option is atomic: concurrent admin/cron requests cannot migrate twice.
		$lock = 'post_collection_migration_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) < time() - 300 ) {
				delete_option( $lock );
			}
			return false;
		}
		try {
			$state = get_option( self::STATE, array() );
			$users = new \WP_User_Query( array( 'role' => 'post_collection', 'orderby' => 'ID', 'order' => 'ASC' ) );
			$remaining = max( 1, (int) $limit );
			foreach ( $users->get_results() as $user ) {
				$term = self::collection_for_user( $user );
				if ( is_wp_error( $term ) ) {
					return $term;
				}
				$cursor = isset( $state[ $user->ID ] ) ? (int) $state[ $user->ID ] : 0;
				$ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND ID > %d AND post_type IN (%s, %s, %s) ORDER BY ID ASC LIMIT %d",
					$user->ID, $cursor, 'friend_post_cache', 'post_collection', Post_Collection::CPT, $remaining
				) );
				if ( $wpdb->last_error ) {
					return new \WP_Error( 'collection_migration_read_failed', __( 'Could not read legacy collection posts. The upgrade will be retried.', 'post-collection' ) );
				}
				foreach ( $ids as $id ) {
					$post = get_post( $id );
					// Recheck ownership/type after selecting candidates, including trash/drafts.
					if ( $post && (int) $post->post_author === (int) $user->ID && in_array( $post->post_type, array( 'friend_post_cache', 'post_collection', Post_Collection::CPT ), true ) ) {
						$tags = self::migrate_tags( $id );
						if ( is_wp_error( $tags ) ) {
							return $tags;
						}
						$terms = wp_get_object_terms( $id, Post_Collection::COLLECTION_TAXONOMY, array( 'fields' => 'ids' ) );
						if ( is_wp_error( $terms ) ) {
							return $terms;
						}
						if ( Post_Collection::CPT !== $post->post_type || ! $terms ) {
							$result = Collection_Storage::set_terms( $id, array( (int) $term->term_id ), Post_Collection::COLLECTION_TAXONOMY, true );
							if ( is_wp_error( $result ) ) {
								return $result;
							}
						}
						if ( Post_Collection::CPT !== $post->post_type ) {
							// Keep an audit record before changing the type. Do not recreate posts,
							// touch dates/status/author, or send Friends/ActivityPub notifications.
							if ( ! metadata_exists( 'post', $id, '_post_collection_legacy_type' ) && ! add_post_meta( $id, '_post_collection_legacy_type', $post->post_type, true ) ) {
								return new \WP_Error( 'collection_migration_meta_failed', __( 'Could not record the original post type.', 'post-collection' ) );
							}
							$changed = $wpdb->update( $wpdb->posts, array( 'post_type' => Post_Collection::CPT ), array( 'ID' => (int) $id, 'post_author' => (int) $user->ID, 'post_type' => $post->post_type ), array( '%s' ), array( '%d', '%d', '%s' ) );
							if ( false === $changed ) {
								return new \WP_Error( 'collection_migration_write_failed', __( 'Could not migrate a collection post. The upgrade will be retried.', 'post-collection' ) );
							}
							clean_post_cache( $id );
						}
						Post_Collection::refresh_post_term_counts( $id );
					}
					$state[ $user->ID ] = (int) $id;
					update_option( self::STATE, $state, false );
					--$remaining;
				}
				if ( ! $remaining ) {
					return false;
				}
			}
			update_option( self::OPTION, self::VERSION, false );
			if ( self::VERSION !== (int) get_option( self::OPTION ) ) {
				return new \WP_Error( 'collection_version_write_failed', __( 'Could not record the completed collection upgrade.', 'post-collection' ) );
			}
			delete_option( self::STATE );
			return true;
		} finally {
			delete_option( $lock );
		}
	}

	/** Copy legacy post tags into the app's taxonomy, preserving original terms. */
	private static function migrate_tags( $post_id ) {
		$taxonomy = defined( '\Friends\Friends::TAG_TAXONOMY' ) ? \Friends\Friends::TAG_TAXONOMY : 'friend_tag';
		if ( 'post_tag' === $taxonomy ) {
			return true;
		}
		$tags = wp_get_object_terms( $post_id, 'post_tag' );
		if ( is_wp_error( $tags ) ) {
			return $tags;
		}
		foreach ( $tags as $tag ) {
			$target = get_term_by( 'slug', $tag->slug, $taxonomy );
			if ( ! $target ) {
				$created = wp_insert_term( $tag->name, $taxonomy, array( 'slug' => $tag->slug, 'description' => $tag->description ) );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				$target = get_term( $created['term_id'], $taxonomy );
			}
			$result = Collection_Storage::set_terms( $post_id, array( (int) $target->term_id ), $taxonomy, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * Map a legacy account to its own term, without merging unrelated same-slug terms.
	 *
	 * @param \WP_User $user Legacy collection owner.
	 * @return \WP_Term|\WP_Error
	 */
	public static function collection_for_user( \WP_User $user ) {
		if ( ! $user->exists() || ! $user->has_cap( 'post_collection' ) ) {
			return new \WP_Error( 'invalid_collection_user', __( 'Invalid collection account.', 'post-collection' ) );
		}
		$terms = get_terms( array( 'taxonomy' => Post_Collection::COLLECTION_TAXONOMY, 'hide_empty' => false, 'meta_key' => '_post_collection_legacy_user_id', 'meta_value' => $user->ID ) );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		if ( $terms ) {
			$term = reset( $terms );
			if ( get_term_meta( $term->term_id, '_post_collection_settings_migrated', true ) ) {
				return $term;
			}
			$id = (int) $term->term_id;
		} else {
			$slug = sanitize_title( $user->user_login );
			$base = $slug . '-legacy-' . $user->ID;
			$suffix = 0;
			while ( term_exists( $slug, Post_Collection::COLLECTION_TAXONOMY ) ) {
				$slug = $base . ( $suffix ? '-' . $suffix : '' );
				++$suffix;
			}
			$created = wp_insert_term( $user->display_name, Post_Collection::COLLECTION_TAXONOMY, array( 'slug' => $slug, 'description' => $user->description ) );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$id = (int) $created['term_id'];
			$saved = update_term_meta( $id, '_post_collection_legacy_user_id', (int) $user->ID );
			if ( is_wp_error( $saved ) || false === $saved ) {
				wp_delete_term( $id, Post_Collection::COLLECTION_TAXONOMY );
				return new \WP_Error( 'collection_mapping_failed', __( 'Could not record the legacy collection account.', 'post-collection' ) );
			}
		}
		foreach ( array( 'friends_publish_post_collection', 'friends_post_collection_inactive', 'friends_post_collection_copy_mode', 'post_collection_frontend_mode', 'post_collection_frontend_view', 'post_collection_hide_from_home' ) as $key ) {
			$value = get_user_option( $key, $user->ID );
			if ( 'friends_publish_post_collection' === $key ) {
				// Only documented legacy true values may publish a collection.
				$value = true === $value || 1 === $value || ( is_string( $value ) && in_array( strtolower( trim( $value ) ), array( '1', 'true' ), true ) );
			}
			if ( false !== $value || 'friends_publish_post_collection' === $key ) {
				$saved = update_term_meta( $id, $key, $value );
				if ( is_wp_error( $saved ) || ( false === $saved && get_term_meta( $id, $key, true ) != $value ) ) {
					return new \WP_Error( 'collection_settings_failed', __( 'Could not migrate collection settings.', 'post-collection' ) );
				}
			}
		}
		$saved = update_term_meta( $id, '_post_collection_settings_migrated', 1 );
		if ( is_wp_error( $saved ) || false === $saved ) {
			return new \WP_Error( 'collection_mapping_failed', __( 'Could not record the legacy collection account.', 'post-collection' ) );
		}
		return get_term( $id, Post_Collection::COLLECTION_TAXONOMY );
	}
}
