<?php

namespace MediaWiki\Extension\NPCEO;

use Html;
use Parser;
use PPFrame;
use Sanitizer;
use MediaWiki\MediaWikiServices;
use MediaWiki;
use Title;
use SpecialPage;
use Linker;

class NPCEO {
        public static function init( Parser $parser ) {
        	$parser->setHook( 'npceo-wanted-list', [ __CLASS__, 'renderList' ] );
		$parser->setHook( 'npceo-wanted-count', [ __CLASS__, 'renderCount' ] );
		$parser->setFunctionHook( 'npceomodel', [ __CLASS__, 'renderModel' ] );
        }

	/**
	 * Callback for the above function, renders contents of the <wantedpagens> tag.
	 *
	 * @param string|null $input User-supplied input, if any
	 * @param string[] $args User-supplied arguments to the tag, if any
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public static function renderList( $input, array $args, Parser $parser, PPFrame $frame ) {
		$f = new NPCEO();
		return $f->parse( $input, $parser );
	}

	/**
	 * Gets value from the parameter list.
	 *
	 * @param string $name
	 * @param string|null $value
	 * @param Parser|null $parser
	 * @return string
	 */
	public function get( $name = null, $value = null, $parser = null ) {
		if($name) {
			if ( preg_match( "/^\s*$name\s*=\s*(.*)/mi", $this->sInput, $matches ) ) {
				$arg = trim( $matches[1] );
				if ( is_int( $value ) ) {
					return intval( $arg );
				} elseif ( $parser === null ) {
					return htmlspecialchars( $arg );
				} else {
					return $parser->replaceVariables( $arg );
				}
			}
		} else {
			$arg = trim( $this->sInput );
			if ( is_int( $value ) ) {
				return intval( $arg );
			} elseif ( $parser === null ) {
				return htmlspecialchars( $arg );
			} else {
				return $parser->replaceVariables( $arg );
			}
		}
		
		return $value;
	}

	/**
	 * @param string $type
	 * @param int|null $error
	 * @return string
	 */
	public function msg( $type, $error = null ) {
		if ( $error && ( $this->get( 'suppresserrors' ) == 'true' ) ) {
			return '';
		}

		return wfMessage( $type )->escaped();
	}

	/**
	 * @param string|null &$input
	 * @param Parser &$parser
	 * @return string HTML
	 */
	public function parse( &$input, &$parser ) {
		$this->sInput =& $input;

		$arg = $this->get( 'namespace', '', $parser );
		$iNamespace = MediaWikiServices::getInstance()->getContentLanguage()->getNsIndex( $arg );
		if ( !$iNamespace ) {
			if ( ( $arg ) || ( $arg === '0' ) ) {
				$iNamespace = intval( $arg );
			} else {
				$iNamespace = -1;
			}
		}
		if ( $iNamespace < 0 ) {
			return $this->msg( 'wpfromns-nons', 1 );
		}

		$output = '';

		$count = 1;
		$start = 0;
		if ( !( $this->get( 'cache' ) == 'true' ) ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}
		if ( $start < 0 ) {
			$start = 0;
		}

		$dbr = wfGetDB( DB_REPLICA );
		// The SQL below is derived from includes/specials/SpecialWantedpages.php
		$res = $dbr->select(
			[
				'pagelinks',
				'pg1' => 'page',
				'pg2' => 'page'
			],
			[
				'namespace' => 'pl_namespace',
				'title' => 'pl_title',
				'value' => 'COUNT(*)'
			],
			[
				'pg1.page_namespace IS NULL',
				'pl_namespace' => $iNamespace
				// 'pg2.page_namespace != ' . NS_MEDIAWIKI
			],
			__METHOD__,
			[ 'GROUP BY' => [ 'pl_namespace', 'pl_title' ] ],
			[
				'pg1' => [
					'LEFT JOIN', [
						'pl_namespace = pg1.page_namespace',
						'pl_title = pg1.page_title'
					]
				],
				'pg2' => [ 'LEFT JOIN', 'pl_from = pg2.page_id' ]
			]
		);

		$counts = [];
		foreach ( $res as $row ) {
			$title = Title::makeTitle( $row->namespace, $row->title );

			$wlh = SpecialPage::getTitleFor( 'Whatlinkshere' );
			$label = wfMessage( 'wpfromns-links', $row->value )->text();

			$output .= '<li>' . Linker::link( $title, $title->getText(), [], [], [ 'broken' ] ) .
				' (' . Linker::link( $wlh, $label, [], [ 'target' => $title->getPrefixedText() ] ) .
				')' . "</li>\n";

			if(!isset($counts[ $row->namespace ])) {
				$counts[ $row->namespace ] = 0;
			}
			$counts[ $row->namespace ]++;
		}

		foreach ( $counts as $namespace => $count ) {
			$parser->getOutput()->setProperty('count_wanted_' . $namespace, $count);
		}

		if ( $output ) {
			return '<ol>' . $output . "</ol>\n";
		} else {
			// no pages found
			return wfMessage( 'wpfromns-nores' )->escaped();
		}
	}



	public static function renderCount( $input, array $args, Parser $parser, PPFrame $frame ) {
		$f = new NPCEO();
		return $f->parseCount( $input, $parser );
	}
	
	public function parseCount( &$input, &$parser ) {
		$pageId = $parser->getTitle()->getArticleID();
		$this->sInput =& $input;
		
		$parser->getOutput()->updateCacheExpiry( 0 );

		$namespace = $this->get( 'namespace', '', $parser );
		$iNamespace = MediaWikiServices::getInstance()->getContentLanguage()->getNsIndex( $namespace );
		if ( !$iNamespace ) {
			if ( ( $namespace ) || ( $namespace === '0' ) ) {
				$iNamespace = intval( $namespace );
			} else {
				$iNamespace = -1;
			}
		}
		
		if ( $iNamespace < 0 ) {
			return 0;
		}
		
		$propName = "count_wanted_$iNamespace";
		
		$page = $this->get( 'page', null, $parser );
		if ( $page ) { //other page
			$title = Title::newFromText( $page );
			if ( !$title || $title->getArticleID() === 0 ) {
                          	return 0;
              		}
			
			$dbl = MediaWikiServices::getInstance()->getDBLoadBalancer();
			$dbr = $dbl->getConnection( DB_REPLICA );
			$propValue = $dbr->selectField( 'page_props', // table to use
				  'pp_value', // Field to select
				  [ 'pp_page' => $title->getArticleID(), 'pp_propname' => $propName ], // where conditions
				  __METHOD__
			);
		} else { //this page
			$propValue = $parser->getOutput()->getProperty( $propName );
		}
		
		if ( $propValue === false ) {
			return 0;
		}
		
		return $propValue;
		/*if ( !$parser->isValidHalfParsedText( $prop ) ) {
                          // Probably won't ever happen.
                          return 'Invalid';
              	} else {
                          // Everything should be good.
                          return $parser->unserializeHalfParsedText( $prop );
              	}*/
	}	
	
	public static function renderModel( Parser $parser, $param1 = '' ) {
		$f = new NPCEO();
		return $f->parseModel( $parser, $param1 );
	}
	
	public function parseModel( &$parser, $param1 = '' ) {
		$lines = [];
		foreach(explode("\n", $param1) as $line) {
			if(!empty($line = trim($line))) {
				$lines[] = $line;
			}
		}
		return '<span class="npceo-model" style="display: none">' . implode('', $lines) . '</span>';
	}	
}
