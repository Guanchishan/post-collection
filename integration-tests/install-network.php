<?php
/** Convert only the disposable integration-test installation to multisite. */
require __DIR__ . '/bootstrap.php';
global $wpdb;
foreach ( $wpdb->tables( 'ms_global' ) as $table => $name ) {
	$wpdb->$table = $name;
}
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->site ) ) && $wpdb->get_var( "SELECT id FROM {$wpdb->site} WHERE id = 1" ) ) {
	echo "Disposable multisite network already installed.\n";
	return;
}
install_network();
$result = populate_network( 1, 'post-collection.test', 'admin@example.test', 'Disposable network', '/', false );
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}
echo "Disposable multisite network installed.\n";
