<?php

/**
 * The LinkTitles\Targets class.
 *
 * Copyright 2012-2024 Daniel Kraus <bovender@bovender.de> ('bovender')
 */
namespace LinkTitles;

use MediaWiki\MediaWikiServices;

/**
 * Fetches potential target page titles from the database.
 */
class Targets {
	private static $instance;

	/**
	 * Singleton factory that returns a (cached) database query results with
	 * potential target page titles.
	 *
	 * @param  mixed  $title   The Title object of the source page.
	 * @param  Config $config  LinkTitles configuration.
	 */
	public static function singleton( $title, Config $config ) {
		if ( ( self::$instance === null ) || ( self::$instance->sourceNamespace != $title->getNamespace() ) ) {
			self::$instance = new Targets( $title, $config );
		}
		return self::$instance;
	}

	/**
	 * Invalidates the cache.
	 */
	public static function invalidate() {
		self::$instance = null;
	}

	/**
	 * @var \MediaWiki\Storage\NameTableStore|null $queryResult
	 */
	public $queryResult;

	/**
	 * @var int $sourceNamespace
	 */
	public $sourceNamespace;

	private $config;

	/**
	 * @var string $charLengthFunction
	 */
	private $charLengthFunction;

	/**
	 * The constructor is private to enforce using the singleton pattern.
	 * @param  mixed  $title
	 * @param  Config $config
	 */
	private function __construct( $title, Config $config) {
		$this->config = $config;
		$this->sourceNamespace = $title->getNamespace();
		$this->fetch();
	}

	/**
	 * Fetches the page titles from the database.
	 */
	private function fetch() {
		( $this->config->preferShortTitles ) ? $sortOrder = 'ASC' : $sortOrder = 'DESC';

		// Build a blacklist of pages
		if ( $this->config->blackList ) {
			$blackList = 'page_title NOT IN ' .
				str_replace( ' ', '_', '("' . implode( '","', str_replace( '"', '\"', $this->config->blackList ) ) . '")' );
		} else {
			$blackList = null;
		}

		if ( $this->config->sameNamespace ) {
			$namespaces = array_diff( $this->config->targetNamespaces, [ $this->sourceNamespace ] );
			array_unshift( $namespaces, $this->sourceNamespace );
		} else {
			$namespaces = $this->config->targetNamespaces;
		}

		if ( !$namespaces) {
			return;
		}

		$weightSelect = "CASE page_namespace ";
		$currentWeight = 0;
		foreach ($namespaces as $namespaceValue) {
				$currentWeight = $currentWeight + 100;
				$weightSelect = $weightSelect . " WHEN " . (int)$namespaceValue . " THEN " . (int)$currentWeight . PHP_EOL;
		}
		$weightSelect = $weightSelect . " END ";
		$namespacesClause = '(' . implode( ', ', array_map('intval', $namespaces) ) . ')';

		// FIXED: Replaced wfGetDB with MediaWikiServices
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		
		$this->queryResult = $dbr->select(
			'page',
			array( 'page_title', 'page_namespace' , "weight" => $weightSelect),
			array_filter(
				array(
					'page_namespace IN ' . $namespacesClause,
					$this->charLength() . '(page_title) >= ' . (int)$this->config->minimumTitleLength,
					$blackList,
				)
			),
			__METHOD__,
			array( 'ORDER BY' => 'weight ASC, ' . $this->charLength() . '(page_title) ' . $sortOrder )
		);
	}

	private function charLength() {
		if ($this->charLengthFunction === null) {
			$this->charLengthFunction = $this->config->sqliteDatabase() ? 'LENGTH' : 'CHAR_LENGTH';
		}
		return $this->charLengthFunction;
	}
}