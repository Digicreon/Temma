<?php

/**
 * GoogleChat
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2025-2026, Amaury Bouchard
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
 * $gchat = \Temma\Datasources\GoogleChat::factory('googlechat://WEBHOOK_URL');
 * $gchat = \Temma\Base\Datasource::factory('googlechat://WEBHOOK_URL');
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
 * // Send a rich notification (card), with a header (title, subtitle and pictogram), sections and buttons.
 * // All keys are optional, but the message must contain a text, a header or at least one section.
 * // Possible icons (in sections) are Google Chat built-in icons: AIRPLANE, BOOKMARK, BUS, CAR, CLOCK,
 * // CONFIRMATION_NUMBER_ICON, DESCRIPTION, DOLLAR, EMAIL, EVENT_SEAT, FLIGHT_ARRIVAL,
 * // FLIGHT_DEPARTURE, HOTEL, HOTEL_ROOM_TYPE, INVITE, MAP_PIN, MEMBERSHIP, MULTIPLE_PEOPLE, PERSON,
 * // PHONE, RESTAURANT_ICON, SHOPPING_CART, STAR, STORE, TICKET, TRAIN, VIDEO_CAMERA, VIDEO_PLAY
 * // See https://developers.google.com/workspace/chat/format-messages#builtinicons
 * $gchat[''] = [
 *     // text displayed above the card (simple formatting, like simple text messages)
 *     'text'     => 'Text message',
 *     // card header (Google Chat requires a title when a subtitle or a pictogram is given)
 *     'title'    => 'A title',
 *     'subtitle' => 'A subtitle',
 *     'picto'    => 'https://url_of_the_picto',
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
 *             'alt'   => 'Alternate text', // optional
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
 * </code>
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
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\GoogleChat object creation with DSN: '" . self::_maskDsn($dsn) . "'.");
		$webhook = null;
		if (!str_starts_with($dsn, 'googlechat://')) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Google Chat DSN '" . self::_maskDsn($dsn) . "'.");
			throw new \Temma\Exceptions\Database("Invalid Google Chat DSN '" . self::_maskDsn($dsn) . "'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$dsn = mb_substr($dsn, mb_strlen('googlechat://'));
		if (($pos = mb_strpos($dsn, '@')) !== false)
			$dsn = mb_substr($dsn, $pos + 1);
		if (!str_starts_with($dsn, 'chat.googleapis.com/v1/spaces/'))
			throw new \Temma\Exceptions\Database("Invalid Google Chat webhook '" . self::_maskDsn($dsn) . "'.", \Temma\Exceptions\Database::FUNDAMENTAL);
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
	 * @param	?string	$text		Text message.
	 * @param	mixed	$options	Not used.
	 * @return	bool	True if the message was sent, false if the data source is disabled.
	 * @throws	\Temma\Exceptions\Database	If an error occured.
	 */
	public function write(string $channel, ?string $text=null, mixed $options=null) : bool {
		return ($this->set($channel, $text, $options));
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Send a notification to Google Chat.
	 * @param	string		$channel	Not used.
	 * @param	string|array	$message	Text message, or an associative array with formatted message data ('text',
	 *						'title', 'subtitle', 'picto' and 'sections' keys, see the class documentation).
	 * @param	mixed		$options	Not used.
	 * @return	bool	True if the message was sent, false if the data source is disabled.
	 * @throws	\Temma\Exceptions\Database	If the message is not a string or an array with a text, a header or sections,
	 *						or if an error occured.
	 */
	public function set(string $channel, mixed $message=null, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		$data = [];
		if (is_string($message))
			$data['text'] = $message;
		else if (is_array($message)) {
			// text displayed with the card
			if (is_string($message['text'] ?? null) && $message['text'] !== '')
				$data['text'] = $message['text'];
			// card header
			$card = [];
			if (($message['title'] ?? ''))
				$card['header']['title'] = $message['title'];
			if (($message['subtitle'] ?? ''))
				$card['header']['subtitle'] = $message['subtitle'];
			if (($message['picto'] ?? ''))
				$card['header']['imageUrl'] = $message['picto'];
			// card sections
			foreach ((is_array($message['sections'] ?? null) ? $message['sections'] : []) as $msgSection) {
				$section = [];
				if (($msgSection['title'] ?? null))
					$section['header'] = $msgSection['title'];
				$widget = [];
				if (($msgSection['html'] ?? null)) {
					if (($msgSection['icon'] ?? null) ||
					    ($msgSection['topLabel'] ?? null) ||
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
			if ($card) {
				$data['cardsV2'] = [
					[
						'card' => $card,
					]
				];
			}
		}
		if (!$data)
			throw new \Temma\Exceptions\Database("Bad Google Chat message parameter.", \Temma\Exceptions\Database::FUNDAMENTAL);
		// send the message
		$json = json_encode($data);
		$curl = curl_init($this->_webhook);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);
		curl_setopt($curl, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json; charset=UTF-8',
			'Content-Length: ' . mb_strlen($json, 'ascii')
		]);
		$result = curl_exec($curl);
		if ($result === false) {
			$error = curl_error($curl);
			curl_close($curl);
			throw new \Temma\Exceptions\Database("cURL error while sending Google Chat message: $error", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		curl_close($curl);
		$json = json_decode($result, true);
		if (is_null($json))
			throw new \Temma\Exceptions\Database("Bad Google Chat response:\n$result", \Temma\Exceptions\Database::FUNDAMENTAL);
		if (isset($json['error']))
			throw new \Temma\Exceptions\Database("Bad Google Chat response '" . $json['error']['message'] . "' (" . $json['error']['code'] . ").", \Temma\Exceptions\Database::FUNDAMENTAL);
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

