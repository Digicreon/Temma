<?php

/**
 * Pushover
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Pushover management object.
 *
 * This object is used to send push notifications on Pushover application.
 * The Pushover application must be downloaded and installed (30 days free trial).
 * In order to use it, you must create an account on pushover.net.
 *
 * For API details, please refere to its documentation (https://dev.smsmode.com/http-api/reference/).
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $push = \Temma\Datasources\Pushover::factory('pushover://APP_TOKEN');
 * $push = \Temma\Base\Datasource::factory('pushover://APP_TOKEN');
 *
 * // send an HTML push message to the given user/group
 * $push['USER_KEY'] = 'Text <b>message</b>';
 * $push->write('USER_KEY', 'Text <b>message</b>');
 * $push->set('USER_KEY', 'Text <b>message</b>');
 *
 * // send a push message with parameters
 * $push->write('33611223344', 'Text message', [
 *     'title'     => 'Message title',
 *     'html'      => false,
 *     'monospace' => true,
 *     'device'    => 'DEVICE_ID',
 *     'image'     => file_get_contents('picture.jpg'),
 *     'mimetype'  => 'image/jpeg',
 *     'priority'  => -1,
 *     'ttl'       =>  86400,
 *     'url'       => 'https://www.temma.net/',
 *     'urlTitle'  => 'Temma website',
 * ]);
 * </code>
 */
class Pushover extends \Temma\Base\Datasource {
	/** URL of Pushover API. */
	const API_URL = 'https://api.pushover.net/1/messages.json';
	/** APP token. */
	private ?string $_appToken = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Pushover	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Pushover {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Pushover object creation with DSN: '$dsn'.");
		if (!str_starts_with($dsn, 'pushover://')) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Pushover DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid Pushover DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$appToken = mb_substr($dsn, mb_strlen('pushover://'));
		if (!$appToken)
			throw new \Temma\Exceptions\Database("Invalid Pushover DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		return (new self($appToken));
	}
	/**
	 * Constructor.
	 * @param	string	$appToken	Application token.
	 */
	private function __construct(string $appToken) {
		$this->_appToken = $appToken;
		$this->_enabled = true;
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Disabled clear.
	 * @param	string	$pattern	Not used.
	 * @return	\Temma\Datasources\Pushover	Never returned.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function clear(string $pattern) : \Temma\Datasources\Pushover {
		throw new \Temma\Exceptions\Database("No clear() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Send a push message. By default, HTML content option is activated.
	 * @param	string	$userKey	Recipient's user key.
	 * @param	string	$text		Text message.
	 * @param	mixed	$options	(optional) Associative array with some of the following keys:
	 * 					- title: Set the notification's title.
	 *					- html: Set to false to disable the HTML content option.
	 *					- monospace: Set to true to enable the monospace option.
	 *					  HTML option will automatically set to false.
	 *					- device: Device identifier, if you want to send the notification to a specific
	 *					  device of the user, instead of all his/her devices.
	 *					- image: Binary content of an image that will be displayed in the notification.
	 *					- mimetype: Mimetype of the attached image.
	 *					- priority: A number going from -2 to 2 (defaults to 0).
	 *					  -2 = no notification
	 *					  -1 = no sound or vibration (still a popup/scrolling notification)
	 *					   0 = sound, vibration and popup alert
	 *					   1 = message highlighted in red, bypass user's quiet hours
	 *					   2 = notification repeated until the user aknowledges it
	 *					- ttl: Number of seconds before the message disappear.
	 *					- url: URL added under the text message.
	 *					- urlTitle: Title of the URL (otherwise, the URL will appear as-is).
	 * @return	\Temma\Datasources\Pushover	The current object.
	 * @throws	\Exception	If an error occured.
	 */
	public function write(string $userKey, string $text=null, mixed $options=null) : \Temma\Datasources\Pushover {
		$curl = curl_init();
		$post = [
			'token'   => $this->_appToken,
			'user'    => $userKey,
			'message' => $text,
		];
		if (($options['title'] ?? null))
			$post['title'] = $options['title'];
		$monospace = (($options['monospace'] ?? null) === true) ? true : false;
		$html = ($monospace || ($options['html'] ?? null) === false) ? false : true;
		if ($monospace)
			$post['monospace'] = '1';
		if ($html)
			$post['html'] = '1';
		if (($options['device'] ?? null))
			$post['device'] = $options['device'];
		if (($options['image'] ?? null) && ($options['mimetype'] ?? null)) {
			$post['attachment_base64'] = base64_encode($options['image']);
			$post['attachment_type'] = $options['mimetype'];
		}
		if (in_array(($options['priority'] ?? 0), [-2, -1, 1, 2]))
			$post['priority'] = $options['priority'];
		if (($options['ttl'] ?? null))
			$post['ttl'] = $options['ttl'];
		if (($options['url'] ?? null)) {
			$post['url'] = $options['url'];
			if (($options['urlTitle'] ?? null))
				$post['url_title'] = $options['urlTitle'];
		}
		curl_setopt_array($curl, [
			CURLOPT_URL            => self::API_URL,
			CURLOPT_POSTFIELDS     => $post,
			CURLOPT_SAFE_UPLOAD    => true,
			CURLOPT_RETURNTRANSFER => true,
		]);
		curl_exec($curl);
		curl_close($curl);
		return ($this);
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Send a push message. Alias of the write() method.
	 * @param	string	$userKey	Recipient's user key.
	 * @param	mixed	$text		Text message.
	 * @param	mixed	$options	(optional) Associative array with some of the following keys:
	 * 					- title: Set the notification's title.
	 *					- html: Set to false to disable the HTML content option.
	 *					- monospace: Set to true to enable the monospace option.
	 *					  HTML option will automatically set to false.
	 *					- device: Device identifier, if you want to send the notification to a specific
	 *					  device of the user, instead of all his/her devices.
	 *					- image: Binary content of an image that will be displayed in the notification.
	 *					- mimetype: Mimetype of the attached image.
	 *					- priority: A number going from -2 to 2 (defaults to 0).
	 *					  -2 = no notification
	 *					  -1 = no sound or vibration (still a popup/scrolling notification)
	 *					   0 = sound, vibration and popup alert
	 *					   1 = message highlighted in red, bypass user's quiet hours
	 *					   2 = notification repeated until the user aknowledges it
	 *					- ttl: Number of seconds before the message disappear.
	 *					- url: URL added under the text message.
	 *					- urlTitle: Title of the URL (otherwise, the URL will appear as-is).
	 * @return	\Temma\Datasources\Pushover	The current object.
	 * @throws	\Exception	If an error occured.
	 */
	public function set(string $userKey, mixed $text=null, mixed $options=null) : \Temma\Datasources\Pushover {
		return ($this->write($userKey, $text, $options));
	}
	/**
	 * Multiple set. Alias to the mWrite() method.
	 * @param	array	$data		Associative array with keys (recipients' user key) and their associated values (text messages).
	 * @param	mixed	$options	(optional) Options.
	 * @return	int	The number of sent messages.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		return ($this->mWrite($data, $options));
	}
}

