<?php
/**
 * Plugin Name: Legacy Redirector – DataForm prototype
 * Description: Throwaway prototype of the Add/Edit Redirect screen built with DataForm, alongside the existing screen for comparison.
 * Version:     0.1.0
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\DataFormPrototype;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\DI\Container;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

const ADD_SLUG  = 'add-redirect-dataform';
const EDIT_SLUG = 'edit-redirect-dataform';

// Plugins load alphabetically, so wait for Legacy Redirector's autoloader.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( Container::class ) ) {
			return;
		}

		add_action( 'admin_menu', __NAMESPACE__ . '\\register_pages' );
		add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue' );
		add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
		add_filter( 'post_row_actions', __NAMESPACE__ . '\\row_action', 20, 2 );
	}
);

function register_pages(): void {
	$parent = 'edit.php?post_type=' . PostType::POST_TYPE;

	add_submenu_page( $parent, 'Add Redirect (DataForm)', 'Add Redirect (DataForm)', Capability::MANAGE_REDIRECTS_CAPABILITY, ADD_SLUG, __NAMESPACE__ . '\\render' );
	$hook = add_submenu_page( '', 'Edit Redirect (DataForm)', 'Edit Redirect (DataForm)', Capability::MANAGE_REDIRECTS_CAPABILITY, EDIT_SLUG, __NAMESPACE__ . '\\render' );

	// Hidden pages get no admin title, which admin-header.php then passes to strip_tags() as null.
	add_action(
		'load-' . $hook,
		static function (): void {
			$GLOBALS['title'] = 'Edit Redirect (DataForm)'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	);
}

function render(): void {
	if ( ! file_exists( __DIR__ . '/build/index.asset.php' ) ) {
		echo '<div class="wrap"><p>Run <code>npm install && npm run build</code> in prototypes/dataform-redirect-form first.</p></div>';
		return;
	}

	echo '<div class="wrap"><div id="legacy-redirector-dataform"></div></div>';
}

/**
 * Add an "Edit (DataForm)" link next to the existing Edit link, so both screens are one click apart.
 */
function row_action( array $actions, \WP_Post $post ): array {
	if ( PostType::POST_TYPE === $post->post_type ) {
		$actions['edit_dataform'] = sprintf( '<a href="%s">Edit (DataForm)</a>', esc_url( edit_url( $post->ID ) ) );
	}
	return $actions;
}

function edit_url( int $id ): string {
	return admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=' . EDIT_SLUG . '&redirect_id=' . $id );
}

function enqueue( string $hook_suffix ): void {
	$pages = array( PostType::POST_TYPE . '_page_' . ADD_SLUG, PostType::POST_TYPE . '_page_' . EDIT_SLUG );
	if ( ! in_array( $hook_suffix, $pages, true ) || ! file_exists( __DIR__ . '/build/index.asset.php' ) ) {
		return;
	}

	$asset = require __DIR__ . '/build/index.asset.php';

	wp_enqueue_script( 'legacy-redirector-dataform', plugins_url( 'build/index.js', __FILE__ ), $asset['dependencies'], $asset['version'], true );
	wp_enqueue_style( 'legacy-redirector-dataform', plugins_url( 'build/index.css', __FILE__ ), array(), $asset['version'] );

	wp_add_inline_script(
		'legacy-redirector-dataform',
		'window.legacyRedirectorDataForm = ' . wp_json_encode( page_data() ) . ';',
		'before'
	);
}

/**
 * Everything the form needs on first paint, so there is no loading state.
 */
function page_data(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only lookup for display.
	$id = isset( $_GET['redirect_id'] ) ? absint( $_GET['redirect_id'] ) : 0;

	$data = array(
		'homePrefix' => trailingslashit( home_url() ),
		'listUrl'    => admin_url( 'edit.php?post_type=' . PostType::POST_TYPE ),
		'classicUrl' => $id
			? admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $id )
			: admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=add-redirect' ),
		'editUrl'    => edit_url( 0 ),
		'redirect'   => null,
	);

	$redirect = $id ? Container::instance()->repository()->find_by_id( $id ) : null;
	if ( null === $redirect ) {
		return $data;
	}

	$data['redirect'] = to_item( $redirect );

	return $data;
}

/**
 * Shape a redirect as the flat item DataForm edits.
 */
function to_item( Redirect $redirect ): array {
	$destination = $redirect->destination();
	$label       = null;

	if ( $destination->is_post_id() ) {
		$value = (string) $destination->as_post_id()->value();
		$post  = get_post( (int) $value );
		$label = $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES ) . ' (ID: ' . $value . ')' : null;
	} else {
		$value = $destination->as_url()->value();
	}

	return array(
		'id'               => (int) $redirect->id(),
		'source'           => ltrim( $redirect->source()->path(), '/' ),
		'destination'      => $value,
		'destinationLabel' => $label,
		'status'           => $redirect->status(),
		'reserved'         => in_array( AuditFindingType::RESERVED_SOURCE, Container::instance()->auditor()->source_warnings( $redirect->source() ), true ),
		'testUrl'          => home_url( $redirect->source()->path() ),
	);
}

function register_routes(): void {
	$args = array(
		'methods'             => \WP_REST_Server::CREATABLE,
		'callback'            => __NAMESPACE__ . '\\save',
		'permission_callback' => static fn(): bool => current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ),
		'args'                => array(
			'source'      => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'destination' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'      => array(
				'type' => 'string',
				'enum' => array( 'publish', 'draft' ),
			),
		),
	);

	register_rest_route( 'legacy-redirector-prototype/v1', '/redirects', $args );
	register_rest_route( 'legacy-redirector-prototype/v1', '/redirects/(?P<id>\d+)', $args );
}

/**
 * The same rule sequence as RedirectFormPage::handle_save(), answering in JSON instead of redirecting.
 */
function save( \WP_REST_Request $request ): array|\WP_Error {
	$container = Container::instance();
	$id        = (int) $request->get_param( 'id' );
	$raw_from  = trim( (string) $request->get_param( 'source' ) );
	$raw_to    = trim( (string) $request->get_param( 'destination' ) );
	$status    = (string) ( $request->get_param( 'status' ) ?? 'publish' );

	if ( '' === $raw_from || '' === $raw_to ) {
		return fail( 'Redirect From and Redirect To are required fields.' );
	}

	try {
		$destination = Destination::from_mixed( is_numeric( $raw_to ) ? (int) $raw_to : $raw_to );
	} catch ( \InvalidArgumentException $e ) {
		return fail( 'The destination is not valid.', 'destination' );
	}

	try {
		$source = SourceUrl::from_string( $raw_from, HomePath::current() );
	} catch ( \InvalidArgumentException $e ) {
		return fail( 'The source URL is not valid.', 'source' );
	}

	if ( $id ) {
		$existing = $container->repository()->find_by_id( $id );
		if ( null === $existing ) {
			return fail( 'Redirect not found.' );
		}
		$proposed = $existing->with_source( $source )->with_destination( $destination );
	} else {
		$proposed = Redirect::create( $source, $destination );
	}

	$validation = $container->validator()->validate( $proposed );
	if ( $validation->is_invalid() ) {
		return fail( (string) $validation->error_message() );
	}

	if ( $destination->is_url() && $destination->as_url()->is_relative()
		&& apply_filters( 'legacy_redirector_check_destination_reachability', true ) ) {
		$finding = $container->auditor()->audit_destination( $proposed, true );
		if ( null !== $finding && AuditFindingType::URL_NOT_FOUND === $finding->type() ) {
			return fail( 'The destination path does not exist.', 'destination' );
		}
	}

	$result = $id
		? $container->manager()->update_redirect( $id, $raw_from, $destination, $status )
		: $container->manager()->create_redirect( $source, $destination, true, $status );

	if ( $result->is_error() ) {
		return fail( (string) $result->error_message() );
	}

	$saved = $container->repository()->find_by_id( $id ? $id : (int) $result->redirect_id() );

	return null === $saved ? fail( 'Failed to save the redirect. Please try again.' ) : to_item( $saved );
}

function fail( string $message, ?string $field = null ): \WP_Error {
	return new \WP_Error( 'legacy_redirector_prototype_invalid', $message, array( 'status' => 400, 'field' => $field ) );
}
