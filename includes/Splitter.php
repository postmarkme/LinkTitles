<?php

/**
 * The Splitter class caches a regular expression that delimits text to be parsed.
 *
 * Copyright 2012-2024 Daniel Kraus <bovender@bovender.de> ('bovender')
 */
namespace LinkTitles;

/**
 * Caches a regular expression that delimits text to be parsed.
 */
class Splitter {
	/**
	 * The splitting expression.
	 * @var string $splitter
	 */
	public $splitter;

	/**
	 * The LinkTitles configuration.
	 * @var Config $config
	 */
	public $config;

	private static $instance;

	/**
	 * Gets the Splitter singleton.
	 *
	 * @param  Config|null $config LinkTitles configuration.
	 */
	public static function singleton( Config $config = null ) {
		if ( self::$instance === null ) {
			if ( $config === null ) {
				$config = new Config();
			}
			self::$instance = new Splitter( $config );
		}
		return self::$instance;
	}

	/**
	 * Invalidates the singleton instance.
	 */
	public static function invalidate() {
		self::$instance = null;
	}

	protected function __construct( Config $config) {
		$this->config = $config;
		$this->buildSplitter();
	}

	/**
	 * Splits a text into sections that may be linked and sections that may not.
	 *
	 * @param  string $text Text to split.
	 * @return array Of strings where even indexes point to linkable sections.
	 */
	public function split( $text ) {
		return preg_split( $this->splitter, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
	}

	/**
	 * Builds the delimiter that is used in a regexp to separate
	 * text that should be parsed from text that should not be parsed.
	 */
	private function buildSplitter() {
		if ( $this->config->skipTemplates ) {
			// Use recursive regex to balance curly braces
			$templatesDelimiter = '{{(?>[^{}]|(?R))*}}|';
		} else {
			// Match template names
			$templatesDelimiter = '{{[^|]*?(?:(?:\[\[[^]]+]])?)[^|]*?(?:\|(?:\w+=)?|(?:}}))|\|\w+=|';
		}

		// Match WikiText headings if requested.
		$headingsDelimiter = $this->config->parseHeadings ? '' : '^=+[^=]+=+$|';

		$urlPattern = '[a-z]+?\:\/\/(?:\S+\.)+\S+(?:\/.*)?';
		$this->splitter = '/(' .                     // exclude from linking:
			'\[\[.*?\]\]|' .                            // links
			$headingsDelimiter .                        // headings
			$templatesDelimiter .                       // templates
			'^ .+?\n|\n .+?\n|\n .+?$|^ .+?$|' .        // preformatted text
			'<nowiki>.*?<.nowiki>|<code>.*?<\/code>|' . // nowiki/code
			'<pre>.*?<\/pre>|<html>.*?<\/html>|' .      // pre/html
			'<script>.*?<\/script>|' .                  // script
			'<syntaxhighlight.*?>.*?<\/syntaxhighlight>|' . // syntaxhighlight
			'<gallery>.*?<\/gallery>|' .                // gallery
			'<div.*?>|<\/div>|' .                       // attributes of div elements
			'<input.+<\/input>|' .                      // input tags
			'<select.+<\/select>|' .                    // select tags
			'<span.*?>|<\/span>|' .                     // attributes of span elements
			'<file>[^<]*<\/file>|' .                    // stuff inside file elements
			'style=".+?"|class=".+?"|data-sort-value=".+?"|' . 	// styles and classes
			'<noautolinks>.*?<\/noautolinks>|' .        // custom tag 'noautolinks'
			'\[' . $urlPattern . '\s.+?\]|'. $urlPattern .  '(?=\s|$)|' . // urls
			'(?<=\b)\S+\@(?:\S+\.)+\S+(?=\b)' .        // email addresses
			')/ismS';
	}
}