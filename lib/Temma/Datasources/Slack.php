<?php

/**
 * Slack
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/datasource-slack
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Slack management object.
 *
 * This object is used to send notifications on Slack.
 * To send a notifications on Slack, you create a Slack application and add a webhook.
 * See https://api.slack.com/messaging/webhooks
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $slack = \Temma\Datasources\Slack::factory('slack://WEBHOOK_URL');
 * $slack = \Temma\Base\Datasource::factory('slack://WEBHOOK_URL');
 * // where WEBHOOK_URL is the fetched webhook URL without the 'https://' part at its beginning.
 * // example: 'slack://hooks.slack.com/services/TXXXXXXXX/BYYYYYYYY/ZZZZZZZZZZZZZZZZZZZZZZZZ'
 *
 * // send a simple text message to the given channel
 * $slack[''] = 'Text message';
 * $slack->write('', 'Text message');
 * $slack->set('', 'Text message');
 *
 * // send a formatted notification
 * $slack[''] = [
 *     'text'        => 'Text above the notification <https://temma.net|with a link>',
 *     'attachments' => [
 *         [
 *             'pretext' => 'Text before the attachment',
 *             'text'    => 'Attachment's text content',
 *             'image'   => 'https://site.com/path/to/image.jpg',
 *             'footer'  => 'Attachment's footer text',
 *         ],
 *         'Text-only attachment',
 *         'Another text-only attachment',
 *         [
 *             'image' => 'https://site.com/path/to/image2.jpg',
 *         ],
 *     ],
 * ];
 */
class Slack extends \Temma\Base\Datasource {
	/** Webhook URL. */
	private ?string $_webhook = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Slack	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Slack {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Slack object creation with DSN: '$dsn'.");
		$webhook = null;
		if (!str_starts_with($dsn, 'slack://')) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Slack DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid Slack DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$dsn = mb_substr($dsn, mb_strlen('slack://'));
		if (($pos = mb_strpos($dsn, '@')) !== false)
			$dsn = mb_substr($dsn, $pos + 1);
		if (!str_starts_with($dsn, 'hooks.slack.com/services/'))
			throw new \Temma\Exceptions\Database("Invalid Slack webhook '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$webhook = "https://$dsn";
		return (new self($webhook));
	}
	/**
	 * Constructor.
	 * @param	string	$webhook	Slack webhook URL.
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
	 * Send a Slack notification.
	 * @param	string	$channel	Not used.
	 * @param	string	$text		Text message, or array of text messages, or an associative array with formatted message data,
	 *					or a list of associative arrays.
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
	 * Send a notification to Slack.
	 * @param	string		$channel	Not used.
	 * @param	string|array	$message	Text message, or array of text messages, or an associative array with formatted message data,
	 *						or a list of associative arrays.
	 * @param	mixed		$options	Not used.
	 * @return	bool	always true.
	 * @throws	\Exception	If an error occured.
	 */
	public function set(string $channel, mixed $message=null, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		$data = [];
		if (is_string($message))
			$data['text'] = $message;
		else if (is_array($message)) {
			$data['text'] = $message['text'] ?? '';
			if (is_array($message['attachments'])) {
				$data['attachments'] = [];
				foreach ($message['attachments'] as $attachment) {
					if (is_string($attachment)) {
						$data['attachments'][] = [
							'text' => $attachment,
						];
					} else if (is_array($attachment)) {
						$chunk = [];
						if (isset($attachment['pretext']))
							$chunk['pretext'] = $attachment['pretext'];
						if (isset($attachment['text']))
							$chunk['text'] = $attachment['text'];
						if (isset($attachment['image']))
							$chunk['image_url'] = $attachment['image'];
						if (isset($attachment['footer']))
							$chunk['footer'] = $attachment['footer'];
						if ($chunk)
							$data['attachments'][] = $chunk;
					}
				}
			}
		} else
			throw new \Temma\Exceptions\Database("Bad Slack message parameter.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$data['channel'] = $channel;
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
		if ($result != 'ok')
			throw new \Temma\Exceptions\Database("Bad Slack response '$result'.", \Temma\Exceptions\Database::FUNDAMENTAL);
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

