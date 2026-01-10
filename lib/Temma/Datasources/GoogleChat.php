<?php

/**
 * GoogleChat
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2025, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-googlechat
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Google Chat management object.
 *
 * This object is used to send notifications on Google Chat.
 * To send a notifications on Google Chat, you need to create a Google Chat application and add a webhook.
 * See https://developers.google.com/workspace/chat/quickstart/webhooks
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $gchat = \Temma\Datasources\GoogleChat::factory('gchat://WEBHOOK_URL');
 * $gchat = \Temma\Base\Datasource::factory('gchat://WEBHOOK_URL');
 * // where WEBHOOK_URL is the fetched webhook URL without the 'https://' part at its beginning.
 * // example: 'googlechat://chat.googleapis.com/v1/spaces/XXXXXXX/messages?key=YYYYYYY&token=ZZZZZZZ'
 *
 * // send a simple text message to the given channel
 * $gchat[''] = 'Text message';
 * $gchat->write('', 'Text message');
 * $gchat->set('', 'Text message');
 *
 * // send a notification formatted with Markdown
 * // See https://developers.google.com/workspace/chat/format-messages
 * $gchat[''] = 'Text with *simple* _formatting_.';
 *
 * // Send a rich notification, with a title, a subtitle, a pictogram and/or icons, sections and buttons.
 * // Possible icons (in sections) are: AIRPLANE, BOOKMARK, BUS, CAR, HORLOGE, CONFIRMATION_NUMBER_ICON,
 * // DESCRIPTION, DOLLAR, E-MAIL, EVENT_SEAT, FLIGHT_ARRIVAL, FLIGHT_DEPARTURE, HOTEL, HOTEL_ROOM_TYPE,
 * // INVITER, MAP_PIN, MEMBERSHIP, MULTIPLE_PEOPLE, PERSONNE, TÉLÉPHONE, RESTAURANT_ICON, SHOPPING_CART,
 * // STAR, STORE, TICKET, TRAIN, VIDEO_CAMERA, VIDEO_PLAY
 * // See https://developers.google.com/workspace/chat/format-messages#builtinicons
 * $gchat[''] = [
 *     'title'    => 'A title',
 *     'subtitle' => 'A subtitle',
 *     'picto'    => 'https://url of the picto',
 *     // each section may have a title and: a text, an image or a list of buttons
 *     'sections' => [
 *         [
 *             'title' => 'First section',
 *             'html'  => 'Some <b>text</b>',
 *         ],
 *         [
 *             'icon'        => 'STAR',
 *             'topLabel'    => 'Status',
 *             'html'        => "A <a href=\"https://temma.net\">link</a>.",
 *             'bottomLabel' => 'Generated yesterday',
 *         ],
 *         [
 *             'image' => 'https://url_of_image',
 *             'alt'   => 'Alternate text', // optionnal
 *         ],
 *         [
 *             'title' => 'Another section',
 *             'buttons' => [
 *                 'Button 1' => 'https://link_btn_1',
 *                 'Button 2' => 'https://link_btn_2',
 *                 'Button 3' => 'https://link_btn_3',
 *             ]
 *         ],
 *     ],
 * ];
 * 
 */
class GoogleChat extends \Temma\Base\Datasource {
	/** Webhook URL. */
	private ?string $_webhook = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\GoogleChat	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\GoogleChat {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\GoogleChat object creation with DSN: '$dsn'.");
		$webhook = null;
		if (!str_starts_with($dsn, 'googlechat://')) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Google Chat DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid Google Chat DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$dsn = mb_substr($dsn, mb_strlen('googlechat://'));
		if (($pos = mb_strpos($dsn, '@')) !== false)
			$dsn = mb_substr($dsn, $pos + 1);
		if (!str_starts_with($dsn, 'chat.googleapis.com/v1/spaces/'))
			throw new \Temma\Exceptions\Database("Invalid Google Chat webhook '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$webhook = "https://$dsn";
		return (new self($webhook));
	}
	/**
	 * Constructor.
	 * @param	string	$webhook	Google Chat webhook URL.
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
	 * Send a Google Chat notification.
	 * @param	string	$channel	Not used.
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
	 * Send a notification to Google Chat.
	 * @param	string		$channel	Not used.
	 * @param	string|array	$message	Text message, or an associative array with formatted message data.
	 * @param	mixed		$options	Not used.
	 * @return	bool	Always true.
	 * @throws	\Exception	If an error occured.
	 */
	public function set(string $channel, mixed $message=null, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		$data = [];
		if (is_string($message))
			$data['text'] = $message;
		else if (is_array($message) && is_array($message['sections'] ?? null)) {
			$card = [];
			if (($message['title'] ?? ''))
				$card['header'] = ['title' => $message['title']];
			if (($message['subtitle'] ?? '')) {
				$card['header'] ??= [];
				$card['header']['subtitle'] = $message['subtitle'];
			}
			$card['sections'] = [];
			foreach ($message['sections'] as $msgSection) {
				$section = [];
				if (($msgSection['title'] ?? null))
					$section['header'] = $msgSection['title'];
				$widget = [];
				if (($msgSection['html'] ?? null)) {
					if (($msgSection['icon'] ?? null) ||
					    ($msgSection['topLoabel'] ?? null) ||
					    ($msgSection['bottomLabel'] ?? null)) {
						$widget = [];
						if (($msgSection['icon'] ?? null))
							$widget['startIcon'] = [ 'knownIcon' => $msgSection['icon'] ];
						if (($msgSection['topLabel'] ?? null))
							$widget['topLabel'] = $msgSection['topLabel'];
						if (($msgSection['bottomLabel'] ?? null))
							$widget['bottomLabel'] = $msgSection['bottomLabel'];
						$widget['text'] = $msgSection['html'];
						$section['widgets'] = [[
							'decoratedText' => $widget,
						]];
					} else {
						$section['widgets'] = [[
							'textParagraph' => [
								'text' => $msgSection['html'],
							]
						]];
					}
				} else if (($msgSection['image'] ?? null)) {
					$widget = ['imageUrl' => $msgSection['image']];
					if (($msgSection['alt'] ?? null))
						$widget['altText'] = $msgSection['alt'];
					$section['widgets'] = [[
						'image' => $widget
					]];
				} else if (is_array($msgSection['buttons'] ?? null)) {
					$buttons = [];
					foreach ($msgSection['buttons'] as $btnTxt => $btnLnk) {
						$buttons[] = [
							'text'    => $btnTxt,
							'onClick' => [
								'openLink' => [
									'url' => $btnLnk,
								]
							]
						];
					}
					$section['widgets'] = [[
						'buttonList' => [
							'buttons' => $buttons,
						]
					]];
				}
				$card['sections'][] = $section;
			}
			$data = [
				'cardsV2' => [
					[
						'card' => $card,
					]
				]
			];
		} else
			throw new \Temma\Exceptions\Database("Bad Google Chat message parameter.", \Temma\Exceptions\Database::FUNDAMENTAL);
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
			'Content-Type: application/json; charset=UTF-8',
			'Content-Length: ' . mb_strlen($json, 'ascii')
		]);
		$result = curl_exec($curl);
		if ($result === false)
			throw new \Exception(curl_error($curl));
		curl_close($curl);
		$json = json_decode($result, true);
		if (is_null($json))
			throw new \Temma\Exceptions\Database("Bad Google Chat response:\n$result", \Temma\Exceptions\Database::FUNDAMENTAL);
		if (isset($json['error'])) {
			print("$result\n");
			throw new \Temma\Exceptions\Database("Bad Google Chat response '" . $json['error']['message'] . "' (" . $json['error']['code'] . ").", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
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

