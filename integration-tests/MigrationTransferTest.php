<?php
use PHPUnit\Framework\TestCase;
use PostCollection\Collection_Migration as Migration;
use PostCollection\Collection_Transfer as Transfer;
use PostCollection\Post_Collection as Plugin;

class MigrationTransferTest extends TestCase {
	protected function setUp(): void {
		global $wpdb;
		wp_set_current_user( 1 );
		foreach ( array( Migration::OPTION, Migration::STATE, 'post_collection_migration_lock', 'post_collection_migration_error', 'post_collection_transfer_lock' ) as $option ) {
			delete_option( $option );
		}
		// Only the hardcoded disposable database is ever cleared by this suite.
		if ( 'post_collection_test' !== DB_NAME || 'pctest_' !== $wpdb->prefix ) {
			throw new RuntimeException( 'Refusing to reset a non-test database.' );
		}
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( get_terms( array( 'taxonomy' => Plugin::COLLECTION_TAXONOMY, 'hide_empty' => false ) ) as $term ) {
			wp_delete_term( $term->term_id, $term->taxonomy );
		}
		foreach ( get_users( array( 'role' => 'post_collection' ) ) as $user ) {
			$user->remove_role( 'post_collection' );
		}
	}

	private function owner( $login = 'legacy-collection' ) {
		$id = username_exists( $login );
		if ( ! $id ) {
			$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'test-only', 'display_name' => 'Legacy Collection', 'description' => 'A private archive', 'role' => 'post_collection' ) );
		} else {
			( new WP_User( $id ) )->add_role( 'post_collection' );
		}
		return new WP_User( $id );
	}

	private function article( $type = 'friend_post_cache', $author = 0, $status = 'publish' ) {
		return wp_insert_post( wp_slash( array( 'post_type' => $type, 'post_author' => $author, 'post_status' => $status, 'post_title' => 'An author\'s article', 'post_content' => '<p>Backslash \\ and "quotes".</p>', 'guid' => 'https://example.test/source/' . wp_generate_uuid4(), 'post_date' => '2024-06-12 10:11:12', 'post_date_gmt' => '2024-06-12 10:11:12' ) ), true );
	}

	private function collection( $slug = 'target' ) {
		$t = wp_insert_term( 'Collection ' . $slug, Plugin::COLLECTION_TAXONOMY, array( 'slug' => $slug ) );
		return get_term( $t['term_id'], Plugin::COLLECTION_TAXONOMY );
	}

	public function test_both_legacy_types_migrate_in_batches_without_changing_article_data() {
		global $wpdb;
		$owner = $this->owner();
		update_user_option( $owner->ID, 'friends_post_collection_copy_mode', true );
		update_user_option( $owner->ID, 'friends_publish_post_collection', false );
		$ids = array( $this->article( 'friend_post_cache', $owner->ID ), $this->article( 'post_collection', $owner->ID, 'trash' ) );
		$unrelated = $this->article( 'friend_post_cache', 1 );
		$normal = $this->article( 'post', $owner->ID );
		$old_count = (int) wp_count_posts( 'friend_post_cache' )->publish;
		$new_count = (int) wp_count_posts( Plugin::CPT )->publish;
		$snapshots = array();
		foreach ( $ids as $id ) {
			add_post_meta( $id, 'source', array( 'author' => 'Original writer', 'html' => '<p>Raw</p>' ) );
			wp_set_object_terms( $id, 'source-writer', 'friends-virtual-user' );
			wp_set_object_terms( $id, 'important', 'friend_tag' );
			wp_set_object_terms( $id, 'old-post-tag', 'post_tag' );
			wp_insert_comment( array( 'comment_post_ID' => $id, 'comment_content' => 'Keep this comment', 'comment_approved' => 1 ) );
			$revision = _wp_put_post_revision( $id );
			$snapshots[ $id ] = array( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A ), get_post_meta( $id, 'source', true ), $revision );
		}
		$this->assertFalse( Migration::run( 1 ) );
		$this->assertNotSame( Migration::VERSION, (int) get_option( Migration::OPTION ) );
		$this->assertFalse( Migration::run( 1 ) );
		$this->assertTrue( Migration::run( 1 ) );
		$term = Migration::collection_for_user( $owner );
		$this->assertFalse( (bool) get_term_meta( $term->term_id, 'friends_publish_post_collection', true ) );
		$this->assertTrue( (bool) get_term_meta( $term->term_id, 'friends_post_collection_copy_mode', true ) );
		foreach ( $ids as $id ) {
			$expected = $snapshots[ $id ][0];
			$expected['post_type'] = Plugin::CPT;
			$this->assertSame( $expected, $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A ) );
			$this->assertSame( $snapshots[ $id ][1], get_post_meta( $id, 'source', true ) );
			$this->assertSame( $id, (int) get_post( $snapshots[ $id ][2] )->post_parent );
			$this->assertCount( 1, get_comments( array( 'post_id' => $id ) ) );
			$this->assertTrue( has_term( $term->term_id, Plugin::COLLECTION_TAXONOMY, $id ) );
			$this->assertTrue( has_term( 'source-writer', 'friends-virtual-user', $id ) );
			$this->assertTrue( has_term( 'important', 'friend_tag', $id ) );
			$this->assertTrue( has_term( 'old-post-tag', 'friend_tag', $id ) );
			$this->assertTrue( has_term( 'old-post-tag', 'post_tag', $id ) );
		}
		$this->assertSame( 'friend_post_cache', get_post_type( $unrelated ) );
		$this->assertSame( 'post', get_post_type( $normal ) );
		$this->assertSame( $old_count - 1, (int) wp_count_posts( 'friend_post_cache' )->publish );
		$this->assertSame( $new_count + 1, (int) wp_count_posts( Plugin::CPT )->publish );
		$this->assertTrue( Migration::run() );
		$this->assertCount( 1, get_terms( array( 'taxonomy' => Plugin::COLLECTION_TAXONOMY, 'hide_empty' => false ) ) );
	}

	public function test_same_slug_existing_term_is_not_reused_or_made_private() {
		$owner = $this->owner();
		$existing = $this->collection( $owner->user_login );
		update_term_meta( $existing->term_id, 'friends_publish_post_collection', true );
		$mapped = Migration::collection_for_user( $owner );
		$this->assertNotSame( $existing->term_id, $mapped->term_id );
		$this->assertTrue( (bool) get_term_meta( $existing->term_id, 'friends_publish_post_collection', true ) );
		$this->assertFalse( (bool) get_term_meta( $mapped->term_id, 'friends_publish_post_collection', true ) );
		$this->assertSame( $mapped->term_id, Migration::collection_for_user( $owner )->term_id );
	}

	public function test_default_account_recovers_from_legacy_wp_error_without_creating_duplicate_user() {
		$owner = $this->owner();
		update_option( 'friends-post-collection_default_user', new WP_Error( 'existing_user_login' ) );
		update_option( 'post-collection_default_user', new WP_Error( 'existing_user_login' ) );
		$method = new ReflectionMethod( Plugin::class, 'setup_default_user' );
		$method->setAccessible( true );
		$before = count( get_users() );
		$method->invoke( null );
		$this->assertSame( (int) $owner->ID, (int) get_option( 'post-collection_default_user' ) );
		$this->assertCount( $before, get_users() );
	}

	public function test_move_and_undo_preserve_subscription_author_zero_and_all_related_data() {
		$id = $this->article();
		wp_set_object_terms( $id, 'original-subscription', 'friend-subscription' );
		wp_set_object_terms( $id, 'original-author', 'friends-virtual-user' );
		add_post_meta( $id, 'author', 'A writer' );
		$source = class_exists( 'Friends\Subscription' ) ? Friends\User::get_post_author( get_post( $id ) )->get_term_id() : null;
		$before = get_post( $id, ARRAY_A );
		$term = $this->collection();
		$result = Transfer::save( $id, $term );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( $id, $result['post_id'] );
		$this->assertTrue( $result['moved'] );
		$this->assertSame( 'private', get_post_status( $id ) );
		$this->assertSame( Plugin::CPT, get_post_type( $id ) );
		$this->assertSame( 0, (int) get_post( $id )->post_author );
		$this->assertTrue( has_term( 'original-subscription', 'friend-subscription', $id ) );
		$this->assertSame( $id, Transfer::restore( $id ) );
		$this->assertSame( $before, get_post( $id, ARRAY_A ) );
		$this->assertFalse( has_term( $term->term_id, Plugin::COLLECTION_TAXONOMY, $id ) );
		$this->assertTrue( has_term( 'original-author', 'friends-virtual-user', $id ) );
		$this->assertSame( 'A writer', get_post_meta( $id, 'author', true ) );
		if ( null !== $source ) {
			$this->assertSame( $source, Friends\User::get_post_author( get_post( $id ) )->get_term_id() );
		}
		$this->assertInstanceOf( WP_Error::class, Transfer::restore( $id ) );
	}

	public function test_copy_preserves_source_metadata_terms_revisions_and_deduplicates_without_consuming_source() {
		$id = $this->article( 'friend_post_cache', 1 );
		add_post_meta( $id, 'source', wp_slash( array( 'author' => 'A writer', 'path' => 'C:\\original' ) ) );
		wp_set_object_terms( $id, 'writer', 'friends-virtual-user' );
		$revision = _wp_put_post_revision( $id );
		$before = get_post( $id, ARRAY_A );
		$term = $this->collection();
		$result = Transfer::save( $id, $term, true );
		$this->assertFalse( is_wp_error( $result ) );
		$copy = $result['post_id'];
		$this->assertNotSame( $id, $copy );
		$this->assertSame( $before, get_post( $id, ARRAY_A ) );
		$this->assertSame( get_post( $id )->post_content, get_post( $copy )->post_content );
		$this->assertSame( get_post_meta( $id, 'source', true ), get_post_meta( $copy, 'source', true ) );
		$this->assertTrue( has_term( 'writer', 'friends-virtual-user', $copy ) );
		$revisions = wp_get_post_revisions( $copy );
		$this->assertNotEmpty( $revisions );
		$this->assertContains( get_post( $revision )->post_content, wp_list_pluck( $revisions, 'post_content' ) );
		$again = Transfer::save( $id, $term );
		$this->assertTrue( $again['duplicate'] );
		$this->assertFalse( $again['moved'] );
		$this->assertSame( $copy, $again['post_id'] );
		$this->assertSame( $before, get_post( $id, ARRAY_A ) );
	}

	public function test_failed_copy_leaves_source_and_does_not_report_success() {
		$id = $this->article( 'friend_post_cache', 1 );
		$before = get_post( $id, ARRAY_A );
		$fail = function () { return true; };
		add_filter( 'wp_insert_post_empty_content', $fail );
		try {
			$result = Transfer::save( $id, $this->collection(), true );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $fail );
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $before, get_post( $id, ARRAY_A ) );
	}

	public function test_inactive_collection_and_ordinary_posts_are_rejected() {
		$term = $this->collection();
		update_term_meta( $term->term_id, 'friends_post_collection_inactive', true );
		$this->assertInstanceOf( WP_Error::class, Transfer::save( $this->article(), $term ) );
		$this->assertInstanceOf( WP_Error::class, Transfer::save( $this->article( 'post', 1 ), $term ) );
	}

	public function test_migration_write_failure_is_retryable_and_does_not_mark_complete() {
		global $wpdb;
		$owner = $this->owner();
		$id = $this->article( 'friend_post_cache', $owner->ID );
		$fail = function ( $query ) use ( $wpdb ) {
			if ( false !== strpos( $query, "UPDATE `{$wpdb->posts}` SET `post_type`" ) ) {
				return 'UPDATE deliberately_missing_test_table SET post_type = 1';
			}
			return $query;
		};
		$old = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$result = Migration::run();
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $old );
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'friend_post_cache', get_post_type( $id ) );
		$this->assertFalse( get_option( Migration::OPTION ) );
		$this->assertTrue( Migration::run() );
		$this->assertSame( Plugin::CPT, get_post_type( $id ) );
		$this->assertCount( 1, get_terms( array( 'taxonomy' => Plugin::COLLECTION_TAXONOMY, 'hide_empty' => false ) ) );
	}

	public function test_partial_collection_settings_retry_reuses_term_and_preserves_privacy() {
		$owner = $this->owner();
		update_user_option( $owner->ID, 'friends_post_collection_copy_mode', true );
		$fail = function ( $check, $id, $key ) {
			return 'friends_post_collection_copy_mode' === $key ? false : $check;
		};
		add_filter( 'update_term_metadata', $fail, 10, 3 );
		try {
			$this->assertInstanceOf( WP_Error::class, Migration::collection_for_user( $owner ) );
		} finally {
			remove_filter( 'update_term_metadata', $fail );
		}
		$term = Migration::collection_for_user( $owner );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertFalse( (bool) get_term_meta( $term->term_id, 'friends_publish_post_collection', true ) );
		$this->assertCount( 1, get_terms( array( 'taxonomy' => Plugin::COLLECTION_TAXONOMY, 'hide_empty' => false ) ) );
	}

	public function test_two_collection_moves_can_be_undone_in_reverse_order() {
		$id = $this->article( 'friend_post_cache', 1 );
		$original = get_post( $id, ARRAY_A );
		$a = $this->collection( 'a' );
		$b = $this->collection( 'b' );
		Transfer::save( $id, $a );
		Transfer::save( $id, $b );
		$this->assertSame( $id, Transfer::restore( $id ) );
		$this->assertTrue( has_term( $a->term_id, Plugin::COLLECTION_TAXONOMY, $id ) );
		$this->assertSame( 'private', get_post_status( $id ) );
		$this->assertSame( $id, Transfer::restore( $id ) );
		$this->assertSame( $original, get_post( $id, ARRAY_A ) );
	}

	public function test_failed_move_rolls_back_terms_and_leaves_original_state() {
		global $wpdb;
		$id = $this->article( 'friend_post_cache', 1 );
		$original = get_post( $id, ARRAY_A );
		$term = $this->collection();
		$fail = function ( $query ) use ( $wpdb ) {
			return false !== strpos( $query, "UPDATE `{$wpdb->posts}` SET `post_type`" ) ? 'UPDATE deliberately_missing_test_table SET post_type = 1' : $query;
		};
		$old = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$this->assertInstanceOf( WP_Error::class, Transfer::save( $id, $term ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $old );
		}
		$this->assertSame( $original, get_post( $id, ARRAY_A ) );
		$this->assertFalse( has_term( $term->term_id, Plugin::COLLECTION_TAXONOMY, $id ) );
		$this->assertEmpty( get_post_meta( $id, Transfer::HISTORY, true ) );
	}

	public function test_a_busy_transfer_returns_error_and_does_not_touch_source() {
		$id = $this->article( 'friend_post_cache', 1 );
		$original = get_post( $id, ARRAY_A );
		add_option( 'post_collection_transfer_lock', time() );
		$this->assertInstanceOf( WP_Error::class, Transfer::save( $id, $this->collection() ) );
		$this->assertSame( $original, get_post( $id, ARRAY_A ) );
	}

	public function test_private_collection_stays_invisible_to_anonymous_visitors() {
		$owner = $this->owner();
		$id = $this->article( 'post_collection', $owner->ID );
		$this->assertTrue( Migration::run() );
		$term = Migration::collection_for_user( $owner );
		$reflection = new ReflectionClass( PostCollection\Post_Collection_App::class );
		$app = $reflection->newInstanceWithoutConstructor();
		$property = $reflection->getProperty( 'post_collection' );
		$property->setAccessible( true );
		$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$property->setValue( $app, $plugin );
		wp_set_current_user( 0 );
		$this->assertFalse( $app->can_view_collection( $term ) );
		$this->assertNull( $app->get_visible_post( $id, $term ) );
	}

	public function test_multisite_only_migrates_collection_members_of_current_blog() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Run again with PC_TEST_MULTISITE=1 after install-network.php.' );
		}
		$owner = $this->owner();
		$id = $this->article( 'friend_post_cache', $owner->ID );
		$site = wpmu_create_blog( 'post-collection.test', '/migration-test-' . wp_generate_uuid4() . '/', 'Other site', 1 );
		$this->assertIsInt( $site );
		switch_to_blog( $site );
		try {
			// The account exists globally, but is not a collection member here.
			$other = $this->article( 'friend_post_cache', $owner->ID );
			$this->assertTrue( Migration::run() );
			$this->assertSame( 'friend_post_cache', get_post_type( $other ) );
			$this->assertSame( Migration::VERSION, (int) get_option( Migration::OPTION ) );
		} finally {
			restore_current_blog();
		}
		$this->assertFalse( get_option( Migration::OPTION ) );
		$this->assertSame( 'friend_post_cache', get_post_type( $id ) );
		$this->assertTrue( Migration::run() );
		$this->assertSame( Plugin::CPT, get_post_type( $id ) );
		switch_to_blog( $site );
		try {
			$this->assertSame( 'friend_post_cache', get_post_type( $other ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_ajax_rejects_missing_capability_and_bad_nonce_without_changing_posts() {
		$id = $this->article( 'friend_post_cache', 1 );
		$term = $this->collection();
		$before = get_post( $id, ARRAY_A );
		$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$die = function () { throw new RuntimeException( 'test-ajax-ended' ); };
		$handler = function () use ( $die ) { return $die; };
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $handler );
		try {
			foreach ( array( 0, 1 ) as $user ) {
				wp_set_current_user( $user );
				$_POST = array( 'id' => $id, 'collection' => $term->term_id, '_ajax_nonce' => $user ? 'invalid-nonce' : wp_create_nonce( Plugin::AJAX_NONCE ) );
				$_REQUEST = $_POST;
				ob_start();
				try {
					$plugin->wp_ajax_save_to_collection();
					$this->fail( 'Rejected AJAX requests must terminate.' );
				} catch ( RuntimeException $e ) {
					$this->assertSame( 'test-ajax-ended', $e->getMessage() );
				} finally {
					$output = ob_get_clean();
				}
				if ( ! $user ) {
					$this->assertFalse( json_decode( $output, true )['success'] );
				}
				$this->assertSame( $before, get_post( $id, ARRAY_A ) );
			}
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $handler );
			$_POST = $_REQUEST = array();
			wp_set_current_user( 1 );
		}
	}

	public function test_insert_as_collection_restores_current_user_even_when_a_hook_throws() {
		$owner = $this->owner();
		$user = new PostCollection\User( $owner->ID );
		$throw = function () { throw new RuntimeException( 'test-insert-failure' ); };
		add_filter( 'wp_insert_post_data', $throw );
		try {
			$user->insert_post( array( 'post_type' => Plugin::CPT, 'post_title' => 'Test', 'post_status' => 'private' ), true );
			$this->fail( 'The controlled hook must throw.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'test-insert-failure', $e->getMessage() );
		} finally {
			remove_filter( 'wp_insert_post_data', $throw );
		}
		$this->assertSame( 1, get_current_user_id() );
		$id = $user->insert_post( array( 'post_type' => Plugin::CPT, 'post_title' => 'Test', 'post_status' => 'private', 'post_author' => 1 ), true );
		$this->assertSame( (int) $owner->ID, (int) get_post( $id )->post_author );
		$this->assertSame( 1, get_current_user_id() );
	}
}
