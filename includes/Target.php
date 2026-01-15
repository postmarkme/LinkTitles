<?php

/**
 * The LinkTitles\Target represents a Wiki page that is a potential link target.
 * Updated for MediaWiki 1.39+ compatibility.
 */
namespace LinkTitles;

use MediaWiki\MediaWikiServices;

/**
 * Represents a page that is a potential link target.
 */
class Target {
	/**
	 * @var mixed $title
	 */
	private $title;

	/**
	 * @var mixed $content
	 */
	private $content;

	/**
	 * @var String $wordStart
	 */
	public $wordStart;

	/**
	 * @var String $wordEnd
	 */
	public $wordEnd;

	/**
	 * @var Config $config
	 */
	private $config;

	private $caseSensitiveLinkValueRegex;

	private $nsText;

	private $titleValue;

	/**
	 * Constructs a new Target object
	 *
	 * @param int $namespace Namespace of the target page
	 * @param string $title Title of the target page
	 * @param Config $config Configuration object
	 */
	public function __construct( $namespace, $title, Config &$config ) {
		$services = MediaWikiServices::getInstance();
		$this->title = $services->getTitleFactory()->makeTitleSafe( (int)$namespace, $title );
		
		if ( $this->title ) {
			$this->titleValue = $this->title->getTitleValue();
		}

		$this->config = $config;

		// Use unicode character properties rather than \b escape sequences
		( $config->wordStartOnly ) ? $this->wordStart = '(?<!\pL|\pN)' : $this->wordStart = '';
		( $config->wordEndOnly ) ? $this->wordEnd = '(?!\pL|\pN)' : $this->wordEnd = '';
	}

	/**
	 * Gets the string representation of the target title.
	 * @return String title text
	 */
	public function getTitleText() {
		return $this->title ? $this->title->getText() : '';
	}

	public function getPrefixedTitleText() {
		if ( !$this->title ) return '';

		if ( $this->title->getNamespace() == NS_CATEGORY ) {
			return ':' . $this->title->getPrefixedText();
		} else {
			return $this->title->getPrefixedText();
		}
	}

	/**
	 * Gets the string representation of the target's namespace.
	 */
	public function getNsText() {
		if ( $this->nsText === null && $this->title ) {
			$this->nsText = $this->title->getNsText();
		}
		return $this->nsText;
	}

	/**
	 * Gets the namespace prefix.
	 */
	public function getNsPrefix() {
		return $this->getNsText() ? $this->getNsText() . ':' : '';
	}

	/**
	 * Gets the title string with certain characters escaped.
	 */
	public function getRegexSafeTitle() {
		return $this->title ? preg_quote( $this->title->getText(), '/' ) : '';
	}

	public function getCaseSensitiveRegex() {
		return $this->buildRegex( $this->getCaseSensitiveLinkValueRegex() );
	}

	public function getCaseInsensitiveRegex() {
		return $this->buildRegex( $this->getRegexSafeTitle() ) . 'i';
	}

	private function buildRegex( $searchTerm ) {
		return '/(?<![\:\.\@\/\?\&])' . $this->wordStart . $searchTerm . $this->wordEnd . '/S';
	}

	public function getCaseSensitiveLinkValueRegex() {
		if ( $this->caseSensitiveLinkValueRegex === null ) {
			$regexSafeTitle = $this->getRegexSafeTitle();
			if ( $this->config->capitalLinks && $regexSafeTitle !== '' && preg_match( '/[a-zA-Z]/', $regexSafeTitle[0] ) ) {
				$this->caseSensitiveLinkValueRegex = '((?i)' . $regexSafeTitle[0] . '(?-i)' . substr($regexSafeTitle, 1) . ')';
			}	else {
				$this->caseSensitiveLinkValueRegex = '(' . $regexSafeTitle . ')';
			}
		}
		return $this->caseSensitiveLinkValueRegex;
	}

	public function getContent() {
		if ( $this->content === null && $this->title ) {
			$this->content = static::getPageContents( $this->title );
		}
		return $this->content;
	}

	/**
	 * Examines the current target page.
	 */
	public function mayLinkTo( Source $source ) {
		if ( !$this->title ) return false;

		if ( $this->config->checkRedirect && $this->redirectsTo( $source ) ) {
			return false;
		}

		if ( $this->config->enableNoTargetMagicWord ) {
			$content = $this->getContent();
			if ( $content ) {
				// matchMagicWord is deprecated/removed. Use MagicWordFactory instead.
				$magicWord = MediaWikiServices::getInstance()->getMagicWordFactory()->get( 'MAG_LINKTITLES_NOTARGET' );
				$text = $content->getContentHandler()->serializeContent( $content );
				if ( $magicWord->match( $text ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public function isSameTitle( Source $source ) {
		if ( !$this->title ) return false;
		return $this->title->equals( $source->getTitle() );
	}

	public function redirectsTo( $source ) {
		$content = $this->getContent();
		if ( $content ) {
			$redirectTitle = $content->getRedirectTarget();
			return $redirectTitle && $redirectTitle->equals( $source->getTitle() );
		}
		return false;
	}

	private static function getPageContents( $title ) {
		$services = MediaWikiServices::getInstance();
		if ( method_exists( $services, 'getWikiPageFactory' ) ) {
			$page = $services->getWikiPageFactory()->newFromTitle( $title );
		} else {
			$page = \WikiPage::factory( $title );
		}
		return $page->getContent();
	}
}