<?php
/* Copyright (C) 2026 Michel L'Hostis <michel.lhostis@iseffi.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       markautomecanik/class/webmecanikclient.class.php
 * \ingroup    markautomecanik
 * \brief      Hardened HTTP client for the Webmecanik Automation (Mautic) REST API
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';

/**
 * Client for the Webmecanik Automation REST API.
 *
 * Security measures:
 * - HTTPS only (protocol restricted at cURL level), certificate and host name verified;
 * - no HTTP redirect followed (prevents credential leaks to another host);
 * - secrets stored encrypted in database (dolEncrypt) and never written to logs;
 * - response size and duration bounded;
 * - only paths under /api/ of the configured instance can be called;
 * - personal data contained in responses is never logged.
 */
class MarkAutoMecanikWebmecanikClient
{
	/** Maximum accepted response size in bytes */
	const MAX_RESPONSE_SIZE = 20971520;

	/** @var DoliDB */
	protected $db;

	/** @var string Base URL of the instance, without trailing slash */
	protected $baseUrl = '';

	/** @var string 'oauth2' or 'basic' */
	protected $authMode = 'oauth2';

	/** @var string */
	protected $clientId = '';

	/** @var string */
	protected $clientSecret = '';

	/** @var string */
	protected $basicUser = '';

	/** @var string */
	protected $basicPassword = '';

	/** @var string|null OAuth2 access token kept in memory only */
	protected $accessToken = null;

	/** @var int Expiry timestamp of the access token */
	protected $accessTokenExpiry = 0;

	/** @var string Last error (safe to display: contains no secret and no personal data) */
	public $error = '';

	/** @var int Last HTTP code */
	public $lastHttpCode = 0;

	/**
	 * Constructor: loads the configuration of the current entity
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->baseUrl = self::normalizeBaseUrl(getDolGlobalString('MARKAUTOMECANIK_WM_URL'));
		$this->authMode = (getDolGlobalString('MARKAUTOMECANIK_WM_AUTH', 'oauth2') === 'basic' ? 'basic' : 'oauth2');
		$this->clientId = getDolGlobalString('MARKAUTOMECANIK_WM_CLIENT_ID');
		$this->clientSecret = self::decryptSecret(getDolGlobalString('MARKAUTOMECANIK_WM_CLIENT_SECRET'));
		$this->basicUser = getDolGlobalString('MARKAUTOMECANIK_WM_BASIC_USER');
		$this->basicPassword = self::decryptSecret(getDolGlobalString('MARKAUTOMECANIK_WM_BASIC_PASSWORD'));
	}

	/**
	 * Encrypt a secret before storing it in llx_const
	 *
	 * @param string $value Clear value
	 * @return string
	 */
	public static function encryptSecret($value)
	{
		if ($value === '' || $value === null) {
			return '';
		}
		return dolEncrypt($value);
	}

	/**
	 * Decrypt a secret read from llx_const (values stored in clear by an older setup are returned as is)
	 *
	 * @param string $value Stored value
	 * @return string
	 */
	public static function decryptSecret($value)
	{
		if ($value === '' || $value === null) {
			return '';
		}
		if (strpos($value, 'dolcrypt:') === 0) {
			return (string) dolDecrypt($value);
		}
		return $value;
	}

	/**
	 * Validate and normalise the base URL of the instance.
	 * Only absolute https URLs without credentials, query string or fragment are accepted.
	 *
	 * @param string $url URL
	 * @return string Normalised URL, or empty string if invalid
	 */
	public static function normalizeBaseUrl($url)
	{
		$url = trim((string) $url);
		if ($url === '' || strlen($url) > 255) {
			return '';
		}
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return '';
		}
		$parts = parse_url($url);
		if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
			return '';
		}
		if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			return '';
		}
		$path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
		if ($path !== '' && !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path)) {
			return '';
		}
		return 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.((int) $parts['port']) : '').$path;
	}

	/**
	 * Base URL of the instance
	 *
	 * @return string
	 */
	public function getBaseUrl()
	{
		return $this->baseUrl;
	}

	/**
	 * Check that the connector is fully configured
	 *
	 * @return bool
	 */
	public function isConfigured()
	{
		if (empty($this->baseUrl)) {
			$this->error = 'Webmecanik URL is not configured or is not a valid https URL';
			return false;
		}
		if ($this->authMode === 'basic') {
			if ($this->basicUser === '' || $this->basicPassword === '') {
				$this->error = 'Webmecanik basic authentication credentials are not configured';
				return false;
			}
		} elseif ($this->clientId === '' || $this->clientSecret === '') {
			$this->error = 'Webmecanik OAuth2 client id / secret are not configured';
			return false;
		}
		return true;
	}

	/**
	 * Low level HTTPS call
	 *
	 * @param string      $method  GET, POST, PATCH
	 * @param string      $url     Absolute URL (must start with the configured base URL)
	 * @param array       $headers HTTP headers
	 * @param string|null $body    Raw body
	 * @param string|null $userpwd user:password for basic authentication
	 * @return array|false array('code' => int, 'body' => string) or false on transport error
	 */
	protected function httpCall($method, $url, $headers = array(), $body = null, $userpwd = null)
	{
		if (strpos($url, $this->baseUrl.'/') !== 0) {
			$this->error = 'Refused to call a URL outside of the configured Webmecanik instance';
			return false;
		}
		if (!function_exists('curl_init')) {
			$this->error = 'PHP cURL extension is required';
			return false;
		}

		$buffer = '';
		$max = self::MAX_RESPONSE_SIZE;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
		if (defined('CURLOPT_PROTOCOLS')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		}
		if (defined('CURLOPT_REDIR_PROTOCOLS')) {
			curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
		}
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Dolibarr-MarkAutoMecanik/1.0');
		curl_setopt($ch, CURLOPT_ENCODING, '');
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		if ($userpwd !== null) {
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
		}
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$buffer, $max) {
			if (strlen($buffer) + strlen($chunk) > $max) {
				return 0; // aborts the transfer
			}
			$buffer .= $chunk;
			return strlen($chunk);
		});

		$ok = curl_exec($ch);
		$errno = curl_errno($ch);
		$errmsg = curl_error($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
		curl_close($ch);

		$this->lastHttpCode = $code;
		$path = (string) parse_url($url, PHP_URL_PATH);
		if ($ok === false || $errno) {
			$this->error = 'Network error when calling Webmecanik (cURL #'.$errno.': '.$errmsg.')';
			dol_syslog(__METHOD__.' '.$method.' '.$path.' '.$this->error, LOG_ERR);
			return false;
		}
		// Only the path, the HTTP code and the size are logged: never the query, the body or the response
		dol_syslog(__METHOD__.' '.$method.' '.$path.' -> HTTP '.$code.' ('.strlen($buffer).' bytes, '.round($time, 2).'s)', LOG_DEBUG);

		return array('code' => $code, 'body' => $buffer);
	}

	/**
	 * Get an OAuth2 access token (client credentials grant)
	 *
	 * @param bool $force Ignore the cached token
	 * @return string|null
	 */
	protected function getAccessToken($force = false)
	{
		if (!$force && $this->accessToken !== null && $this->accessTokenExpiry > time() + 30) {
			return $this->accessToken;
		}
		$this->accessToken = null;

		$res = $this->httpCall(
			'POST',
			$this->baseUrl.'/oauth/v2/token',
			array('Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'),
			http_build_query(array(
				'grant_type' => 'client_credentials',
				'client_id' => $this->clientId,
				'client_secret' => $this->clientSecret,
			), '', '&')
		);
		if ($res === false) {
			return null;
		}
		if ($res['code'] !== 200) {
			$this->error = 'Webmecanik OAuth2 authentication failed (HTTP '.$res['code'].'): check URL, client id and client secret';
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return null;
		}
		$data = json_decode($res['body'], true);
		if (!is_array($data) || empty($data['access_token']) || !is_string($data['access_token'])) {
			$this->error = 'Webmecanik OAuth2 response does not contain an access token';
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return null;
		}
		$this->accessToken = $data['access_token'];
		$this->accessTokenExpiry = time() + (isset($data['expires_in']) ? max(60, (int) $data['expires_in']) : 3600);
		return $this->accessToken;
	}

	/**
	 * Call the REST API
	 *
	 * @param string     $method GET, POST or PATCH
	 * @param string     $path   Path starting with /api/
	 * @param array      $query  Query string parameters
	 * @param array|null $data   JSON body
	 * @return array|null Decoded response, null on error (see $this->error)
	 */
	public function request($method, $path, $query = array(), $data = null)
	{
		$this->error = '';
		$method = strtoupper($method);
		if (!in_array($method, array('GET', 'POST', 'PATCH'), true)) {
			$this->error = 'Unsupported HTTP method';
			return null;
		}
		if (!preg_match('#^/api/[A-Za-z0-9/_-]+$#', $path)) {
			$this->error = 'Invalid API path';
			return null;
		}
		if (!$this->isConfigured()) {
			return null;
		}

		$url = $this->baseUrl.$path;
		if (!empty($query)) {
			$url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		}
		$body = ($data !== null ? json_encode($data) : null);

		for ($attempt = 1; $attempt <= 2; $attempt++) {
			$headers = array('Accept: application/json');
			if ($body !== null) {
				$headers[] = 'Content-Type: application/json';
			}
			$userpwd = null;
			if ($this->authMode === 'basic') {
				$userpwd = $this->basicUser.':'.$this->basicPassword;
			} else {
				$token = $this->getAccessToken($attempt > 1);
				if ($token === null) {
					return null;
				}
				$headers[] = 'Authorization: Bearer '.$token;
			}

			$res = $this->httpCall($method, $url, $headers, $body, $userpwd);
			if ($res === false) {
				return null;
			}
			// Expired OAuth2 token: get a new one and retry once
			if ($res['code'] === 401 && $this->authMode === 'oauth2' && $attempt === 1) {
				continue;
			}
			break;
		}

		$code = $res['code'];
		if ($code === 401) {
			$this->error = 'Webmecanik API: authentication refused (HTTP 401)';
		} elseif ($code === 403) {
			$this->error = 'Webmecanik API: access denied (HTTP 403), check the permissions of the API user / client';
		} elseif ($code === 404) {
			$this->error = 'Webmecanik API: resource not found (HTTP 404)';
		} elseif ($code === 429) {
			$this->error = 'Webmecanik API: rate limit reached (HTTP 429), retry later';
		} elseif ($code >= 500) {
			$this->error = 'Webmecanik API: server error (HTTP '.$code.')';
		} elseif ($code < 200 || $code >= 300) {
			$this->error = 'Webmecanik API: unexpected HTTP code '.$code;
		}
		if ($this->error) {
			dol_syslog(__METHOD__.' '.$method.' '.$path.' '.$this->error, ($code === 429 ? LOG_WARNING : LOG_ERR));
			return null;
		}

		$decoded = json_decode($res['body'], true);
		if (!is_array($decoded)) {
			$this->error = 'Webmecanik API: invalid JSON response ('.json_last_error_msg().')';
			dol_syslog(__METHOD__.' '.$method.' '.$path.' '.$this->error, LOG_ERR);
			return null;
		}
		if (!empty($decoded['errors'])) {
			$this->error = 'Webmecanik API returned an error';
			dol_syslog(__METHOD__.' '.$method.' '.$path.' '.$this->error, LOG_ERR);
			return null;
		}
		return $decoded;
	}

	/**
	 * List contacts
	 *
	 * @param array  $where  Mautic "where" filters: array of array('col' =>, 'expr' =>, 'val' =>)
	 * @param int    $start  Offset
	 * @param int    $limit  Page size (max 200)
	 * @param string $search Mautic search string (e.g. "segment:my-segment")
	 * @return array|null array('total' => int, 'contacts' => array)
	 */
	public function getContacts($where = array(), $start = 0, $limit = 100, $search = '')
	{
		$query = array(
			'start' => max(0, (int) $start),
			'limit' => min(200, max(1, (int) $limit)),
			'orderBy' => 'date_modified',
			'orderByDir' => 'ASC',
		);
		$i = 0;
		foreach ($where as $filter) {
			$query['where['.$i.'][col]'] = $filter['col'];
			$query['where['.$i.'][expr]'] = $filter['expr'];
			$query['where['.$i.'][val]'] = $filter['val'];
			$i++;
		}
		if ($search !== '') {
			$query['search'] = $search;
		}
		$res = $this->request('GET', '/api/contacts', $query);
		if ($res === null) {
			return null;
		}
		$contacts = (isset($res['contacts']) && is_array($res['contacts'])) ? $res['contacts'] : array();
		return array('total' => (int) (isset($res['total']) ? $res['total'] : count($contacts)), 'contacts' => $contacts);
	}

	/**
	 * Get one contact
	 *
	 * @param int $id Contact id
	 * @return array|null
	 */
	public function getContact($id)
	{
		$id = (int) $id;
		if ($id <= 0) {
			$this->error = 'Invalid contact id';
			return null;
		}
		$res = $this->request('GET', '/api/contacts/'.$id);
		if ($res === null || empty($res['contact']) || !is_array($res['contact'])) {
			return null;
		}
		return $res['contact'];
	}

	/**
	 * Add / remove tags on a contact (a tag prefixed by "-" is removed)
	 *
	 * @param int   $id   Contact id
	 * @param array $tags Tags
	 * @return bool
	 */
	public function setContactTags($id, $tags)
	{
		$id = (int) $id;
		$clean = array();
		foreach ($tags as $tag) {
			$tag = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $tag);
			if ($tag !== '' && $tag !== '-') {
				$clean[] = $tag;
			}
		}
		if ($id <= 0 || empty($clean)) {
			return false;
		}
		return ($this->request('PATCH', '/api/contacts/'.$id.'/edit', array(), array('tags' => $clean)) !== null);
	}

	/**
	 * Test the connection and the permissions
	 *
	 * @return int|false Number of contacts visible by the API user, false on error
	 */
	public function testConnection()
	{
		$res = $this->request('GET', '/api/contacts', array('limit' => 1, 'minimal' => 'true'));
		if ($res === null) {
			return false;
		}
		return (int) (isset($res['total']) ? $res['total'] : 0);
	}
}
