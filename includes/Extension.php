<?php

/**
 * The LinkTitles\Extension class provides event handlers and entry points for the extension.
 * Updated for MediaWiki 1.39+ compatibility.
 */

namespace LinkTitles;

use CommentStoreComment;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RenderedRevision;
use MediaWiki\Revision\SlotRecord;
use Status;
use WikiPage;
use User;

/**
 * Provides event handlers and entry points for the extension.
 */
class Extension {
	const URL = 'https://github.com/bovender/LinkTitles';

	/**
	 * Event handler for the MultiContentSave hook.
	 *
	 * This handler is used if the parseOnEdit configuration option is set.
	 */
	public static function onMultiContentSave(
		RenderedRevision $renderedRevision,
		User $user,
		CommentStoreComment $summary,
		$flags,
		Status $hookStatus
	) {
		$isMinor = $flags & EDIT_MINOR;

		$config = new Config();
		if ( !$config->parseOnEdit || $isMinor ) return true;

		$revision = $renderedRevision->getRevision();
		
		// FIXED: Use getPage() instead of getPageAsLinkTarget() 
		// getPage() returns a PageReference which implements PageIdentity
		$pageIdentity = $revision->getPage(); 
		
		$slots = $revision->getSlots();
		$content = $slots->getContent( SlotRecord::MAIN );

		$services = MediaWikiServices::getInstance();
		
		// MW 1.36+
		if ( method_exists( $services, 'getWikiPageFactory' ) ) {
			$wikiPageFactory = $services->getWikiPageFactory();
			$wikiPage = $wikiPageFactory->newFromTitle( $pageIdentity );
		} else {
			// Fallback for older versions using the LinkTarget
			$wikiPage = WikiPage::factory( \Title::newFromLinkTarget( $revision->getPageAsLinkTarget() ) );
		}
		
		$source = Source::createFromPageandContent( $wikiPage, $content, $config );
		$linker = new Linker( $config );
		$result = $linker->linkContent( $source );
		if ( $result ) {
			$content = $source->setText( $result );
			$slots->setContent( 'main', $content );
		}

		return true;
	}

	/*
	 * Event handler for the InternalParseBeforeLinks hook.
	 */
	public static function onInternalParseBeforeLinks( \Parser &$parser, &$text ) {
		$config = new Config();
		if ( !$config->parseOnRender ) return true;
		$source = Source::createFromParserAndText( $parser, $text, $config );
		$linker = new Linker( $config );
		$result = $linker->linkContent( $source );
		if ( $result ) {
			$text = $result;
		}
		return true;
	}

	/**
	 * Adds links to a single page.
	 *
	 * @param  mixed $title Title object.
	 * @param  \IContextSource $context Current request context.
	 * @return bool True if the page exists, false if the page does not exist
	 */
	public static function processPage( $title, \IContextSource $context ) {
		$config = new Config();
		$source = Source::createFromTitle( $title, $config );
		if ( $source->hasContent() ) {
			$linker = new Linker( $config );
			$result = $linker->linkContent( $source );
			if ( $result ) {
				$content = $source->getContent()->getContentHandler()->unserializeContent( $result );

				$updater = $source->getPage()->newPageUpdater( $context->getUser());
				$updater->setContent( SlotRecord::MAIN, $content );
				
				// Ensure wfMessage returns a string
				$commentText = \wfMessage( 'linktitles-bot-comment', self::URL )->text();
				
				$updater->saveRevision(
					CommentStoreComment::newUnsavedComment( $commentText ),
					EDIT_MINOR | EDIT_FORCE_BOT
				);
			};
			return true;
		}
		else {
			return false;
		}
	}

	public static function onGetDoubleUnderscoreIDs( array &$doubleUnderscoreIDs ) {
		$doubleUnderscoreIDs[] = 'MAG_LINKTITLES_NOTARGET';
		$doubleUnderscoreIDs[] = 'MAG_LINKTITLES_NOAUTOLINKS';
		return true;
	}

	public static function onParserFirstCallInit( \Parser $parser ) {
		$parser->setHook( 'noautolinks', 'LinkTitles\Extension::doNoautolinksTag' );
		$parser->setHook( 'autolinks', 'LinkTitles\Extension::doAutolinksTag' );
	}

	public static function doNoautolinksTag( $input, array $args, \Parser $parser, \PPFrame $frame ) {
		Linker::lock();
		$result =  $parser->recursiveTagParse( $input, $frame );
		Linker::unlock();
		return $result;
	}

	public static function doAutolinksTag( $input, array $args, \Parser $parser, \PPFrame $frame ) {
		$config = new Config();
		$linker = new Linker( $config );
		$source = Source::createFromParserAndText( $parser, $input, $config );
		Linker::unlock();
		$result = $linker->linkContent( $source );
		Linker::lock();
		if ( $result ) {
			return $parser->recursiveTagParse( $result, $frame );
		} else {
			return $parser->recursiveTagParse( $input, $frame );
		}
	}
}