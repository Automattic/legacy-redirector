<?php
/**
 * Bootstraps all admin functionality.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin;

use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\CheckDuplicateHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\SearchPostsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\ValidateRedirectHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ListScreenSetup;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\FormScreenSetup;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\RedirectFormPage;

/**
 * Initializes all admin components for the redirect management interface.
 */
final class AdminBootstrapper {

	/**
	 * Redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * Redirect validator.
	 *
	 * @var RedirectValidator
	 */
	private RedirectValidator $validator;

	/**
	 * Redirect query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface      $repository       Redirect repository.
	 * @param RedirectManager                  $manager          Redirect manager.
	 * @param RedirectValidator                $validator        Redirect validator.
	 * @param RedirectQueryRepositoryInterface $query_repository Redirect query repository.
	 */
	public function __construct(
		RedirectRepositoryInterface $repository,
		RedirectManager $manager,
		RedirectValidator $validator,
		RedirectQueryRepositoryInterface $query_repository
	) {
		$this->repository       = $repository;
		$this->manager          = $manager;
		$this->validator        = $validator;
		$this->query_repository = $query_repository;
	}

	/**
	 * Initialize all admin components.
	 *
	 * @return void
	 */
	public function init(): void {
		// AJAX handlers.
		$this->register_ajax_handlers();

		// List table customization.
		$this->register_list_table_components();

		// Form pages.
		$this->register_form_pages();

		// Validation notices.
		$this->register_validation_notices();
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	private function register_ajax_handlers(): void {
		$check_duplicate = new CheckDuplicateHandler( $this->repository );
		$check_duplicate->register();

		$search_posts = new SearchPostsHandler();
		$search_posts->register();

		$validate = new ValidateRedirectHandler( $this->repository, $this->validator );
		$validate->register();
	}

	/**
	 * Register list table components.
	 *
	 * @return void
	 */
	private function register_list_table_components(): void {
		$columns = new ColumnsManager();
		$columns->register();

		$row_actions = new RowActionsManager();
		$row_actions->register();

		$view_filters = new ViewFilters( $this->query_repository );
		$view_filters->register();

		$list_screen_setup = new ListScreenSetup();
		$list_screen_setup->register();
	}

	/**
	 * Register form pages.
	 *
	 * @return void
	 */
	private function register_form_pages(): void {
		$form_page = new RedirectFormPage( $this->repository, $this->manager, $this->validator );
		$form_page->register();

		$form_screen_setup = new FormScreenSetup();
		$form_screen_setup->register();
	}

	/**
	 * Register validation notices.
	 *
	 * @return void
	 */
	private function register_validation_notices(): void {
		$notices = new ValidationNotices( $this->repository, $this->validator );
		$notices->register();
	}
}
