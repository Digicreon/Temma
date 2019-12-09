<?php

/**
 * Session
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2010-2019, Amaury Bouchard
 */

namespace Temma\Base;

use \Temma\Base\Log as TµLog;

/**
 * Session management object.
 *
 * This object should be called as soon as possible during the process of a web
 * application. It creates a session ID which is stored on the client side in
 * a cookie. If a session ID was already existing in cookie, the session is
 * fetched from this ID, and a new session ID is created and stored in cookie.
 *
 *
 * Example of a script which creates a session and store a session variable:
 * <code>
 * // creation of the session object
 * $session = \Temma\Base\Session::factory($cache);
 *
 * // store a data in session (two equivalent syntaxes)
 * $session->set('foo', 'bar');
 * $session['foo'] = 'bar';
 * </code>
 *
 * Example of a script which fetch the data back, and then delete all
 * session data:
 * <code>
 * $session = \Temma\Base\Session::factory($cache);
 *
 * // get the session data (two equivalent syntaxes)
 * $foo = $session->get('foo');
 * $foo = $session['foo'];
 *
 * // delete one session data (two equivalent syntaxes)
 * $session->set('foo', null);
 * unset($session['foo']);
 *
 * // delete all session data
 * $session->clean();
 * </code>
 */
class Session implements \ArrayAccess {
	/** Constant: Short session duration (1 day). */
	const SHORT_DURATION = 86400;
	/** Constant: Medium session duration (1 month). */
	const MEDIUM_DURATION = 2592000;
	/** Constant: Long session duration (1 year). */
	const LONG_DURATION = 31536000;
	/** Cache management object. */
	private $_cache = null;
	/** Name of the session cookie. */
	private $_cookieName = null;
	/** Session duration. */
	private $_duration = null;
	/** Associative array of session data. */
	private $_data = null;
	/** Session identifier. */
	private $_sessionId = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Factory.
	 * @param	\Temma\Base\Datasource	$cache		(optional) Cache management object.
	 * @param	string			$cookieName	(optional) Name of the session cookie. "TEMMA_SESSION" by default.
	 * @param	int			$duration	(optional) Session duration. One year by default.
	 * @param	int			$renewDelay	(optional) Delay before session ID renewal. 20 minutes by default.
	 * @return	\Temma\Base\Session	The created instance.
	 */
	static public function factory(?\Temma\Base\Datasource $cache=null, string $cookieName='TEMMA_SESSION', int $duration=31536000, int $renewDelay=1200) : \Temma\Base\Session {
		return (new \Temma\Base\Session($cache, $cookieName, $duration, $renewDelay));
	}
	/**
	 * Constructor.
	 * @param	\Temma\Base\Datasource	$cache		(optional) Cache management object.
	 * @param	string			$cookieName	(optional) Name of the session cookie. "TEMMA_SESSION" by default.
	 * @param	int			$duration	(optional) Session duration. One year by default.
	 * @param	int			$renewDelay	(optional) Delay before session ID renewal. 20 minutes by default.
	 */
	private function __construct(?\Temma\Base\Datasource $cache=null, ?string $cookieName=null, ?int $duration=null, ?int $renewDelay=null) {
		TµLog::log('Temma/Base', 'DEBUG', "Session object creation.");
		// fetch the cache
		if (isset($cache) && $cache->isEnabled())
			$this->_cache = clone $cache;
		// if the cache is not active, use the standard PHP session engine
		if (!isset($this->_cache)) {
			session_start();
			return;
		}
		$this->_data = [];
		$this->_cookieName = $cookieName;
		// search for the session ID in the received cookies
		$oldSessionId = isset($_COOKIE[$cookieName]) ? $_COOKIE[$cookieName] : null;
		$this->_sessionId = $oldSessionId;
		// creation of the new session ID
		$newSessionId = hash('md5', time() . mt_rand(0, 0xffff) . mt_rand(0, 0xffff) . mt_rand(0, 0xffff) . mt_rand(0, 0xffff));
		// fetch session data
		if (empty($oldSessionId)) {
			$this->_sessionId = $newSessionId;
		} else {
			// get data from cache
			$data = $this->_cache->get("sess:$oldSessionId");
			if (isset($data['_magic']) && $data['_magic'] == 'Ax')
				$this->_data = $data['data'] ?? null;
			else {
				unset($_COOKIE[$cookieName]);
				$newSessionId = $this->_sessionId;
			}
		}
		$this->_sessionId = $newSessionId;
		// compute expiration date
		if (!isset($this->_data))
			$duration = self::SHORT_DURATION;
		$this->_duration = $duration;
		$timestamp = time() + $duration;
		$expiration = date('Y-m-d H-i-s', $timestamp);
		// store the session ID in cookie
		if (!preg_match("/[^.]+\.[^.]+$/", $_SERVER['HTTP_HOST'], $matches))
			$host = $_SERVER['HTTP_HOST'];
		else
			$host = $matches[0];
		if (!isset($_COOKIE[$cookieName]) || empty($_COOKIE[$cookieName]) || $this->_sessionId != $oldSessionId && !headers_sent()) {
			TµLog::log('Temma/Base', 'DEBUG', "Send cookie '$cookieName' - '$newSessionId' - '$timestamp' - '.$host'");
			// send the cookie
			if (PHP_VERSION_ID < 70300) {
				// PHP < 7.3: use a hack to send the 'samesite' attribute
				setcookie($cookieName, $newSessionId, $timestamp, '/; samesite=Lax', ".$host", false);
			} else {
				// PHP >= 7.3: use an associative array
				setcookie($cookieName, $newSessionId, [
					'expires'	=> $timestamp,
					'path'		=> '/',
					'domain'	=> ".$host",
					'secure'	=> false,
					'httponly'	=> false,
					'samesite'	=> 'Lax'
				]);
			}
		}
	}

	/* ********** MANAGEMENT ********** */
	/** Delete all data in the current session. */
	public function clean() : void {
		TµLog::log('Temma/Base', 'DEBUG', "Cleaning session.");
		if (!isset($this->_cache)) {
			// standard PHP session
			foreach ($_SESSION as $key => $val)
				unset($_SESSION[$key]);
			return;
		}
		// reset internal array
		unset($this->_data);
		$this->_data = [];
		// delete cache data
		$this->_cache->set('sess:' . $this->_sessionId, null);
	}
	/** Delete the current session. */
	public function remove() : void {
		TµLog::log('Temma/Base', 'DEBUG', "Removing current session.");
		if (!isset($this->_cache)) {
			// standard PHP session
			foreach ($_SESSION as $key => $val)
				unset($_SESSION[$key]);
			return;
		}
		// delete the session
		$this->_cache->set('sess:' . $this->_sessionId, null);
		// reset internal variables
		unset($this->_data);
		$this->_data = null;
	}
	/**
	 * Return the current session's identifier.
	 * @return	string	The session ID.
	 */
	public function getSessionId() : string {
		if (!isset($this->_cache))
			return (session_id());
		return ($this->_sessionId);
	}

	/* ********** DATA MANAGEMENT ********** */
	/**
	 * Add a data in session.
	 * @param	string	$key	Data name.
	 * @param	mixed	$value	(optional) Data value. The data is removed if the value is null. Null by default.
	 * @link	http://php.net/manual/function.serialize.php
	 */
	public function set(string $key, /* mixed */ $value=null) : void {
		TµLog::log('Temma/Base', 'DEBUG', "Setting value for key '$key'.");
		if (!isset($this->_cache)) {
			if (is_null($value))
				unset($_SESSION[$key]);
			else
				$_SESSION[$key] = $value;
			return;
		}
		// update internal array
		if (is_null($value))
			unset($this->_data[$key]);
		else
			$this->_data[$key] = $value;
		// data sync
		$cacheData = [
			'_magic'	=> 'Ax',
			'data'		=> $this->_data
		];
		$this->_cache->set('sess:' . $this->_sessionId, $cacheData, $this->_duration);
	}
	/**
	 * Add data in session, array-like syntax.
	 * @param	string	$key	Data name.
	 * @param	mixed	$value	Data value. The data is removed if the value is null.
	 */
	public function offsetSet(/* mixed */ $key, /* mixed */ $value) : void {
		$this->set($key, $value);
	}
	/**
	 * Remove data from cache, array-like syntax.
	 * @param	string	$key	Data name.
	 */
	public function offsetUnset(/* mixed */ $key) : void {
		$this->set($key, null);
	}
	/**
	 * Store a data from an associative array in session.
	 * The array is created if it doesn't exist.
	 * @param	string	$key		Name of the session variable.
	 * @param	string	$arrayKey	Name of the associative array key.
	 * @param	mixed	$value		(optional) Data value. The data is removed if the value is null. Null by default.
	 */
	public function setArray(string $key, string $arrayKey, /* mixed */ $value=null) : void {
		TµLog::log('Temma/Base', 'DEBUG', "Setting value for array '$key\[$arrayKey\]'.");
		if (!isset($this->_cache)) {
			if (is_null($value)) {
				if (isset($_SESSION[$key][$arrayKey]))
					unset($_SESSION[$key][$arrayKey]);
			} else {
				if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key]))
					$_SESSION[$key] = [];
				$_SESSION[$key][$arrayKey] = $value;
			}
			return;
		}
		// update the internal array
		if (is_null($value)) {
			if (isset($this->_data[$key][$arrayKey]))
				unset($this->_data[$key][$arrayKey]);
		} else {
			if (!isset($this->_data[$key]) || !is_array($this->_data[$key]))
				$this->_data[$key] = [];
			$this->_data[$key][$arrayKey] = $value;
		}
		// data sync
		$cacheData = [
			'_magic'	=> 'Ax',
			'data'		=> $this->_data
		];
		$this->_cache->set('sess:' . $this->_sessionId, $cacheData, $this->_duration);
	}
	/**
	 * Tell if a data exists, array-like syntax.
	 * @param	string $key	Data name.
	 * @return	bool	True if the data exists, false otherwise.
	 */
	public function offsetExists(/* mixed */ $key) : bool {
		if (!isset($this->_cache))
			return (isset($_SESSION[$key]));
		return (isset($this->_data[$key]));
	}
	/**
	 * Fetch a session data.
	 * @param	string	$key		Data name.
	 * @param	mixed	$default	(optional) Default value, used if the data doesn't exist in session.
	 * @return	mixed	Value of the session data.
	 * @link	http://php.net/manual/function.unserialize.php
	 */
	public function get(string $key, /* mixed */ $default=null) /* : mixed */ {
		TµLog::log('Temma/Base', 'DEBUG', "Returning value for key '$key'.");
		if (!isset($this->_cache)) {
			if (!isset($_SESSION[$key]) && isset($default))
				return ($default);
			if (isset($_SESSION[$key]))
				return ($_SESSION[$key]);
			return (null);
		}
		if (!isset($this->_data[$key]) && isset($default))
			return ($default);
		if (isset($this->_data[$key]))
			return ($this->_data[$key]);
		return (null);
	}
	/**
	 * Fetch a session data, array-like syntax.
	 * @param	string	$key	Data name.
	 * @return	mixed	Value of the session data.
	 */
	public function offsetGet(/* mixed */ $key) /* : mixed */ {
		return ($this->get($key));
	}
	/**
	 * Return all session variables.
	 * @return	array	Session data.
	 */
	public function getAll() : ?array {
		if (!isset($this->_data))
			return (null);
		return ($this->_data); 
	}
}

