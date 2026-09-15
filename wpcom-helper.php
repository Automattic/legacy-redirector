<?php
/**
 * Amendments for when this plugin runs on WordPress.com.
 *
 * @package Automattic\LegacyRedirector
 */

// Do not allow inserts to be opted into from unauthenticated contexts on
// WordPress.com. Users with the manage_redirects capability pass the
// creation gate before this filter is consulted, so WP-CLI, the admin,
// and capability-checked REST/Abilities requests are unaffected.
add_filter( 'wpcom_legacy_redirector_allow_insert', '__return_false', 9999 );
