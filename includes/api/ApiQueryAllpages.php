<?php
/**
 *
 *
 * Created on Sep 25, 2006
 *
 * Copyright © 2006 Yuri Astrakhan <Firstname><Lastname>@gmail.com
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * Query module to enumerate all available pages.
 *
 * @ingroup API
 */
class ApiQueryAllpages extends ApiQueryGeneratorBase {

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'ap' );
	}

	public function execute() {
		$this->run();
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	/**
	 * @param $resultPageSet ApiPageSet
	 * @return void
	 */
	public function executeGenerator( $resultPageSet ) {
		if ( $resultPageSet->isResolvingRedirects() ) {
			$this->dieUsage( 'Use "gapfilterredir=nonredirects" option instead of "redirects" when using allpages as a generator', 'params' );
		}

		$this->run( $resultPageSet );
	}

	/**
	 * @param $resultPageSet ApiPageSet
	 * @return void
	 */
	private function run( $resultPageSet = null ) {
		$db = $this->getDB();

		$params = $this->extractRequestParams();

		// Page filters
		$this->addTables( 'page' );

		if ( $params['filterredir'] == 'redirects' ) {
			$this->addWhereFld( 'page_is_redirect', 1 );
		} elseif ( $params['filterredir'] == 'nonredirects' ) {
			$this->addWhereFld( 'page_is_redirect', 0 );
		}

		$this->addWhereFld( 'page_namespace', $params['namespace'] );
		$dir = ( $params['dir'] == 'descending' ? 'older' : 'newer' );
		$from = ( is_null( $params['from'] ) ? null : $this->titlePartToKey( $params['from'] ) );
		$to = ( is_null( $params['to'] ) ? null : $this->titlePartToKey( $params['to'] ) );
		$this->addWhereRange( 'page_title', $dir, $from, $to );

		if ( isset( $params['prefix'] ) ) {
			$this->addWhere( 'page_title' . $db->buildLike( $this->titlePartToKey( $params['prefix'] ), $db->anyString() ) );
		}

		if ( is_null( $resultPageSet ) ) {
			$selectFields = array(
				'page_namespace',
				'page_title',
				'page_id'
			);
		} else {
			$selectFields = $resultPageSet->getPageTableFields();
		}

		$this->addFields( $selectFields );
		$forceNameTitleIndex = true;
		if ( isset( $params['minsize'] ) ) {
			$this->addWhere( 'page_len>=' . intval( $params['minsize'] ) );
			$forceNameTitleIndex = false;
		}

		if ( isset( $params['maxsize'] ) ) {
			$this->addWhere( 'page_len<=' . intval( $params['maxsize'] ) );
			$forceNameTitleIndex = false;
		}

		if ( $params['filterlanglinks'] == 'withoutlanglinks' ) {
			$this->addTables( 'langlinks' );
			$this->addJoinConds( array( 'langlinks' => array( 'LEFT JOIN', 'page_id=ll_from' ) ) );
			$this->addWhere( 'll_from IS NULL' );
			$forceNameTitleIndex = false;
		} elseif ( $params['filterlanglinks'] == 'withlanglinks' ) {
			$this->addTables( 'langlinks' );
			$this->addWhere( 'page_id=ll_from' );
			$this->addOption( 'STRAIGHT_JOIN' );
			// We have to GROUP BY all selected fields to stop
			// PostgreSQL from whining
			$this->addOption( 'GROUP BY', implode( ', ', $selectFields ) );
			$forceNameTitleIndex = false;
		}

		if ( $forceNameTitleIndex ) {
			$this->addOption( 'USE INDEX', array( 'page' => 'name_title' ) );
		}

		$limit = $params['limit'];
		$this->addOption( 'LIMIT', $limit + 1 );

		wfRunHooks( 'APIQueryAllpages::RunAlter', array( $this, $db, $params ) );

		$res = $this->select( __METHOD__ );

		$count = 0;
		$result = $this->getResult();
		foreach ( $res as $row ) {
			if ( ++ $count > $limit ) {
				// We've reached the one extra which shows that there are additional pages to be had. Stop here...
				// TODO: Security issue - if the user has no right to view next title, it will still be shown
				$this->setContinueEnumParameter( 'from', $this->keyToTitle( $row->page_title ) );
				break;
			}

			if ( is_null( $resultPageSet ) ) {
				$title = Title::makeTitle( $row->page_namespace, $row->page_title );
				$vals = array(
					'pageid' => intval( $row->page_id ),
					'ns' => intval( $title->getNamespace() ),
					'title' => $title->getPrefixedText()
				);
				$fit = $result->addValue( array( 'query', $this->getModuleName() ), null, $vals );
				if ( !$fit ) {
					$this->setContinueEnumParameter( 'from', $this->keyToTitle( $row->page_title ) );
					break;
				}
			} else {
				$resultPageSet->processDbRow( $row );
			}
		}

		if ( is_null( $resultPageSet ) ) {
			$result->setIndexedTagName_internal( array( 'query', $this->getModuleName() ), 'p' );
		}
	}

	public function getAllowedParams() {
		return array(
			'from' => null,
			'to' => null,
			'prefix' => null,
			'namespace' => array(
				ApiBase::PARAM_DFLT => 0,
				ApiBase::PARAM_TYPE => 'namespace',
			),
			'filterredir' => array(
				ApiBase::PARAM_DFLT => 'all',
				ApiBase::PARAM_TYPE => array(
					'all',
					'redirects',
					'nonredirects'
				)
			),
			'minsize' => array(
				ApiBase::PARAM_TYPE => 'integer',
			),
			'maxsize' => array(
				ApiBase::PARAM_TYPE => 'integer',
			),
			'limit' => array(
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			),
			'dir' => array(
				ApiBase::PARAM_DFLT => 'ascending',
				ApiBase::PARAM_TYPE => array(
					'ascending',
					'descending'
				)
			),
			'filterlanglinks' => array(
				ApiBase::PARAM_TYPE => array(
					'withlanglinks',
					'withoutlanglinks',
					'all'
				),
				ApiBase::PARAM_DFLT => 'all'
			),
		);
	}

	public function getParamDescription() {
		$p = $this->getModulePrefix();
		return array(
			'from' => 'The page title to start enumerating from',
			'to' => 'The page title to stop enumerating at',
			'prefix' => 'Search for all page titles that begin with this value',
			'namespace' => 'The namespace to enumerate',
			'filterredir' => 'Which pages to list',
			'dir' => 'The direction in which to list',
			'minsize' => 'Limit to pages with at least this many bytes',
			'maxsize' => 'Limit to pages with at most this many bytes',
			'filterlanglinks' => 'Filter based on whether a page has langlinks',
			'limit' => 'How many total pages to return.',
		);
	}

	public function getDescription() {
		return 'Enumerate all pages sequentially in a given namespace';
	}

	public function getPossibleErrors() {
		return array_merge( parent::getPossibleErrors(), array(
			array( 'code' => 'params', 'info' => 'Use "gapfilterredir=nonredirects" option instead of "redirects" when using allpages as a generator' ),
		) );
	}

	public function getExamples() {
		return array(
			'api.php?action=query&list=allpages&apfrom=B' => array(
				'Simple Use',
				'Show a list of pages starting at the letter "B"',
			),
			'api.php?action=query&generator=allpages&gaplimit=4&gapfrom=T&prop=info' => array(
				'Using as Generator',
				'Show info about 4 pages starting at the letter "T"',
			),
			'api.php?action=query&generator=allpages&gaplimit=2&gapfilterredir=nonredirects&gapfrom=Re&prop=revisions&rvprop=content' => array(
				'Show content of first 2 non-redirect pages begining at "Re"',
			)
		);
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/API:Allpages';
	}

	public function getVersion() {
		return __CLASS__ . ': $Id$';
	}
}