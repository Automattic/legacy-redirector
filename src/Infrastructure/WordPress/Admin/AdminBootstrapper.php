<?php
/**
 * Bootstraps all admin functionality.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditResults;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ScanButton;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ListScreenSetup;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\FormScreenSetup;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\RedirectFormPage;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\ValidatePage;

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
	 * Redirect auditor.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * Stored audit results.
	 *
	 * @var AuditResults
	 */
	private AuditResults $audit_results;

	/**
	 * Per-row audit flags.
	 *
	 * @var AuditFlags
	 */
	private AuditFlags $audit_flags;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface      $repository       Redirect repository.
	 * @param RedirectManager                  $manager          Redirect manager.
	 * @param RedirectValidator                $validator        Redirect validator.
	 * @param RedirectQueryRepositoryInterface $query_repository Redirect query repository.
	 * @param RedirectAuditor                  $auditor          Redirect auditor.
	 * @param AuditResults                     $audit_results    Stored audit results.
	 * @param AuditFlags                       $audit_flags      Per-row audit flags.
	 */
	public function __construct(
		RedirectRepositoryInterface $repository,
		RedirectManager $manager,
		RedirectValidator $validator,
		RedirectQueryRepositoryInterface $query_repository,
		RedirectAuditor $auditor,
		AuditResults $audit_results,
		AuditFlags $audit_flags
	) {
		$this->repository       = $repository;
		$this->manager          = $manager;
		$this->validator        = $validator;
		$this->query_repository = $query_repository;
		$this->auditor          = $auditor;
		$this->audit_results    = $audit_results;
		$this->audit_flags      = $audit_flags;
	}

	/**
	 * Initialize all admin components.
	 *
	 * @return void
	 */
	public function init(): void {
		// List table customization.
		$this->register_list_table_components();

		// Form pages.
		$this->register_form_pages();

		// Validation notices.
		$this->register_validation_notices();
	}

	/**
	 * Register list table components.
	 *
	 * @return void
	 */
	private function register_list_table_components(): void {
		$columns = new ColumnsManager( $this->repository, $this->auditor );
		$columns->register();

		$row_actions = new RowActionsManager( $this->repository );
		$row_actions->register();

		$view_filters = new ViewFilters( $this->query_repository, $this->audit_flags );
		$view_filters->register();

		$list_screen_setup = new ListScreenSetup();
		$list_screen_setup->register();

		$scan_button = new ScanButton();
		$scan_button->register();
	}

	/**
	 * Register form pages.
	 *
	 * @return void
	 */
	private function register_form_pages(): void {
		$form_page = new RedirectFormPage( $this->repository, $this->manager, $this->validator, $this->auditor );
		$form_page->register();

		$form_screen_setup = new FormScreenSetup();
		$form_screen_setup->register();

		$validate_page = new ValidatePage( $this->query_repository, $this->auditor, $this->audit_results );
		$validate_page->register();
	}

	/**
	 * Register validation notices.
	 *
	 * @return void
	 */
	private function register_validation_notices(): void {
		$notices = new ValidationNotices( $this->repository, $this->auditor );
		$notices->register();
	}
}
