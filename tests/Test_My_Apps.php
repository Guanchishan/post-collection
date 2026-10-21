<?php

use PHPUnit\Framework\TestCase;
use PostCollection\Post_Collection;

require_once __DIR__ . '/../class-post-collection.php';

class Post_Collection_Launcher_Test_Plugin extends Post_Collection {
	public function __construct() {}

	public function get_required_role() {
		return 'edit_private_posts';
	}

	public function get_post_collection_users() {
		throw new LogicException( 'Launcher entries must not use legacy collection users.' );
	}
}

class Test_My_Apps extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wp_test_current_user_caps'] = array();
		$GLOBALS['wp_test_registered_terms'] = array();
		$GLOBALS['wp_test_options'] = array();
	}

	public function test_launcher_uses_terms_and_preserves_main_app() {
		$GLOBALS['wp_test_options']['post_collection_my_apps_collections'] = true;
		$GLOBALS['wp_test_current_user_caps']['edit_private_posts'] = true;
		$created = wp_insert_term( 'Research', Post_Collection::COLLECTION_TAXONOMY, array( 'slug' => 'research' ) );
		$main = array( 'name' => 'Post Collection', 'url' => home_url( '/post-collection/' ) );
		$plugin = new Post_Collection_Launcher_Test_Plugin();
		$apps = $plugin->my_apps_plugins( array( 'post-collection' => $main ) );

		$this->assertCount( 2, $apps );
		$this->assertSame( $main, $apps['post-collection'] );
		$this->assertSame( 'Research', $apps[ 'post-collection-term-' . $created['term_id'] ]['name'] );
		$this->assertSame( home_url( '/post-collection/research/' ), $apps[ 'post-collection-term-' . $created['term_id'] ]['url'] );

		wp_delete_term( $created['term_id'], Post_Collection::COLLECTION_TAXONOMY );
		$this->assertSame( array( 'post-collection' => $main ), $plugin->my_apps_plugins( array( 'post-collection' => $main ) ) );
	}

	public function test_collection_shortcuts_are_disabled_by_default() {
		$GLOBALS['wp_test_current_user_caps']['edit_private_posts'] = true;
		wp_insert_term( 'Research', Post_Collection::COLLECTION_TAXONOMY );
		$main = array( 'post-collection' => array( 'url' => home_url( '/post-collection/' ) ) );
		$plugin = new Post_Collection_Launcher_Test_Plugin();
		$this->assertSame( $main, $plugin->my_apps_plugins( $main ) );
		$GLOBALS['wp_test_options']['post_collection_my_apps_collections'] = false;
		$this->assertSame( $main, $plugin->my_apps_plugins( $main ) );
	}

	public function test_collection_shortcuts_require_access() {
		$GLOBALS['wp_test_options']['post_collection_my_apps_collections'] = true;
		wp_insert_term( 'Research', Post_Collection::COLLECTION_TAXONOMY );
		$plugin = new Post_Collection_Launcher_Test_Plugin();
		$this->assertSame( array(), $plugin->my_apps_plugins( array() ) );
	}
}
