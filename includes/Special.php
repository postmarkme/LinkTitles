<?php
/**
 * Provides a special page for the LinkTitles extension.
 * Updated for MediaWiki 1.39+ compatibility.
 */

namespace LinkTitles;

use MediaWiki\MediaWikiServices;

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'Not an entry point.' );
}

class Special extends \SpecialPage {
	private $config;

	function __construct() {
		parent::__construct( 'LinkTitles', 'linktitles-batch' );
		$this->config = new Config();
	}

	function getGroupName() {
		return 'pagetools';
	}

	function execute( $par ) {
		if ( !$this->userCanExecute( $this->getUser() ) ) {
			$this->displayRestrictionError();
			return;
		}

		$request = $this->getRequest();
		$output = $this->getOutput();
		$this->setHeaders();

		if ( $request->wasPosted() ) {
			if ( array_key_exists( 's', $request->getValues() ) ) {
				$this->process( $request, $output );
			}
			else {
				$this->buildInfoPage( $request, $output );
			}
		}
		else {
			$this->buildInfoPage( $request, $output );
		}
	}

	private function process( \WebRequest &$request, \OutputPage &$output) {
		$namespacesClause = str_replace( '_', ' ','(' . implode( ', ',$this->config->sourceNamespaces ) . ')' );
		$startTime = microtime( true );
		
		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$titleFactory = $services->getTitleFactory();

		$postValues = $request->getValues();
		$start = intval( $postValues['s'] );

		if ( array_key_exists( 'e', $postValues ) ) {
			$end = intval( $postValues['e'] );
		}
		else {
			$end = $this->countPages( $dbr, $namespacesClause );
		}

		array_key_exists( 'r', $postValues ) ? $reloads = $postValues['r'] : $reloads = 0;

		$res = $dbr->select(
			'page',
			array('page_title', 'page_namespace'),
			array('page_namespace IN ' . $namespacesClause),
			__METHOD__,
			array('LIMIT' => 999999, 'OFFSET' => $start)
		);

		$curTitle = null;
		foreach ( $res as $row ) {
			// FIXED: Using TitleFactory instead of the global Title class
			$curTitle = $titleFactory->makeTitleSafe( (int)$row->page_namespace, $row->page_title );
			
			if ( $curTitle ) {
				Extension::processPage( $curTitle, $this->getContext() );
			}
			
			$start += 1;
			if ( microtime( true ) - $startTime > $this->config->specialPageReloadAfter ) {
				break;
			}
		}

		$this->addProgressInfo( $output, $curTitle, $start, $end );

		if ( $start < $end ) {
			$reloads += 1;
			$output->addHTML( $this->getReloaderForm( $request->getRequestURL(), $start, $end, $reloads) );
		}
		else {
			$this->addCompletedInfo( $output, $start, $end, $reloads );
		}
	}

	private function buildInfoPage( &$request, &$output ) {
		$output->addWikiMsg( 'linktitles-special-info', Extension::URL );
		$url = $request->getRequestURL();
		$submitButtonLabel = $this->msg( 'linktitles-special-submit' );
		$output->addHTML(
<<<EOF
<form method="post" action="{$url}">
	<input type="submit" value="$submitButtonLabel" />
	<input type="hidden" name="s" value="0" />
</form>
EOF
		);
	}

	private function addProgressInfo( &$output, $curTitle, $index, $end ) {
		$progress = ($end > 0) ? ($index / $end * 100) : 100;
		$percent = sprintf("%01.1f", $progress);
		
		// Handle display title for progress message
		$titleText = $curTitle ? $curTitle->getPrefixedText() : '';
		
		$output->addWikiMsg( 'linktitles-special-progress', Extension::URL, $titleText );
		$output->addWikiMsg( 'linktitles-special-page-count', $index, $end );
		$output->addHTML( 
<<<EOF
<div style="width:100%; padding:2px; border:1px solid #000; position: relative; margin-bottom:16px;">
	<span style="position: absolute; left: 50%; font-weight:bold; color:#555;">{$percent}%</span>
	<div style="width:{$progress}%; background-color:#bbb; height:20px; margin:0;"></div>
</div>
EOF
		);
		$output->addWikiMsg( 'linktitles-special-cancel-notice' );
	}

	private function getReloaderForm( $url, $start, $end, $reloads ) {
		return
<<<EOF
<form method="post" name="linktitles" action="{$url}">
	<input type="hidden" name="s" value="{$start}" />
	<input type="hidden" name="e" value="{$end}" />
	<input type="hidden" name="r" value="{$reloads}" />
</form>
<script type="text/javascript">
	document.linktitles.submit();
</script>
EOF
		;
	}

	private function addCompletedInfo( &$output, $start, $end, $reloads ) {
		$pagesPerReload = ($reloads > 0) ? sprintf( '%0.1f', $end / $reloads ) : sprintf( '%0.1f', $end );
		$output->addWikiMsg( 'linktitles-special-completed-info', $end,
			$this->config->specialPageReloadAfter, $reloads, $pagesPerReload
		);
	}

	private function countPages( $dbr, $namespacesClause ) {
		$res = $dbr->select(
			'page',
			array('pagecount' => "COUNT(page_id)"),
			array('page_namespace IN ' . $namespacesClause),
			__METHOD__
		);
		$row = $res->fetchObject();
		return $row ? (int)$row->pagecount : 0;
	}
}