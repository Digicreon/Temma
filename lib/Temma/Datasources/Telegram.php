<?php

/**
 * Telegram
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2025-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-telegram
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Telegram Bot management object.
 *
 * This object is used to send notifications to a Telegram bot.
 * To send a notifications to a Telegram bot, you need to create a bot and retrieve the associated
 * token, and then the related chat_id.
 * - On Telegram, search for BotFather.
 * - Send `/newbot`.
 * - You will get an API token.
 *
 * For a private discussion:
 * - Chat with your bot.
 * - Do a request: `curl -s "https://api.telegram.org/bot<token>/getUpdates"`
 * - In the JSON response, search for `"chat":{"id":123456789}`. This is the `chat_id`.
 *
 * For a group conversation:
 * - Add the bot to a group.
 * - Get the `chat_id` (negative number).
 *
 * For a channel:
 * - Create a channel.
 * - Add the bot to the channel, as administrator.
 * - `chat_id` is the channel name (like `@channel_name`).
 *
 * See https://core.telegram.org/bots/features#botfather
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $tg = \Temma\Datasources\Telegram::factory('telegram://API_TOKEN');
 * $tg = \Temma\Base\Datasource::factory('telegram://API_TOKEN');
 *
 * // send a simple text message
 * $tg['123456789'] = 'Text message';
 * $tg->write('@my_channel', 'Text message');
 * $tg->set('@channel_name', 'Text message');
 *
 * // send a notification formatted with simplified HTML
 * // See https://core.telegram.org/bots/api#html-style
 * $tg['@my_channel'] = 'Text with <i>simple</i> <b>formatting</b>.';
 * </code>
 */
class Telegram extends \Temma\Base\Datasource {
	/** Constant: URL of the Telegram API. */
	const string API_URL = 'https://api.telegram.org/bot%s/sendMessage';
	/** API token. */
	private ?string $_apiToken = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Telegram	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Telegram {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Telegram object creation with DSN: '" . self::_maskDsn($dsn) . "'.");
		if (!str_starts_with($dsn, 'telegram://')) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Telegram DSN '" . self::_maskDsn($dsn) . "'.");
			throw new \Temma\Exceptions\Database("Invalid Telegram DSN '" . self::_maskDsn($dsn) . "'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$dsn = mb_substr($dsn, mb_strlen('telegram://'));
		return (new self($dsn));
	}
	/**
	 * Constructor.
	 * @param	string	$token	API token.
	 */
	public function __construct(string $token) {
		$this->_apiToken = $token;
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
	 * Send a Telegram notification.
	 * @param	string	$chatId		Chat ID or channel name.
	 * @param	?string	$text		Text message.
	 * @param	mixed	$options	Not used.
	 * @return	bool	True if the message was sent, false if the data source is disabled.
	 * @throws	\Temma\Exceptions\Database	If an error occured.
	 */
	public function write(string $chatId, ?string $text=null, mixed $options=null) : bool {
		return ($this->set($chatId, $text, $options));
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Send a notification to Telegram.
	 * @param	string	$chatId		Chat ID or channel name.
	 * @param	mixed	$message	Text message (scalar value).
	 * @param	mixed	$options	Not used.
	 * @return	bool	True if the message was sent, false if the data source is disabled.
	 * @throws	\Temma\Exceptions\Database	If an error occured.
	 */
	public function set(string $chatId, mixed $message=null, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		if (!is_scalar($message))
			throw new \Temma\Exceptions\Database("Bad Telegram message parameter.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$message = (string)$message;
		// send the message
		$url = sprintf(static::API_URL, $this->_apiToken);
		$postfields = [
			'chat_id'                  => $chatId,
			'text'                     => $message,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => true,
		];
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $postfields,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 10,
		]);
		$response = curl_exec($ch);
		if ($response === false) {
			$error = curl_error($ch);
			curl_close($ch);
			throw new \Temma\Exceptions\Database("cURL error while sending Telegram message: $error", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		// check the response (the raw response is not written in the message, it could contain the URL with the token)
		$data = json_decode($response, true);
		if (!is_array($data))
			throw new \Temma\Exceptions\Database("Invalid Telegram API response (HTTP code $code).", \Temma\Exceptions\Database::FUNDAMENTAL);
		if (($data['ok'] ?? false) !== true)
			throw new \Temma\Exceptions\Database('Telegram API error: ' . ($data['description'] ?? "Unknown error (HTTP code $code)."), \Temma\Exceptions\Database::FUNDAMENTAL);
		return (true);
	}
	/**
	 * Disabled mSet().
	 * @param	array	$data		Not used.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	Always 0 if the datasource is disabled (never returned otherwise).
	 * @throws	\Temma\Exceptions\Database	Always throws an exception if the datasource is enabled.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		throw new \Temma\Exceptions\Database("No mSet() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
}

