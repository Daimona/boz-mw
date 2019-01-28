<?php
# Boz-MW - Another MediaWiki API handler in PHP
# Copyright (C) 2017, 2018 Valerio Bozzolan
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program. If not, see <http://www.gnu.org/licenses/>.

# MediaWiki
namespace mw;

/**
 * A MediaWiki wikitext
 */
class Wikitext {

	/**
	 * MediaWiki site
	 *
	 * @var mw\Site
	 */
	private $site;

	/**
	 * Complete plain text wikitext
	 *
	 * @var string
	 */
	private $wikitext;

	/**
	 * String appended to the wikitext
	 *
	 * @var string
	 */
	private $appended = '';

	/**
	 * String prepended to the wikitext
	 *
	 * @var string
	 */
	private $prepended = '';

	/**
	 * Strings sobstituted from the wikitext
	 *
	 * @var array [ [ 'a', 'b' ], [ 'b', 'c' ] ]
	 */
	private $sobstitutions = [];

	/**
	 * Constructor
	 *
	 * @param $site mw\Site MediaWiki site
	 * @param $wikitext string Wikitext
	 */
	public function __construct( $site, $wikitext ) {
		$this->setWikitext( $wikitext );
		$this->setSite( $site );
	}

	/**
	 * Get the MediaWiki site
	 *
	 * @return mw\Site
	 */
	public function getSite() {
		return $this->site;
	}

	/**
	 * Get the complete wikitext
	 *
	 * @return string
	 */
	public function getWikitext() {
		return $this->wikitext;
	}

	/**
	 * Set the MediaWiki site
	 *
	 * @return self
	 */
	public function setSite( $site ) {
		$this->site = $site;
		return $this;
	}

	/**
	 * Set the wikitext
	 *
	 * @param $wikitext string
	 * @return self
	 */
	public function setWikitext( $wikitext ) {
		$this->wikitext = $wikitext;
		return $this;
	}

	/**
	 * Append some wikitext
	 *
	 * @param $wikitext string
	 * @return self
	 */
	public function appendWikitext( $wikitext ) {
		$this->appended .= $wikitext;
		$this->setWikitext( $this->getWikitext() . $wikitext );
		return $this;
	}

	/**
	 * Prepend some wikitext
	 *
	 * @param $wikitext string
	 * @return self
	 */
	public function prependWikitext( $wikitext ) {
		$this->prepended .= $wikitext;
		$this->setWikitext( $wikitext . $this->getWikitext() );
		return $this;
	}

	/**
	 * Run a preg_match() on the wikitext
	 *
	 * @param $pattern string Pattern
	 * @param $matches array Matches
	 * @param $flags int
	 * @param $offset int
	 * @see preg_match()
	 */
	public function pregMatch( $pattern, & $matches = [], $flags = 0, $offset = 0 ) {
		return preg_match( $pattern, $this->getWikitext(), $matches, $flags, $offset );
	}

	/**
	 * Run a preg_match_all() on the wikitext
	 *
	 * @param $pattern string Pattern
	 * @param $matches array Matches
	 * @param $flags int
	 * @param $offset int
	 * @see preg_match_all()
	 * @return int|false
	 */
	public function pregMatchAll( $pattern, & $matches = [], $flags = 0, $offset = 0 ) {
		return preg_match_all( $pattern, $this->getWikitext(), $matches, $flags, $offset );
	}

	/**
	 * Run a preg_replace() on the wikitext
	 *
	 * @param $patterns string|array Pattern
	 * @param $replacement mixed Replacement where you can use group placeholders such as $0, $1 or ${0}, ${1}, ecc.
	 * @param $limit int Sobstitution limit
	 * @param $count int Sobstitution count
	 * @see preg_replace()
	 */
	public function pregReplace( $patterns, $replacement, $limit = -1, &$count = 0 ) {
		// note that preg_replace() supports $pattern as array, but pregMatchAll() requires a string, so let's loop
		if( ! is_array( $patterns ) ) {
			$patterns = [ $patterns ];
		}
		foreach( $patterns as $pattern ) {
			$n = $this->pregMatchAll( $pattern, $matches );
			if( $n ) {
				$groups = count( $matches );
				for( $i = 0; $i < $n; $i++ ) {
					$from = $matches[ 0 ][ $i ];
					$to = $replacement;
					foreach( $matches as $group_name => $groups ) {
						$to = str_replace( [
							'\\' . $group_name,      // \\1
							'$'  . $group_name,      // $1
							'${' . $group_name . '}' // ${1}
						], $groups[ $i ], $to );
					}
					$this->sobstitutions[] = [ $from, $to ];
				}
			}
		}
		$this->setWikitext( preg_replace( $patterns, $replacement, $this->getWikitext(), $limit, $count ) );
		return $this;
	}

	/**
	 * Search and replace all the occurrences of a string from the wikitext
	 *
	 * @param $a mixed Search
	 * @param $b mixed Replace
	 * @param $count int Sobstitution count
	 * @return self
	 */
	public function strReplace( $a, $b, & $count = 0 ) {
		$this->setWikitext( str_replace( $a, $b, $this->getWikitext(), $count ) );
		for( $i = 0; $i < $count; $i++ ) {
			$this->sobstitutions[] = [ $a, $b ];
		}
		return $this;
	}

	/**
	 * It has a Category? (it checks only the wikitext)
	 *
	 * @param $category_name string Category without namespace prefix
	 * @return bool
	 */
	public function hasCategory( $category_name ) {
		$category_name_regex = Title::factory( $category_name )->getRegexFirstCaseInsensitive();
		$category_name_regex = \regex\Generic::spaceBurger( $category_name_regex );

		$category_ns_titleparts = $this->getSite()->getNamespace( 14 )->getAllTitlePartsCapitalized();
		$category_ns_regexes = [];
		foreach( $category_ns_titleparts as $category_ns_titlepart ) {
			$category_ns_regexes[] = $category_ns_titlepart->getRegexFirstCaseInsensitive();
		}

		$whatever_sortkey = '(\|.*?)?';
		foreach( $category_ns_regexes as $category_ns_regex ) {
			$category_ns_regex = \regex\Generic::spaceBurger( $category_ns_regex );
			$pattern = sprintf(
				'/\[\[%s\]\]/',
				$category_ns_regex . ':' . $category_name_regex . $whatever_sortkey
			);
			if( 1 === $this->pregMatch( $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add a Category
	 *
	 * @param $category_name string Category name without category prefix
	 * @param $sortkey string
	 * @return string
	 */
	public function addCategory( $category_name, $sortkey = null ) {
		if( $this->hasCategory( $category_name ) ) {
			return false;
		}
		$category_namespace = $this->getSite()->getNamespace( 14 )->getName();
		$this->appendWikitext( sprintf(
			"\n[[%s:%s%s]]",
			$category_namespace,
			$category_name,
			$sortkey ? "|$sortkey" : ''
		) );
		return true;
	}

	/**
	 * Get the prepended wikitext
	 *
	 * @return string
	 */
	public function getPrepended() {
		return $this->prepended;
	}

	/**
	 * Get the appended wikitext
	 *
	 * @return string
	 */
	public function getAppended() {
		return $this->appended;
	}

	/**
	 * Get the sobstituted wikitext
	 *
	 * @return array [ [ 'a', 'b' ], [ 'b', 'c' ] ]
	 */
	public function getSobstitutions() {
		return $this->sobstitutions;
	}

	/**
	 * Count the sobstitutions in the wikitext
	 *
	 * @return int
	 */
	public function countSobstitutions() {
		return count( $this->getSobstitutions() );
	}

	/**
	 * Check if there were changes since creation
	 *
	 * @return bool
	 */
	public function isChanged() {
		return $this->countSobstitutions() || $this->prepended || $this->appended;
	}

	/**
	 * Get the sobstituted wikitext without repetitions
	 *
	 * It also count the repetitions
	 *
	 * @return array [ [ 'a', 'b', 1 ], [ 'b', 'c', 2 ] ]
	 */
	public function getUniqueSobstitutions() {
		$seen = [];
		foreach( $this->getSobstitutions() as $sobstitution ) {
			list( $a, $b ) = $sobstitution;
			@$seen[ $a ][ $b ]++;
		}
		$sobstitutions = [];
		foreach( $seen as $a => $_ ) {
			foreach( $_ as $b => $n ) {
				$sobstitutions[] = [ $a, $b, $n ];
			}
		}
		return $sobstitutions;
	}

	/**
	 * Get a boring array of human sobstitution messages
	 *
	 * @return array
	 */
	public function getHumanUniqueSobstitutions() {
		$things = [];
		foreach( $this->getUniqueSobstitutions() as $abn ) {
			list( $a, $b, $n ) = $abn;
			$many = 1 === $n
				? "1 time"
				: sprintf( "$n times", $n );
			$things[] = sprintf( "%s → %s (%s)", $a, $b, $many );
		}
		return $things;
	}
}
