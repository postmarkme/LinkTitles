#!/usr/bin/env php
<?php

/**
 * LinkTitles command line interface (CLI)/maintenance script
 * Updated for MediaWiki 1.40+ compatibility.
 *
 *  Copyright 2012-2024 Daniel Kraus <bovender@bovender.de> @bovender
 */

namespace LinkTitles;

use MediaWiki\MediaWikiServices;

// The maintenance execution logic changed in 1.40.
// We keep a basic check for backward compatibility but optimize for run.php.
$maintenanceScript = __DIR__ . "/../../maintenance/Maintenance.php";
if ( !file_exists( $maintenanceScript ) ) {
	$maintenanceScript = __DIR__ . "/Maintenance.php";
}

if ( file_exists( $maintenanceScript ) ) {
	require_once $maintenanceScript;
}

require_once( __DIR__ . "/includes/Extension.php" );

/**
 * Core class of the maintenance script.
 * @ingroup batch
 */
class Cli extends \Maintenance {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->addDescription("Iterates over wiki pages and automatically adds links to other pages.");
		$this->addOption( "start", "Set start index.", false, true, "s" );
		$this->addOption( "page", "page name to process", false, true, "p" );
		$this->addOption( "verbose", "print detailed progress information", false, false, "v" );
	}

	/*
	 * Main function of the maintenance script.
	 */
	public function execute() {
		if ( $this->hasOption('page') ) {
			if ( !$this->hasOption( 'start' ) ) {
				$this->singlePage();
			}
			else {
				$this->fatalError( 'FATAL: Must not use --start option with --page option.' );
			}
		}
		else {
			$startIndex = (int)$this->getOption( 'start', 0 );
			if ( $startIndex < 0 ) {
				$this->fatalError( 'FATAL: Start index must be 0 or greater.' );
			};
			$this->allPages( $startIndex );
		}
	}

	/**
	 * Processes a single page.
	 * @return bool True on success, false on failure.
	 */
	private function singlePage() {
		$pageName = strval( $this->getOption( 'page' ) );
		$this->output( "Processing single page: '$pageName'\n" );
		
		$services = MediaWikiServices::getInstance();
		$title = $services->getTitleFactory()->newFromText( $pageName );
		
		if ( !$title ) {
			$this->fatalError( 'FATAL: Invalid page title provided.' );
		}

		$success = Extension::processPage( $title, \RequestContext::getMain() );
		if ( $success ) {
			$this->output( "Finished.\n" );
		}
		else {
			$this->error( 'FATAL: There is no such page.', 3 );
		}
		return $success;
	}

	/**
	 * Process all pages in the Wiki.
	 * @param  integer $index Index of the start page.
	 * @return bool           True on success, false on failure.
	 */
	private function allPages( $index = 0 ) {
		$config = new Config();
		$verbose = $this->hasOption( 'verbose' );
		$services = MediaWikiServices::getInstance();
		$titleFactory = $services->getTitleFactory();

		// Retrieve page names from the database.
		$dbr = $this->getDB( DB_REPLICA );
		$namespacesClause = str_replace( '_', ' ','(' . implode( ', ', $config->sourceNamespaces ) . ')' );
		
		$res = $dbr->select(
			'page',
			[ 'page_title', 'page_namespace' ],
			[ 'page_namespace IN ' . $namespacesClause ],
			__METHOD__,
			[
				'LIMIT' => 999999999,
				'OFFSET' => $index
			]
		);
		
		$numPages = $res->numRows();
		$context = \RequestContext::getMain();
		$this->output( "Processing {$numPages} pages, starting at index {$index}...\n" );

		$numProcessed = 0;
		foreach ( $res as $row ) {
			// FIXED: Use TitleFactory instead of global Title class
			$title = $titleFactory->makeTitleSafe( (int)$row->page_namespace, $row->page_title );
			
			$numProcessed += 1;
			$index += 1;
			
			if ( $title ) {
				if ( $verbose ) {
					$this->output(
						sprintf(
							"%s - processed %5d of %5d (%2.0f%%) - index %5d - %s\n",
							date("c"),
							$numProcessed,
							$numPages,
							($numPages > 0) ? ($numProcessed / $numPages * 100) : 100,
							$index,
							$title->getPrefixedText()
						)
					);
				} else {
					$percent = ($numPages > 0) ? ($numProcessed / $numPages * 100) : 100;
					$this->output( sprintf( "\rPage #%d (%02.0f%%) ", $index, $percent ) );
				}
				
				Extension::processPage( $title, $context );
			}
		}

		$this->output( "\nFinished.\n" );
	}
}

// Global script entry
$maintClass = Cli::class;
require_once RUN_MAINTENANCE_IF_MAIN;