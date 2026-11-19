<?php
/**
 * Ability definition interface.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

/**
 * An ability this plugin registers with the WordPress Abilities API.
 *
 * Implementations are presentation only: they translate ability input into
 * calls on the application services, and service results back into the
 * structures described by their output schema.
 */
interface AbilityInterface {

	/**
	 * Get the namespaced ability name.
	 *
	 * @return string The ability name, e.g. 'legacy-redirector/get-redirect'.
	 */
	public function name(): string;

	/**
	 * Get the arguments to register the ability with.
	 *
	 * @return array<string, mixed> Arguments accepted by wp_register_ability().
	 */
	public function args(): array;
}
