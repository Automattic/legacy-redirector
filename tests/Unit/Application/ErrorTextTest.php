<?php
/**
 * ErrorText unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\ErrorText;
use Automattic\LegacyRedirector\Domain\InvalidRedirectValue;
use Automattic\LegacyRedirector\Domain\RedirectPersistenceException;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;

/**
 * ErrorTextTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\ErrorText
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Domain\InvalidRedirectValue
 * @uses \Automattic\LegacyRedirector\Domain\RedirectPersistenceException
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class ErrorTextTest extends MonkeyStubs {

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\stubTranslationFunctions();
	}

	/**
	 * Test the translatable text matches the Domain's English for every reason.
	 *
	 * The CLI shows getMessage() and every other surface shows this, so in
	 * English the two must read the same, and every reason must be mapped.
	 *
	 * @dataProvider exception_provider
	 *
	 * @param \Throwable $e The exception.
	 */
	public function test_text_matches_the_domain_english( \Throwable $e ): void {
		$this->assertSame( $e->getMessage(), ErrorText::of( $e ) );
	}

	/**
	 * One exception per reason.
	 *
	 * @return array<string, array{\Throwable}>
	 */
	public function exception_provider(): array {
		$reasons = ( new \ReflectionClass( InvalidRedirectValue::class ) )->getConstants();
		$cases   = array();

		foreach ( $reasons as $reason ) {
			$cases[ $reason ] = array( InvalidRedirectValue::because( $reason ) );
		}

		$source = SourceUrl::from_string( '/old-page' );

		$cases['save failed']      = array( RedirectPersistenceException::save_failed( $source, 'Database error.' ) );
		$cases['duplicate source'] = array( RedirectPersistenceException::duplicate_source( $source ) );
		$cases['source taken']     = array( RedirectPersistenceException::source_taken( $source, 42 ) );
		$cases['corrupt redirect'] = array( RedirectPersistenceException::corrupt_redirect( 42 ) );

		return $cases;
	}

	/**
	 * Test the provider has a case for every persistence reason too.
	 */
	public function test_provider_covers_every_persistence_reason(): void {
		$reasons = array_values( ( new \ReflectionClass( RedirectPersistenceException::class ) )->getConstants() );
		$covered = array_map(
			static fn( array $row ): string => $row[0] instanceof RedirectPersistenceException ? $row[0]->reason() : '',
			$this->exception_provider()
		);

		$this->assertEqualsCanonicalizing( $reasons, array_values( array_filter( $covered ) ) );
	}

	/**
	 * Test the translation is what the user sees, not the English message.
	 */
	public function test_uses_the_translation(): void {
		Functions\when( '__' )->alias( static fn( string $text ): string => 'The URL is not valid UTF-8.' === $text ? 'Die URL ist kein gültiges UTF-8.' : $text );

		$this->assertSame( 'Die URL ist kein gültiges UTF-8.', ErrorText::of( InvalidRedirectValue::because( InvalidRedirectValue::SOURCE_NOT_UTF8 ) ) );
	}

	/**
	 * Test any other exception keeps its own message.
	 */
	public function test_other_exceptions_keep_their_message(): void {
		$this->assertSame( 'Something else.', ErrorText::of( new \RuntimeException( 'Something else.' ) ) );
	}
}
