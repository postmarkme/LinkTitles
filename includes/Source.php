<?php

/**
 * The LinkTitles\Source represents a Wiki page to which links may be added.
 */
namespace LinkTitles;

use MediaWiki\MediaWikiServices;

/**
 * Represents a page that is a potential link target.
 */
class Source {
	/**
	 * The LinKTitles configuration for this Source.
	 * @var Config $config
	 */
	public $config;

	private $title;
	private $text;
	private $page;
	private $content;

	/**
	 * Creates a Source object from a Title.
	 * Updated to remove strict type hint to prevent MediaWiki namespace conflicts.
	 * 
	 * @param  mixed   $title  The Title object.
	 * @param  Config  $config LinkTitles configuration.
	 * @return Source
	 */
	public static function createFromTitle( $title, Config $config ) {
		$source = new Source( $config );
		$source->title = $title;
		return $source;
	}

	/**
	 * Creates a Source object with a given WikiPage and a Content.
	 *
	 * @param  mixed   $page     WikiPage to link from
	 * @param  mixed   $content  Page content
	 * @param  Config  $config   LinkTitles configuration
	 * @return Source
	 */
	public static function createFromPageandContent( $page, $content, Config $config ) {
		$source = new Source( $config );
		$source->page = $page;
		$source->content = $content;
		return $source;
	}

	/**
	 * Creates a Source object with a given Parser.
	 *
	 * @param  mixed   $parser Parser object.
	 * @param  Config  $config LinKTitles Configuration
	 * @return Source
	 */
	public static function createFromParser( $parser, Config $config ) {
		$source = new Source( $config );
		$source->title = $parser->getTitle();
		return $source;
	}

	/**
	 * Creates a Source object with a given Parser and text.
	 *
	 * @param  mixed   $parser Parser object.
	 * @param  String  $text   String representation of content.
	 * @param  Config  $config LinKTitles Configuration
	 * @return Source
	 */
	public static function createFromParserAndText( $parser, $text, Config $config ) {
		$source = self::createFromParser( $parser, $config );
		$source->text = $text;
		return $source;
	}

	/**
	 * Private constructor.
	 * @param Config $config
	 */
	private function __construct( Config $config) {
		$this->config = $config;
	}

	public function canBeLinked() {
		return $this->hasDesiredNamespace() && !$this->hasNoAutolinksMagicWord();
	}

	public function hasDesiredNamespace() {
		return in_array( $this->getTitle()->getNamespace(), $this->config->sourceNamespaces );
	}

	public function hasNoAutolinksMagicWord() {
		$text = $this->getText();
		if (!$text) return false;
		return MediaWikiServices::getInstance()->getMagicWordFactory()->get( 'MAG_LINKTITLES_NOAUTOLINKS' )->match( $text );
	}

	public function getTitle() {
		if ( $this->title === null ) {
			if ( $this->page != null) {
				$this->title = $this->page->getTitle();
			} else {
				throw new \Exception( 'Unable to create Title for this Source because Page is null.' );
			}
		}
		return $this->title;
	}

	public function getNamespace() {
		return $this->getTitle()->getNamespace();
	}

	public function getContent() {
		if ( $this->content === null ) {
			$this->content = $this->getPage()->getContent();
		}
		return $this->content;
	}

	public function hasContent() {
		return $this->getContent() != null;
	}

	public function getText() {
		if ( $this->text === null ) {
			$content = $this->getContent();
			if ( $content ) {
				$this->text = $content->getContentHandler()->serializeContent( $content );
			} else {
				$this->text = '';
			}
		}
		return $this->text;
	}

	public function setText( $text ) {
		$content = $this->getContent();
		if ( $content ) {
			$this->content = $content->getContentHandler()->unserializeContent( $text );
			$this->text = $text;
		}
		return $this->content;
	}

	public function getPage() {
		if ( $this->page === null ) {
			if ( $this->title != null) {
				$this->page = static::getPageObject( $this->title );
			} else {
				throw new \Exception( 'Unable to create Page for this Source because Title is null.' );
			}
		}
		return $this->page;
	}

	private static function getPageObject( $title ) {
		$services = MediaWikiServices::getInstance();
		if ( method_exists( $services, 'getWikiPageFactory' ) ) {
			return $services->getWikiPageFactory()->newFromTitle( $title );
		}
		return \WikiPage::factory( $title );
	}
}