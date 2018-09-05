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

# Wikibase
namespace wb;

/**
 * A Claim consists of a Snak and Qualifiers.
 *
 * Optionally, it can have qualifiers.
 *
 * @see https://www.wikidata.org/wiki/Wikidata:Glossary#Claim
 */
class Claim {

	/**
	 * Snak
	 *
	 * @var Snak|null
	 */
	var $mainsnak;

	//var $id;

	//var $qualifiers;

	/**
	 * Constructor
	 *
	 * @param $mainsnak Snak|null Main snak
	 */
	public function __construct( $mainsnak = null ) {
		$this->setMainsnak( $mainsnak );
	}

	/**
	 * Get the mainsnak
	 *
	 * @return Snak|null
	 */
	public function getMainsnak() {
		return $this->mainsnak;
	}

	/**
	 * Get the mainsnak
	 *
	 * @return Snak|null
	 */
	public function hasQualifiers() {
		return ! empty( $this->qualifiers );
	}

	/**
	 * Get the qualifiers
	 */
	public function getQualifiers() {
		return $this->qualifiers;
	}

	/**
	 * Set the mainsnak
	 *
	 * @param $mainsnak Snak|null
	 * @return self
	 */
	public function setMainsnak( $mainsnak ) {
		$this->mainsnak = $mainsnak;
		return $this;
	}

	/**
	 * Set the qualifiers
	 *
	 * @param $qualifiers
	 * @return self
	 */
	public function setQualifiers( $qualifiers ) {
		$this->qualifiers = $qualifiers;
		return $this;
	}

	/**
	 * Set the claim ID
	 *
	 * @param $id string
	 * @return string
	 */
	public function setID( $id ) {
		$this->id = $id;
		return $this;
	}

	/**
	 * Get the claim ID
	 *
	 * @return string
	 */
	public function getID() {
		if( empty( $this->id ) ) {
			throw new \Exception( 'missing id' );
		}
		return $this->id;
	}

	/**
	 * Check if this claim is marked for removal
	 *
	 * @return array
	 */
	public function isMarkedForRemoval() {
		return isset( $this->removed ) && $this->removed;
	}

	/**
	 * Mark this claim as to be removed
	 *
	 * @see https://www.wikidata.org/w/api.php?action=help&modules=wbeditentity
	 * @return self
	 */
	public function markForRemoval() {
		$this->removed = 1;
		return $this;
	}

	/**
	 * Clone this claim and obtain a claim marked for removal
	 *
	 * @return self
	 */
	public function cloneForRemoval() {
		return ( new self() )
			->setID( $this->getID() )
			->markForRemoval();
	}

	/**
	 * Create a claim from raw data
	 *
	 * @param $data array
	 * @return self
	 */
	public static function createFromData( $data ) {
		if( ! isset( $data['mainsnak'] ) ) {
			throw new WrongDataException( __CLASS__ );
		}
		$claim = new static( Snak::createFromData( $data['mainsnak'] ) );
		if( isset( $data['qualifiers'] ) ) {
			$claim->setQualifiers( $data['qualifiers'] );
		}
		return $claim;
	}
}
