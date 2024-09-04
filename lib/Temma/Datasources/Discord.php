<?php

/**
 * Discord
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Discord management object.
 *
 * This object is used to send messages on Discord.
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $discord = \Temma\Datasources\Discord::factory('discord://WEBHOOK_URL');
 * $discord = \Temma\Base\Datasource::factory('discord://WEBHOOK_URL');
 * // where WEBHOOK_URL is the fetched webhook URL without the 'https://' part at its beginning.
 * // example: 'discord://discord.com/api/webhooks/ABC/XYZ'
 * // send a simple text message
 * $discord[''] = 'Text message';
 * $discord->write('', 'Text message');
 * $discord->set('', 'Text message');
 */
class Discord extends \Temma\Base\Datasource {
	/** Webhook URL. */
	private ?string $_webhook = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Discord	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Discord {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Discord object creation with DSN: '$dsn'.");
		$webhook = null;
		if (!str_starts_with($dsn, 'discord://')) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Discord DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid Discord DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$dsn = mb_substr($dsn, mb_strlen('discord://'));
		if (($pos = mb_strpos($dsn, '@')) !== false)
			$dsn = mb_substr($dsn, $pos + 1);
		if (!str_starts_with($dsn, 'discord.com/api/webhooks/'))
			throw new \Temma\Exceptions\Database("Invalid Discord webhook '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$webhook = "https://$dsn";
		return (new self($webhook));
	}
	/**
	 * Constructor.
	 * @param	string	$webhook	Discord webhook URL.
	 */
	public function __construct(string $webhook) {
		$this->_webhook = $webhook;
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Disabled clear.
	 * @param	string	$pattern	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function clear(string $pattern) : void {
		throw new \Temma\Exceptions\Database("No clear() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Send a message to Discord.
	 * @param	string	$channel	Not used. Set to empty string.
	 * @param	string	$text		Text message.
	 * @param	mixed	$options	Not used.
	 * @return	bool	Always true.
	 * @throws	\Exception	If an error occured.
	 */
	public function write(string $channel, string $text=null, mixed $options=null) : bool {
		$this->set($channel, $text, $options);
		return (true);
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Send a message to Discord.
	 * @param	string		$channel	Not used. Set to empty string.
	 * @param	string|array	$message	Text message, or array of text messages, or an associative array with formatted message data,
	 *						or a list of associative arrays.
	 * @param	mixed		$options	Not used.
	 * @return	bool	always true.
	 * @throws	\Temma\Exceptions\Database	If an error occured.
	 */
	public function set(string $channel, mixed $message=null, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		$data = [];
		if (is_string($message))
			$data['content'] = $message;
		else
			throw new \Temma\Exceptions\Database("Bad Discord message parameter.", \Temma\Exceptions\Database::FUNDAMENTAL);
		// send the message
		$json = json_encode($data);
		$curl = curl_init($this->_webhook);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		//curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
		curl_setopt($curl, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . mb_strlen($json, 'ascii')
		]);
		$result = curl_exec($curl);
		if ($result === false)
			throw new \Exception(curl_error($curl));
		curl_close($curl);
		if ($result != '')
			throw new \Temma\Exceptions\Database("Bad Discord response '$result'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		return (true);
	}
	/**
	 * Disable mSet().
	 * @param	array	$data		Associative array with keys (recipients' user key) and their associated values (text messages).
	 * @param	mixed	$options	(optional) Options.
	 * @return	int	The number of sent messages.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		throw new \Temma\Exceptions\Database("No mSet() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
}

