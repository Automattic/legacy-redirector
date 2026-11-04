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
//
// Deliberately not also hooked to the deprecated
// wpcom_legacy_redirector_allow_insert name. It would be redundant, because
// RedirectManager feeds the deprecated filter's result into this one, so this
// callback still has the last word over code using the old name. It would also
// be harmful: apply_filters_deprecated() only warns when something is attached,
// so attaching here would emit a deprecation notice on every WordPress.com
// request, whether or not any customer code uses the old hook.
add_filter( 'legacy_redirector_allow_insert', '__return_false', 9999 );
