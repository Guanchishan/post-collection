<?php
/** Bootstrap a disposable WordPress installation, never an existing site. */
$root = getenv( 'PC_TEST_WP_ROOT' );
if ( ! $root || '1' !== getenv( 'PC_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Set PC_TEST_DISPOSABLE=1 and PC_TEST_WP_ROOT to a disposable WordPress directory/database only.' );
}
require_once __DIR__ . '/../vendor/autoload.php';
define( 'ABSPATH', rtrim( $root, '/' ) . '/' );
define( 'DB_NAME', 'post_collection_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'disposable-test-only' );
define( 'DB_HOST', getenv( 'PC_TEST_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_INSTALLING', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_USE_THEMES', false );
if ( '1' === getenv( 'PC_TEST_MULTISITE' ) ) {
	define( 'MULTISITE', true );
	define( 'SUBDOMAIN_INSTALL', false );
	define( 'DOMAIN_CURRENT_SITE', 'post-collection.test' );
	define( 'PATH_CURRENT_SITE', '/' );
	define( 'SITE_ID_CURRENT_SITE', 1 );
	define( 'BLOG_ID_CURRENT_SITE', 1 );
}
$table_prefix = 'pctest_';
$_SERVER['HTTP_HOST'] = 'post-collection.test';
$_SERVER['SERVER_NAME'] = 'post-collection.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', function () { return new WP_Error( 'test_network_disabled' ); } );
if ( ! is_blog_installed() ) {
	wp_install( 'Post Collection disposable tests', 'test-admin', 'admin@example.test', false, '', 'disposable-test-only' );
}
wp_set_current_user( 1 );

// Optional: exercise the installed Friends source too. It must use this test DB.
if ( getenv( 'PC_TEST_FRIENDS_PLUGIN' ) ) {
	require getenv( 'PC_TEST_FRIENDS_PLUGIN' );
} else {
	// The transfer needs only a presence check; taxonomies below model its storage.
	eval( 'namespace Friends; class Friends {}' );
}
require_once __DIR__ . '/../class-user.php';
require_once __DIR__ . '/../class-user-query.php';
require_once __DIR__ . '/../includes/class-article-notes.php';
require_once __DIR__ . '/../class-post-collection.php';
require_once __DIR__ . '/../class-post-collection-app.php';
add_role( 'post_collection', 'Post Collection', array( 'post_collection' => true ) );
register_post_type( 'friend_post_cache', array( 'public' => false, 'supports' => array( 'title', 'editor', 'revisions' ) ) );
register_post_type( PostCollection\Post_Collection::CPT, array( 'public' => false, 'supports' => array( 'title', 'editor', 'revisions' ) ) );
register_taxonomy( PostCollection\Post_Collection::COLLECTION_TAXONOMY, array( PostCollection\Post_Collection::CPT ), array( 'public' => false ) );
foreach ( array( 'friend-subscription', 'friends-virtual-user', 'friend_tag' ) as $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy( $taxonomy, array( 'friend_post_cache', PostCollection\Post_Collection::CPT ), array( 'public' => false ) );
	}
}
register_taxonomy_for_object_type( 'post_format', PostCollection\Post_Collection::CPT );
register_taxonomy_for_object_type( 'post_tag', PostCollection\Post_Collection::CPT );
