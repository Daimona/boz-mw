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

# Network
namespace network;

use cli\Log;
use InvalidArgumentException;

/**
 * HTTP request handler for GET and POST requests.
 */
class HTTPRequest {

	/**
	 * Class version
	 *
	 * To be incremented every time do you notice this number.
	 *
	 * @var string
	 */
	const VERSION = 0.4;

	/**
	 * Source code URL
	 *
	 * @var string
	 */
	const REPO = 'https://github.com/valerio-bozzolan/boz-mw';

	/**
	 * Wait seconds before each GET request.
	 */
	static $WAIT = 0.2;

	/**
	 * Wait seconds before each POST request
	 *
	 * @var float
	 */
	static $WAIT_POST = 0.2;

	/**
	 * Wait seconds before each lag
	 *
	 * @var float
	 */
	const WAIT_DOS  = 5;

	/**
	 * Full HTTP URL to the API endpoint
	 *
	 * @var string
	 */
	protected $api;

	/**
	 * HTTP GET/POST data
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Internal arguments
	 *
	 * @param array
	 */
	private $args;

	/**
	 * Latest HTTP response headers
	 *
	 * @var array
	 */
	private $latestHttpResponseHeaders = [];

	/**
	 * Latest HTTP error status code
	 *
	 * @var Status
	 */
	private $latestHttpResponseStatus;

	/**
	 * HTTP cookies
	 *
	 * @var array
	 */
	private $cookies = [];

	/**
	 * Constructor.
	 *
	 * @param $api string API endpoint
	 * @param $args array Internal arguments
	 */
	public function __construct( $api, $args = [] ) {
		$this->api = $api;
		$this->setArgs( $args );
	}

	/**
	 * Statical constructor.
	 *
	 * @return network\HTTPRequest
	 */
	public static function factory( $api, $args = [] ) {
		return new self( $api, $args );
	}

	/**
	 * Get internal arguments.
	 *
	 * @return array
	 */
	public function getArgs() {
		return $this->args;
	}

	/**
	 * Set internal arguments
	 *
	 * method: GET, POST etc.
	 * user-agent: HTTP user agent
	 * sensitive: flag to indicate if the data is sensitive and should not be printed as usual in the log
	 * wait: microseconds to wait after every GET request
	 * wait-post: microseconds to wait after every POST request
	 * headers: HTTP headers
	 *
	 * @param $args array Internal arguments
	 * @return self
	 */
	public function setArgs( $args ) {
		$this->args = array_replace( [
			'method'     => 'GET',
			'user-agent' => sprintf( 'boz-mw HTTPRequest.php/%s %s', self::VERSION, self::REPO ),
			'sensitive'  => false,
			'wait'       => self::$WAIT,
			'wait-post'  => self::$WAIT_POST,
			'headers'    => [],
		], $args );
		return $this;
	}

	/**
	 * Make an HTTP GET query
	 *
	 * @param $data array GET data
	 * @param $args array Internal arguments
	 * @return mixed Response
	 */
	public function fetch( $data = [], $args = [] ) {

		$args = array_replace( $this->args, $args );

		// Eventually post-process the data before using
		$data = static::onDataReady( $data );

		// HTTP query using file_get_contents()
		$url = $this->api;
		$query = http_build_query( $data );
		$context = [
			'http' => []
		];
		$context['http']['method'] = $args['method'];
		if( $args['user-agent'] ) {
			$args['headers'][] = self::header( 'User-Agent', $args['user-agent'] );
		}

		if( $this->haveCookies() ) {
			$args['headers'][] = $this->getCookieHeader();
		}

		if( $query ) {
			if( 'POST' === $args['method'] ) {
				$args['headers'][] = self::header('Content-Type', 'application/x-www-form-urlencoded' );
				$context['http']['content'] = $query;
			} else {
				$url .= "?$query";
			}
		}

		if( 'GET' === $args['method'] ) {
			Log::debug( "GET $url" );
		} else {
			Log::sensitive( "{$args['method']} $url $query", "{$args['method']} $url" );
		}

		if( $args['headers'] ) {
			$context['http']['header'] = self::implodeHTTPHeaders( $args['headers'] );
		}

		if( $args['wait'] ) {
			Log::debug( sprintf(
				"Waiting %.2f s.",
				$args['wait']
			) );
			usleep( $args['wait'] * 1000000 );
		}

		$stream_context = stream_context_create( $context );
		$response = file_get_contents( $url, false, $stream_context );

		// Here $http_response_header exists magically (PHP merda!)
		if( ! isset( $http_response_header ) ) {
			throw new Exception( 'undefined http_response_header variable: wrong request?' );
		}
		$this->loadHTTPResponseHeaders( $http_response_header );

		// Check the HTTP status
		$status = $this->getLatestHTTPResponseStatus();
		if( ! $status->isOK() ) {
			if( $status->isClientError() ) {
				Log::warn( sprintf(
					"waiting because of HTTP status %s: %s",
					$status->getCode(),
					$status->getMessage()
				) );
				$args = array_replace( [
					'wait' => self::WAIT_DOS
				], $args );
				return $this->fetch( $data, $args );
			}
			throw new NotOKException( $status );
		}

		Log::debug( $status->getHeader() );

		return static::onFetched( $response, $data );
	}

	/**
	 * Effectuate an HTTP POST.
	 *
	 * @param $data array POST data
	 * @param $args Internal arguments
	 * @return mixed Response
	 */
	public function post( $data = [], $args = [] ) {
		$args = array_replace(
			[ 'wait' => $this->args['wait-post'] ],
			$args,
			[ 'method' => 'POST' ]
		);
		return $this->fetch( $data, $args );
	}

	/**
	 * Get latest HTTP response headers.
	 *
	 * @return array
	 */
	public function getLatestHTTPResponseHeaders() {
		return $this->latestHttpResponseHeaders;
	}

	/**
	 * Get latest HTTP status
	 *
	 * @return Status
	 */
	private function getLatestHTTPResponseStatus() {
		return $this->latestHttpResponseStatus;
	}

	/**
	 * Check if cookies are set
	 *
	 * @return bool
	 */
	public function haveCookies() {
		return isset( $this->cookies );
	}

	/**
	 * Get cookies
	 *
	 * @return array
	 */
	public function getCookies() {
		return $this->cookies;
	}

	/**
	 * Set an HTTP cookie name => value pair.
	 *
	 * @param $name string Cookie name
	 * @param $value string Cookie value
	 * @return self
	 */
	public function setCookie( $name, $value ) {
		$this->cookies[ $name ] = $value;
		return $this;
	}

	public function getCookieHeader() {
		$cookies = [];
		foreach( $this->getCookies() as $name => $value ) {
			$cookies[] = urlencode( $name ) . '=' . urlencode( $value );
		}
		return self::header(
			'Cookie',
			implode( '; ', $cookies )
		);
	}

	public function getHTTPCookies() {
		return $this->cookies;
	}

	/**
	 * Set an HTTP cookie in its raw form.
	 *
	 * @param $cookie string Cookie text
	 */
	private function setRawCookie( $cookie ) {
		$parts = explode(';', $cookie);
		if( isset( $parts[0] ) ) {
			$name_value = explode('=', $parts[0] );
			if( 2 === count( $name_value ) ) {
				list( $name, $value ) = $name_value;
				$this->setCookie( $name, $value );
			}
		}
	}

	/**
	 * Load HTTP response headers, filling cookies.
	 */
	private function loadHTTPResponseHeaders( $http_response_headers ) {
		list( $this->latestHttpResponseHeaders, $this->latestHttpResponseStatus )
			= self::parseHTTPResponseHeaders( $http_response_headers );

		if( isset( $this->latestHttpResponseHeaders['Set-Cookie'] ) ) {
			foreach( $this->latestHttpResponseHeaders['Set-Cookie'] as $cookie ) {
				$this->setRawCookie( $cookie );
			}
		}
	}

	/**
	 * Implode HTTP headers
	 *
	 * @param $headers array
	 * @return string
	 */
	private function implodeHTTPHeaders( $headers ) {
		if( ! $headers ) {
			return null;
		}
		$s = '';
		foreach( $headers as $header ) {
			$s .= "$header\r\n";
		}
		return $s;
	}

	/**
	 * Group HTTP headers by keys and get the HTTP Status.
	 *
	 * @param $http_response_headers array
	 * @return array The first element contains an associative array of header name and value(s). The second one contains the Status.
	 */
	private static function parseHTTPResponseHeaders( $http_response_headers ) {
		$status = null;
		$headers = [];
		foreach( $http_response_headers as $header ) {
			// Check if it's an header like 'Foo: bar'
			$header_parts = explode(':', $header, 2);
			if( 2 === count( $header_parts ) ) {
				list( $name, $value ) = $header_parts;
				if( ! isset( $headers[ $name ] ) ) {
					$headers[ $name ] = [];
				}
				$headers[ $name ][] = ltrim( $value );
			} else {
				try {
					$status = Status::createFromHeader( $header );
				} catch( InvalidArgumentException $e ) {
					$headers[ $header ] = true;
				}
			}
		}

		if( null === $status ) {
			throw new \Exception( 'HTTP response without an HTTP status code' );
		}

		return [ $headers, $status ];
	}

	/**
	 * Get an HTTP header string from a name => value pair.
	 *
	 * @param $name string HTTP header name
	 * @param $value string HTTP header value
	 * @return string HTTP header
	 */
	private static function header( $name, $value ) {
		return self::headerRaw( sprintf(
			'%s: %s',
			$name,
			$value
		) );
	}

	/**
	 * This function simply returns a single header
	 *
	 * As you know an header does not contains a line feed or a carriage return.
	 *
	 * @param $name string HTTP header
	 * @return string HTTP header
	 */
	private static function headerRaw( $header ) {
		if( false !== strpos( $header, "\n" ) || false !== strpos( $header, "\r" ) ) {
			Log::warn( "wtf header with line feed or carriage return (header injection?)" );
			Log::debug( $header );
			return str_replace( [ "\n", "\r" ], '', $header );
		}
		return $header;
	}

	/**
	 * Can be overrided to post-process the data before its use
	 *
	 * @param $data GET/POST data
	 * @return array GET/POST data post-processed
	 */
	protected function onDataReady( $data ) {
		return $data;
	}

	/**
	 * Callback to be overloaded
	 *
	 * This is called every time something is fetched.
	 *
	 * @param $response mixed Response
	 * @param $request_data mixed GET/POST data
	 * @return mixed Response
	 */
	protected function onFetched( $response, $request_data ) {
		return $response;
	}
}
