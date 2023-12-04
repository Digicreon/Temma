<?php

/**
 * Slack
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Slack management object.
 *
 * This object is used to send notifications on Slack.
 * To send Skriv notifications on Slack, you must install the official Incoming Webhook application
 * (https://my.slack.com/services/new/incoming-webhook), and get the given Incoming Webhook URL.
 *
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $slack = \Temma\Datasources\Slack::factory('slack://WEBHOOK_URL');
 * $slack = \Temma\Base\Datasource::factory('slack://WEBHOOK_URL');
 * // where WEBHOOK_URL is the fetched webhook URL without the 'https://' part at its beginning.
 * // example: 'slack://hooks.slack.com/services/TXXXXXXXX/BYYYYYYYY/ZZZZZZZZZZZZZZZZZZZZZZZZ'
 *
 * // initialization with a given notifier name
 * $slack = \Temma\Datasources\Slack::factory('slack://username@WEBHOOK_URL');
 * $slack = \Temma\Base\Datasource::factory('slack://username@WEBHOOK_URL');
 *
 * // initialization with a given notifier name and a notifier icon URL
 * // the icon URL must be URL-encoded
 * $slack = \Temma\Datasources\Slack::factory('slack://username:ICON_URL@WEBHOOK_URL');
 * $slack = \Temma\Base\Datasource::factory('slack://username:ICON_URL@WEBHOOK_URL');
 *
 * // send a simple text message to the given channel
 * $slack['CHANNEL'] = 'Text message';
 * $slack->write('CHANNEL', 'Text message');
 * $slack->set('CHANNEL', 'Text message');
 *
 * // send a simple message, with a defined user name and icon
 * $slack->write('CHANNEL', 'Text message', [
 *     'author' => 'Author name',
 *     'icon'   => 'https://site.com/path/to/author/icon.png',
 * ]);
 * $slack->set('CHANNEL', 'Text message', [
 *     'author' => 'Author name',
 *     'icon'   => 'https://site.com/path/to/author/icon.png',
 * ]);
 *
 * // send a formatted notification
 * $slack['CHANNEL'] = [
 *     'text'        => 'Text above the notification <https://temma.net|with a link>',
 *     'attachments' => [
 *         [
 *             'pretext' => 'Text before the attachment',
 *             'author'  => 'Attachment author's name',
 *             'icon'    => 'Attachment author's icon URL',
 *             'link'    => 'Attachment author's link URL',
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
 *
 * // send a formatted notification, with a defined user name and icon
 * $slack->set('CHANNEL', [
 *     'text'        => 'blah blah blah',
 *     'attachments' => ['Text 1', 'Text 2'],
 * ], [
 *     'author' => 'Author name',
 *     'icon'   => 'https://site.com/path/to/author/icon.png',
 * ]);
 *
 * // definition of user and icon for all messages
 * $slack->setAuthor('Notifier's name', 'https://site.com/path/to/author/icon.png');
 * $slask['CHANNEL'] = 'Text';
 * $slack['CHANNEL'] = 'blah blah blah';
 * $slack['CHANNEL'] = [
 *     'text'        => 'blah blah',
 *     'attachments' => ['text 1', 'text 2'],
 * ];
 * </code>
 */
class Slack extends \Temma\Base\Datasource {
	/** Webhook URL. */
	private ?string $_webhook = null;
	/** User name. */
	private ?string $_username = null;
	/** User icon. */
	private ?string $_icon = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Slack	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Slack {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Slack object creation with DSN: '$dsn'.");
		$username = null;
		$webhook = null;
		$icon = null;
		if (!str_starts_with($dsn, 'slack://')) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Slack DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid Slack DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$dsn = mb_substr($dsn, mb_strlen('slack://'));
		if (($pos = mb_strpos($dsn, '@')) !== false) {
			$username = mb_substr($dsn, 0, $pos);
			$dsn = mb_substr($dsn, $pos + 1);
			if (($pos = mb_strpos($username, ':')) !== false) {
				$icon = urldecode(mb_substr($username, $pos + 1));
				$username = mb_substr($username, 0, $pos);
			}
		}
		if (!str_starts_with($dsn, 'hooks.slack.com/services/'))
			throw new \Temma\Exceptions\Database("Invalid Slack webhook '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$webhook = "https://$dsn";
		return (new self($webhook, $username, $icon));
	}
	/**
	 * Constructor.
	 * @param	string	$webhook	Slack webhook.
	 * @param	?string	$username	(optional) User name.
	 * @param	?string	$icon		(optional) User icon URL.
	 */
	private function __construct(string $webhook, ?string $username=null, ?string $icon=null) {
		$this->_webhook = $webhook;
		$this->_username = $username;
		$this->_icon = $icon;
	}

	/* ********** SPECIAL METHODS ********** */
	/**
	 * Defines the notifer name and icon for all subsequent calls.
	 * @param	?string	$author	(optional) Author name.
	 * @param	?string	$icon	(optional) Author icon URL.
	 */
	public function setAuthor(?string $author, ?string $icon) : void {
		$this->_username = $author;
		$this->_icon = $icon;
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
	 * @param	string	$channel	Slack channel (starting with a sharp character).
	 * @param	string	$text		Text message, or array of text messages, or an associative array with formatted message data,
	 *					or a list of associative arrays.
	 * @param	mixed	$options	(optional) Associative array with some of the following keys:
	 * 					- author: Notification author's name.
	 *					- icon: Notification author's icon.
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
	 * @param	string		$channel	Slack channel (starting with a sharp character).
	 * @param	string|array	$message	Text message, or array of text messages, or an associative array with formatted message data,
	 *						or a list of associative arrays.
	 * @param	mixed		$options	(optional) Associative array with some of the following keys:
	 * 						- author: Notification author's name.
	 *						- icon: Notification author's icon.
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
						if (isset($attachment['author']))
							$chunk['author_name'] = $attachment['author'];
						if (isset($attachment['icon']))
							$chunk['author_icon'] = $attachment['icon'];
						if (isset($attachment['link']))
							$chunk['author_link'] = $attachment['link'];
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
		if (($options['author'] ?? null))
			$data['username'] = $options['author'];
		else if ($this->_username)
			$data['username'] = $this->_username;
		if (($options['icon'] ?? null))
			$data['icon_url'] = $options['icon'];
		else if ($this->_icon)
			$data['icon_url'] = $this->_icon;
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

