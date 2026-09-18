<?php
/**
 * Seed the wp-env development site with redirect edge cases.
 *
 * Creates a set of fixture posts to redirect to, then imports a deliberately
 * nasty CSV covering source-path shapes, destination shapes, broken targets,
 * loops and chains. A second pass feeds the validator inputs it should reject,
 * so you can see the guards fire.
 *
 * Idempotent: fixture post IDs are remembered in an option and the import runs
 * in upsert mode, so re-running updates rather than duplicates.
 *
 * Usage (from the plugin root, with wp-env running):
 *
 *     composer seed              # 25 bulk redirects on top of the edge cases
 *     composer seed -- 0         # edge cases only
 *     composer seed -- 500       # pagination-scale bulk rows
 *
 * Note: `wp legacy-redirector validate --check-urls` cannot reach the
 * site from inside the `cli` container, because home_url() points at the
 * host's published port. Every URL destination will report "Request failed".
 * Run validate without `--check-urls` to exercise the post-ID and path checks.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	die( "This script must be run through WP-CLI: wp eval-file bin/seed-dev-data.php\n" );
}

// Just enough to spill past one list-table page; pass a count for more.
$bulk_count = isset( $args[0] ) ? (int) $args[0] : 25;
$home       = untrailingslashit( home_url() );

// '' on a site at the domain root, '/subsite1' on a subdirectory subsite.
// Asked of the plugin rather than recomputed, so the seed and the code under
// test always agree on where home is.
$home_path = \Automattic\LegacyRedirector\Application\HomePath::current();

WP_CLI::log( sprintf( 'Seeding %s (home path: %s)', $home, '' === $home_path ? '(domain root)' : $home_path ) );

/*
 * ---------------------------------------------------------------------------
 * 1. Fixture posts to point redirects at.
 * ---------------------------------------------------------------------------
 */

$fixtures = array(
	'published' => array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'post_title'  => 'Seed Target: Published Post',
	),
	'page'      => array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Seed Target: Published Page',
	),
	'draft'     => array(
		'post_type'   => 'post',
		'post_status' => 'draft',
		'post_title'  => 'Seed Target: Draft',
	),
	'private'   => array(
		'post_type'   => 'post',
		'post_status' => 'private',
		'post_title'  => 'Seed Target: Private',
	),
	'pending'   => array(
		'post_type'   => 'post',
		'post_status' => 'pending',
		'post_title'  => 'Seed Target: Pending Review',
	),
	'future'    => array(
		'post_type'   => 'post',
		'post_status' => 'future',
		'post_title'  => 'Seed Target: Scheduled',
		'post_date'   => gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ),
	),
	'unicode'   => array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'post_title'  => 'Seed Target: Café 日本語 Ünïcøde',
	),
	'attach'    => array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_title'     => 'Seed Target: Attachment',
		'post_mime_type' => 'image/png',
	),
	'trashed'   => array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'post_title'  => 'Seed Target: Trashed',
	),
);

$ids = get_option( 'wpcom_legacy_redirector_seed_ids', array() );

foreach ( $fixtures as $key => $args_post ) {
	if ( isset( $ids[ $key ] ) && get_post( $ids[ $key ] ) ) {
		continue;
	}

	$new_id = wp_insert_post( $args_post + array( 'post_content' => 'Fixture created by bin/seed-dev-data.php' ), true );

	if ( is_wp_error( $new_id ) ) {
		WP_CLI::warning( sprintf( 'Could not create fixture "%s": %s', $key, $new_id->get_error_message() ) );
		continue;
	}

	$ids[ $key ] = $new_id;
}

// The trashed fixture must actually be in the trash.
if ( isset( $ids['trashed'] ) && 'trash' !== get_post_status( $ids['trashed'] ) ) {
	wp_trash_post( $ids['trashed'] );
}

update_option( 'wpcom_legacy_redirector_seed_ids', $ids, false );

$deleted_id = 999999; // An ID that has never existed.
$published  = (string) $ids['published'];
$page_id    = (string) $ids['page'];
$draft_id   = (string) $ids['draft'];
$private_id = (string) $ids['private'];
$pending_id = (string) $ids['pending'];
$future_id  = (string) $ids['future'];
$unicode_id = (string) $ids['unicode'];
$attach_id  = (string) $ids['attach'];
$trashed_id = (string) $ids['trashed'];
$long_path  = '/very-long/' . str_repeat( 'segment-', 40 ) . 'end';
$long_url   = 'https://example.com/?q=' . str_repeat( 'x', 900 );

/*
 * ---------------------------------------------------------------------------
 * 2. Redirects that should all be stored, validation bypassed so the
 *    deliberately broken ones survive to be found by `validate`.
 * ---------------------------------------------------------------------------
 */

$rows = array(
	// --- Source path shapes -------------------------------------------------
	array( '/simple', '/target' ),
	array( '/trailing-slash/', '/target' ),
	array( '/no-trailing-slash', '/target/' ),
	array( '/UPPER/Mixed/Case', '/target' ),
	array( '/upper/mixed/case', '/target' ),
	array( '/deep/a/b/c/d/e/f/g/h/i/j/k/l/m', '/target' ),
	array( '/legacy/article.html', $published ),
	array( '/legacy/index.php', '/target' ),
	array( '/legacy/story.aspx', '/target' ),
	array( '/with space', '/target' ),
	array( '/with%20encoded-space', '/target' ),
	array( '/with+plus', '/target' ),
	array( '/café-münchen', '/target' ),
	array( '/日本語/記事', '/target' ),
	array( '/فوتوغرافيا', '/target' ),
	array( '/Кириллица/статья', '/target' ),
	array( '/emoji-🎉-path', '/target' ),
	array( '/mixed-日本語-and-ascii', '/target' ),
	array( $long_path, '/target' ),
	array( '/.hidden', '/target' ),
	array( '/path.with.many.dots', '/target' ),
	array( '/~tilde-user', '/target' ),
	array( '/(parens)', '/target' ),
	array( '/[brackets]', '/target' ),
	array( '/path:with:colons', '/target' ),
	array( "/it's-an-apostrophe", '/target' ),
	array( '/amp&ersand', '/target' ),
	array( '/semi;colon', '/target' ),
	array( '/percent-100%25', '/target' ),
	array( '/double//slash', '/target' ),
	array( '/../dot-dot-traversal', '/target' ),
	array( '/trailing-dot.', '/target' ),
	array( '/UPPERCASE.HTML', '/target' ),

	// --- Query string shapes ------------------------------------------------
	array( '/query?a=1', '/target' ),
	array( '/query?a=1&b=2', '/target' ),
	array( '/query?b=2&a=1', '/target' ),
	array( '/query?a=', '/target' ),
	array( '/query?a', '/target' ),
	array( '/query?utm_source=newsletter&utm_medium=email&utm_campaign=spring', '/target' ),
	array( '/query?arr[]=1&arr[]=2', '/target' ),
	array( '/query?q=' . rawurlencode( '<script>alert(1)</script>' ), '/target' ),
	array( "/query?q=' OR 1=1--", '/target' ),
	array( '/query?q=日本語', '/target' ),
	array( '/trailing-question?', '/target' ),
	array( '/fragment#section-two', '/target' ),
	array( '/query?a=1#frag', '/target' ),

	// --- Full URLs as source (scheme and host are stripped) ------------------
	array( 'https://old.example.com/full-url-source', '/target' ),
	array( 'http://old.example.com:8080/port-in-source', '/target' ),
	array( 'https://old.example.com/', '/target' ),

	// --- Destination shapes -------------------------------------------------
	array( '/dest-relative', '/somewhere/else' ),
	array( '/dest-home', '/' ),
	array( '/dest-external-http', 'http://example.com/page' ),
	array( '/dest-external-https', 'https://example.com/page' ),
	array( '/dest-with-port', 'https://example.com:8443/page' ),
	array( '/dest-with-query', 'https://example.com/page?a=1&b=2' ),
	array( '/dest-with-fragment', 'https://example.com/page#anchor' ),
	array( '/dest-with-auth', 'https://user:pass@example.com/page' ),
	array( '/dest-idn', 'https://münchen.example.com/straße' ),
	array( '/dest-unicode-path', '/日本語/記事' ),
	array( '/dest-very-long-url', $long_url ),
	array( '/dest-post-id', $published ),
	array( '/dest-page-id', $page_id ),
	array( '/dest-attachment-id', $attach_id ),
	array( '/dest-unicode-post-id', $unicode_id ),
	array( '/dest-own-permalink', (string) wp_make_link_relative( (string) get_permalink( (int) $published ) ) ),

	// --- Status -------------------------------------------------------------
	array( '/status-enabled', '/target', 'enabled' ),
	array( '/status-disabled', '/target', 'disabled' ),
	array( '/status-disabled-external', 'https://example.com/gone', 'disabled' ),

	// --- Broken destinations, for `validate` to find ------------------------
	array( '/broken-draft', $draft_id ),
	array( '/broken-private', $private_id ),
	array( '/broken-pending', $pending_id ),
	array( '/broken-future', $future_id ),
	array( '/broken-trashed', $trashed_id ),
	array( '/broken-deleted', (string) $deleted_id ),
	array( '/broken-404-path', '/this-path-does-not-exist-anywhere' ),
	array( '/broken-unresolvable-host', 'https://no-such-host-exists.invalid/page' ),
	array( '/broken-404-external-url', 'https://example.com/definitely-not-here' ),

	// --- Internal absolute destinations, normalised to relative on save -----
	array( '/normalise-internal-absolute', $home . '/definitely-not-here' ),
	array( '/normalise-internal-with-query', $home . '/target?a=1' ),
	array( '/normalise-internal-root', $home . '/' ),
	array( '/normalise-internal-scheme-mismatch', str_replace( 'http://', 'https://', $home ) . '/target' ),

	// --- Loops and chains ---------------------------------------------------
	array( '/self-referential', '/self-referential' ),
	array( '/loop-a', '/loop-b' ),
	array( '/loop-b', '/loop-a' ),
	array( '/cycle-x', '/cycle-y' ),
	array( '/cycle-y', '/cycle-z' ),
	array( '/cycle-z', '/cycle-x' ),
	array( '/chain-1', '/chain-2' ),
	array( '/chain-2', '/chain-3' ),
	array( '/chain-3', '/chain-4' ),
	array( '/chain-4', $published ),
	array( '/loop-via-query', '/loop-via-query?utm_source=x' ),

	// --- Near-duplicates that hash differently ------------------------------
	array( '/Duplicate-Case', '/target-one' ),
	array( '/duplicate-case', '/target-two' ),
	array( '/duplicate-slash', '/target-one' ),
	array( '/duplicate-slash/', '/target-two' ),

	// --- Sources that shadow real content -----------------------------------
	array( (string) wp_make_link_relative( (string) get_permalink( (int) $page_id ) ), '/target' ),
	array( '/wp-admin/', '/target' ),
	array( '/wp-login.php', '/target' ),
	array( '/feed/', '/target' ),
	array( '/robots.txt', '/target' ),
);

/*
 * Sites whose home is not the domain root. Sources are stored home-relative,
 * so HomePath::make_relative() has to strip the prefix on the way in. Only
 * seeded where there is actually a prefix to strip - on a root site these
 * would be ordinary paths and would prove nothing.
 */
if ( '' !== $home_path ) {
	$slug    = ltrim( $home_path, '/' );
	$encoded = implode( '/', array_map( 'rawurlencode', explode( '/', $slug ) ) );
	// Parsed rather than str_replace( $home_path, '', $home ), because
	// home_url() can be percent-encoded while $home_path is decoded, in which
	// case the replacement silently matches nothing and leaves the prefix on.
	$home_parts = wp_parse_url( $home );
	$host       = $home_parts['scheme'] . '://' . $home_parts['host']
		. ( isset( $home_parts['port'] ) ? ':' . $home_parts['port'] : '' );
	$sibling    = $slug . '-extra';

	$subsite_rows = array(

		/*
		 * Full-URL sources are the only ones the home path is stripped from.
		 * SourceUrl::normalise() guards strip_home_path() on the URL having a
		 * host, because a bare request URI never carries the home path. So
		 * these must all land on the bare, home-relative path.
		 */
		array( $home . '/full-url-on-subsite', '/target' ),
		array( $home . '/full-url-with-query?a=1', '/target' ),

		// The subsite home page itself, given as a full URL. Stores as '/'.
		array( $home . '/', '/target' ),
		array( $home, '/target' ),

		// Percent-encoded home prefix in a full URL. make_relative() compares
		// segments decoded, so this has to strip exactly as the bare form does.
		// This is the case that a byte comparison would silently fail on for a
		// non-ASCII home path.
		array( $host . '/' . $encoded . '/encoded-prefix-source', '/target' ),

		// Boundary: a full URL whose first segment merely starts with the home
		// slug is not under home at all, so make_relative() returns null and
		// nothing may be stripped.
		array( $host . '/' . $sibling . '/not-under-home', '/target' ),
		array( $host . '/' . $slug . 'suffix/not-under-home', '/target' ),

		// The home slug repeated in a full URL: only the first is the prefix.
		array( $home . '/' . $slug . '/nested-same-name', '/target' ),

		/*
		 * Path-only sources are taken literally - they are already understood
		 * to be home-relative, so a leading segment that happens to match the
		 * home slug is content, not a prefix. Stored verbatim, and reachable
		 * at <home>/<slug>/... rather than <home>/...
		 */
		array( $home_path . '/looks-like-prefix-but-is-literal', '/target' ),
		array( '/' . $slug . '/' . $slug . '/doubly-literal', '/target' ),

		// Destinations pointing back at this subsite, which the normaliser
		// should rewrite to home-relative paths.
		array( '/subsite-dest-absolute', $home . '/target' ),
		array( '/subsite-dest-home-root', $home . '/' ),

		// A destination on a *different* site in the network stays absolute.
		array( '/subsite-dest-other-site', untrailingslashit( network_home_url() ) . '/target' ),
	);

	$rows = array_merge( $rows, $subsite_rows );
}

for ( $i = 1; $i <= $bulk_count; $i++ ) {
	$rows[] = array( sprintf( '/bulk/article-%04d', $i ), sprintf( '/archive/%04d', $i ) );
}

$csv = tempnam( sys_get_temp_dir(), 'seed' ) . '.csv';
$fh  = fopen( $csv, 'w' );
foreach ( $rows as $row ) {
	fputcsv( $fh, $row, ',', '"', '\\' );
}
fclose( $fh );

WP_CLI::log( sprintf( 'Importing %d redirects (validation skipped so broken fixtures survive)…', count( $rows ) ) );
WP_CLI::runcommand(
	sprintf( 'legacy-redirector import %s --mode=upsert --skip-validation --format=csv', escapeshellarg( $csv ) ),
	array(
		'return'     => false,
		'launch'     => true,
		'exit_error' => false,
	)
);
unlink( $csv );

/*
 * ---------------------------------------------------------------------------
 * 3. Rows the validator should reject. Imported with validation ON so you can
 *    see the guards fire; none of these should end up stored.
 * ---------------------------------------------------------------------------
 */

$rejects = array(
	array( '/reject-self', '/reject-self' ),
	array( '/reject-protocol-relative', '//evil.example.com/' ),
	array( '/reject-scheme-relative-malformed', 'https:/evil.example.com' ),
	array( '/reject-javascript', 'javascript:alert(1)' ),
	array( '/reject-data-uri', 'data:text/html,<script>alert(1)</script>' ),
	array( '/reject-ftp', 'ftp://example.com/file.zip' ),
	array( '/reject-mailto', 'mailto:someone@example.com' ),
	array( '/reject-no-scheme', 'evil.example.com/path' ),
	array( '/reject-empty-destination', '' ),
	array( '/reject-missing-post', (string) $deleted_id ),
	array( '/reject-draft-post', $draft_id ),
	array( '/simple', '/already-exists' ),
	array( '', '/no-source' ),
);

$csv = tempnam( sys_get_temp_dir(), 'seedreject' ) . '.csv';
$fh  = fopen( $csv, 'w' );
foreach ( $rejects as $row ) {
	fputcsv( $fh, $row, ',', '"', '\\' );
}
fclose( $fh );

WP_CLI::log( sprintf( "\nImporting %d rows that should all be rejected…", count( $rejects ) ) );
WP_CLI::runcommand(
	sprintf( 'legacy-redirector import %s --verbose --format=csv', escapeshellarg( $csv ) ),
	array(
		'return'     => false,
		'launch'     => true,
		'exit_error' => false,
	)
);
unlink( $csv );

/*
 * ---------------------------------------------------------------------------
 * 4. Malformed rows the public API cannot produce, written directly. These
 *    stand in for legacy data migrated from 1.x.
 * ---------------------------------------------------------------------------
 */

$malformed = array(
	// Neither a destination URL nor a destination post: EMPTY_DESTINATION.
	array(
		'title'   => '/legacy-empty-destination',
		'excerpt' => '',
		'parent'  => 0,
	),
	// Destination post that was hard-deleted: POST_DELETED.
	array(
		'title'   => '/legacy-orphaned-parent',
		'excerpt' => '',
		'parent'  => $deleted_id,
	),
	// Whitespace-only destination.
	array(
		'title'   => '/legacy-whitespace-destination',
		'excerpt' => '   ',
		'parent'  => 0,
	),
	// Both a URL and a post ID set: ambiguous.
	array(
		'title'   => '/legacy-both-destinations',
		'excerpt' => '/some-url',
		'parent'  => (int) $published,
	),
);

foreach ( $malformed as $row ) {
	$hash = md5( $row['title'] );

	$existing = get_posts(
		array(
			'post_type'        => 'vip-legacy-redirect',
			'name'             => $hash,
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);

	if ( ! empty( $existing ) ) {
		continue;
	}

	wp_insert_post(
		array(
			'post_type'    => 'vip-legacy-redirect',
			'post_status'  => 'publish',
			'post_name'    => $hash,
			'post_title'   => $row['title'],
			'post_excerpt' => $row['excerpt'],
			'post_parent'  => (int) $row['parent'],
		)
	);
}

WP_CLI::log( sprintf( "\nWrote %d malformed legacy rows directly.", count( $malformed ) ) );

/*
 * ---------------------------------------------------------------------------
 * 5. Summary.
 * ---------------------------------------------------------------------------
 */

global $wpdb;

$counts = (array) wp_count_posts( 'vip-legacy-redirect' );

WP_CLI::log( "\nRedirect counts by status:" );
foreach ( $counts as $count_status => $count ) {
	if ( (int) $count > 0 ) {
		WP_CLI::log( sprintf( '  %-10s %d', $count_status, $count ) );
	}
}

// Duplicate source hashes should never happen, but `import --mode=upsert` cannot
// update a disabled redirect (find_by_source() is publish-only), so re-running
// the seed with --skip-validation will duplicate the disabled rows.
$duplicates = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT post_title, COUNT(*) AS total FROM {$wpdb->posts}
		 WHERE post_type = %s AND post_status IN ( 'publish', 'draft' )
		 GROUP BY post_name, post_title HAVING total > 1",
		'vip-legacy-redirect'
	)
);

if ( ! empty( $duplicates ) ) {
	WP_CLI::warning( sprintf( '%d source path(s) have more than one redirect:', count( $duplicates ) ) );
	foreach ( $duplicates as $duplicate ) {
		WP_CLI::log( sprintf( '  %s (%d rows)', $duplicate->post_title, $duplicate->total ) );
	}
}

WP_CLI::log( "\nFixture post IDs:" );
foreach ( $ids as $key => $fixture_id ) {
	$fixture_status = get_post_status( $fixture_id );
	WP_CLI::log( sprintf( '  %-10s %d (%s)', $key, $fixture_id, false === $fixture_status ? 'missing' : $fixture_status ) );
}

WP_CLI::success( 'Seeding complete. Try: wp legacy-redirector validate --format=table' );
