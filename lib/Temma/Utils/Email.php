<?php

/**
 * Email.
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-email
 */

namespace Temma\Utils;

use \Temma\Base\Log as TµLog;

/**
 * Object used to send emails.
 *
 * Could be used directly as a static object:
 * ```php
 * use \Temma\Utils\Email as TµEmail;
 * TµEmail::simpleMail('luke@rebellion.org', 'vader@empire.com', "I can't beleive it", "You are my father?");
 * TµEmail::fullMail('vader@empire.com', 'luke@rebellion.org', "Yes", "<h1>Yes</h1><p>I am your father</p>");
 * ```
 *
 * The "temma.json" configuration file may contain an "x-email" extended configuration,
 * to define automatic recipients ("cc" and "bcc"), and to define the envelope sender passed to sendmail.
 * ```json
 * {
 *     "x-email": {
 *         "disabled": true,
 *         "allowedDomains": ["temma.net", "temma.org"],
 *         "cc": "leia@rebellion.org",
 *         "bcc": [
 *             "palpatine@empire.com",
 *             "yoda@jedi.org"
 *         ],
 *         "envelopeSender": "administrator@blackstar.com"
 *     }
 * ]
 * ```
 *
 * Then, the Email object must be initiated using the loader object:
 * ```php
 * // initialization at first use
 * $this->_loader['\Temma\Utils\Email']->textMail( ... );
 * ```
 *
 * @link	https://www.php.net/manual/en/function.mail.php
 */
class Email implements \Temma\Base\Loadable {
	/** Dependency injection component. */
	protected \Temma\Base\Loader $_loader;
	/** Tell if message sending is disabled. */
	protected bool $_disabled = false;
	/** List of allowed domains. */
	protected ?array $_allowedDomains = null;
	/** Recipients added to all messages. */
	protected ?array $_cc = [];
	/** Blinded recipients added to all messages. */
	protected ?array $_bcc = [];
	/** Envelope sender used for all messages. */
	protected string $_envelopeSender = '';
	/** Transport datasource used to send messages (null: use PHP's mail() through the local MTA). */
	protected ?\Temma\Base\Datasource $_transport = null;
	/** True once the transport has been resolved (from a setter or the configuration). */
	protected bool $_transportResolved = false;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader	Dependency injection component.
	 */
	public function __construct(\Temma\Base\Loader $loader) {
		$this->_loader = $loader;
		$disabled = $loader->config?->xtra('email', 'disabled');
		if ($disabled)
			$this->_disabled = true;
		$allowedDomains = $loader->config?->xtra('email', 'allowedDomains');
		if (is_array($allowedDomains))
			$this->_allowedDomains = $allowedDomains;
		$cc = $loader->config?->xtra('email', 'cc');
		if (is_array($cc))
			$this->_cc = array_filter($cc);
		else if (is_string($cc) && $cc)
			$this->_cc = [$cc];
		$bcc = $loader->config?->xtra('email', 'bcc');
		if (is_array($bcc))
			$this->_bcc = array_filter($bcc);
		else if (is_string($bcc) && $bcc)
			$this->_bcc = [$bcc];
		$envelopeSender = $loader->config?->xtra('email', 'envelopeSender');
		if (is_string($envelopeSender))
			$this->_envelopeSender = trim($envelopeSender);
	}
	/**
	 * Enable or disable message sending.
	 * @param	bool	$enable	True to enable, false to disable.
	 */
	public function enable(bool $enable) : void {
		$this->_disabled = !$enable;
	}
	/**
	 * Define the list of allowed domains.
	 * @param	?array	$allowedDomains	The list of allowed domains.
	 */
	public function setAllowedDomains(?array $allowedDomains) : void {
		$this->_allowedDomains = $allowedDomains;
	}
	/**
	 * Define recipients for all messages.
	 * @param	string|array	$cc	Additional recipients.
	 */
	public function setCc(string|array $cc) : void {
		$this->_cc = is_array($cc) ? $cc : [$cc];
	}
	/**
	 * Define blinded recipients for all messages.
	 * @param	string|array	$bcc	Additional blinded recipients.
	 */
	public function setBcc(string|array $bcc) : void {
		$this->_bcc = is_array($bcc) ? $bcc : [$bcc];
	}
	/**
	 * Define the envelope sender for all messages.
	 * @param	string	$envelopeSender	The envelope sender.
	 */
	public function setEnvelopeSender(string $envelopeSender) : void {
		$this->_envelopeSender = trim($envelopeSender);
	}
	/**
	 * Define the transport datasource used to send messages.
	 * Any datasource supporting set() works: \Temma\Datasources\Smtp to deliver through a relay,
	 * File/S3 to archive, Sqs/Beanstalk to enqueue, Dummy to discard.
	 * @param	\Temma\Base\Datasource	$datasource	The transport datasource.
	 */
	public function setDatasource(\Temma\Base\Datasource $datasource) : void {
		$this->_transport = $datasource;
		$this->_transportResolved = true;
	}
	/**
	 * Define the transport as an SMTP relay, from a DSN or separate parameters.
	 * @param	string|array	$smtp	SMTP DSN ('smtp://', 'smtp+tls://' or 'smtps://'), or associative
	 *					array of parameters (see \Temma\Datasources\Smtp::fromParams()).
	 */
	public function setSmtp(string|array $smtp) : void {
		$this->_transport = is_string($smtp) ? \Temma\Base\Datasource::factory($smtp)
		                                     : \Temma\Datasources\Smtp::fromParams($smtp);
		$this->_transportResolved = true;
	}
	/**
	 * Send a simple raw-text message, without attachment.
	 * @param	string		$from		Sender of the message (in the form "Name <address@domain>" or "address@domain").
	 * @param	string|array	$to		Recipient of the message, or list of recipients (each recipient in the form "Name <address@domain>" or "address@domain").
	 * @param	string		$title		(optional) Title of the message.
	 * @param	string		$message	(optional) Content of the message.
	 * @param	string|array	$cc		(optional) Other recipient, or list of recipients.
	 * @param	string|array	$bcc		(optional) Blinded recipient, or list of recipients.
	 * @param	?string		$envelopeSender	(optional) Envelope sender passed to sendmail.
	 */
	public function textMail(string $from, string|array $to, string $title='', string $message='',
	                         string|array $cc='', string|array $bcc='', ?string $envelopeSender=null) : void {
		if ($this->_disabled)
			return;
		$to = $this->_filterRecipients($to);
		if (!$to)
			return;
		$cc = is_array($cc) ? $cc : [$cc];
		$cc = array_merge($cc, $this->_cc);
		$bcc = is_array($bcc) ? $bcc : [$bcc];
		$bcc = array_merge($bcc, $this->_bcc);
		$envelopeSender = $envelopeSender ?: $this->_envelopeSender;
		$this->_deliver($from, $to, $title, '', $message, null, $cc, $bcc, null, $envelopeSender);
	}
	/**
	 * Send an HTML mail, with or without a raw text version, with or without attached files.
	 * @param	string		$from		Sender of the message (in the form "Name <address@domain>" or "address@domain").
	 * @param	string|array	$to		Recipient of the message, or list of recipients (each recipient in the form "Name <address@domain>" or "address@domain").
	 * @param	string		$title		(optional) Title of the message.
	 * @param	string		$html		(optional) HTML content of the message.
	 * @param	?string		$text		(optional) Raw text content of the message.
	 * @param	?array		$attachments	(optional) List of files to attach, each one represented by an associative array containing these keys:
	 *  			               		- filename	Name of the file.
	 * 			               		- mimetype	MIME type of the file.
	 * 			               		- data		Binary content of the file.
	 * @param	string|array	$cc		(optional) Other recipient, or list of recipients.
	 * @param	string|array	$bcc		(optional) Blinded recipient, or list of recipients.
	 * @param	?string		$unsubscribe	(optional) Content for the "List-Unsubscribe" header.
	 *						For example: "<mailto:contact@site.com?subject=Unsubscribe>, <https://www.site.com/mail/unsubscribe>"
	 * @param	?string		$envelopeSender	(optional) Envelope sender passed to sendmail.
	 */
	public function mimeMail(string $from, string|array $to, string $title='', string $html='', ?string $text=null,
	                         ?array $attachments=null, string|array $cc='', string|array $bcc='',
	                         ?string $unsubscribe=null, ?string $envelopeSender=null) : void {
		if ($this->_disabled)
			return;
		$to = $this->_filterRecipients($to);
		if (!$to)
			return;
		$cc = is_array($cc) ? $cc : [$cc];
		$cc = array_merge($cc, $this->_cc);
		$bcc = is_array($bcc) ? $bcc : [$bcc];
		$bcc = array_merge($bcc, $this->_bcc);
		$envelopeSender = $envelopeSender ?: $this->_envelopeSender;
		$this->_deliver($from, $to, $title, $html, $text, $attachments, $cc, $bcc, $unsubscribe, $envelopeSender);
	}
	/**
	 * Send an HTML mail, with or without a raw text version, with or without attached files.
	 * The text and HTML messages are generated from Smarty templates.
	 * @param	string		$from		Sender of the message (in the form "Name <address@domain>" or "address@domain").
	 * @param	string|array	$to		Recipient of the message, or list of recipients (each recipient in the form "Name <address@domain>" or "address@domain").
	 * @param	string		$title		(optional) Title of the message.
	 * @param	?string		$htmlTplPath	(optional) Path to the HTML template file. Can be an absolute path (starting with '/')
	 *						or a relative path (under the 'templates/' directory).
	 * @param	?string		$textTplPath	(optional) Path to the text template file. Can be an absolute path (starting with '/')
	 *						or a relative path (under the 'templates/' directory).
	 * @param	?array		$templateData	(optional) Associative array of data that must be sent to the templates.
	 * @param	?array		$attachments	(optional) List of files to attach, each one represented by an associative array containing these keys:
	 *  			               		- filename	Name of the file.
	 * 			               		- mimetype	MIME type of the file.
	 * 			               		- data		Binary content of the file.
	 * @param	string|array	$cc		(optional) Other recipient, or list of recipients.
	 * @param	string|array	$bcc		(optional) Blinded recipient, or list of recipients.
	 * @param	?string		$unsubscribe	(optional) Content for the "List-Unsubscribe" header.
	 *						For example: "<mailto:contact@site.com?subject=Unsubscribe>, <https://www.site.com/mail/unsubscribe>"
	 * @param	?string		$envelopeSender	(optional) Envelope sender passed to sendmail.
	 * @see	\Temma\Utils\Smarty	The HTML template follows the configured HTML auto-escaping setting; the text template is never auto-escaped.
	 */
	public function templatedMail(string $from, string|array $to, string $title='',
	                              ?string $htmlTplPath='', ?string $textTplPath=null,
	                              ?array $templateData=null, ?array $attachments=null,
	                              string|array $cc='', string|array $bcc='',
	                              ?string $unsubscribe=null, ?string $envelopeSender=null) : void {
		$html = $text = '';
		if ($htmlTplPath)
			$html = $this->_loader['\Temma\Utils\Smarty']->render($htmlTplPath, $templateData);
		if ($textTplPath)
			$text = $this->_loader['\Temma\Utils\Smarty']->render($textTplPath, $templateData, autoEscape: false);
		$this->mimeMail($from, $to, $title, $html, $text, $attachments, $cc, $bcc, $unsubscribe, $envelopeSender);
	}

	/* ********** STATIC METHODS ********** */
	/**
	 * Sends a simple raw-text message, without attachment.
	 * @param	string		$from		Sender of the message (in the form "Name <address@domain>" or "address@domain").
	 * @param	string|array	$to		Recipient of the message, or list of recipients (each recipient in the form "Name <address@domain>" or "address@domain").
	 * @param	string		$title		(optional) Title of the message.
	 * @param	string		$message	(optional) Content of the message.
	 * @param	string|array	$cc		(optional) Other recipient, or list of recipients.
	 * @param	string|array	$bcc		(optional) Blinded recipient, or list of recipients.
	 * @param	?string		$envelopeSender	(optional) Envelope sender passed to sendmail.
	 */
	static public function simpleMail(string $from, string|array $to, string $title='', string $message='',
	                                  string|array $cc='', string|array $bcc='', ?string $envelopeSender=null) : void {
		$headers = [ 
			'Content-Type' => 'text/plain; charset=utf-8',
			'From'         => $from,
		];
		// recipient
		if (is_array($to)) {
			$to = array_filter($to);
			$to = $to ? implode(', ', $to) : '';
		}
		// other recipients
		if (is_array($cc)) {
			$cc = array_filter($cc);
			$cc = implode(', ', $cc);
		}
		if ($cc)
			$headers['Cc'] = $cc;
		// blinded recipients
		if (is_array($bcc)) {
			$bcc = array_filter($bcc);
			$bcc = implode(', ', $bcc);
		}
		if ($bcc)
			$headers['Bcc'] = $bcc;
		// management of the envelope sender
		$params = '';
		if ($envelopeSender)
			$params = "-f$envelopeSender";
		// send the message
		mail($to, $title, $message, $headers, $params);
	}
	/**
	 * Send an HTML mail, with or without a raw text version, with or without attached files.
	 * @param	string		$from		Sender of the message (in the form "Name <address@domain>" or "address@domain").
	 * @param	string|array	$to		Recipient of the message, or list of recipients (each recipient in the form "Name <address@domain>" or "address@domain").
	 * @param	string		$title		(optional) Title of the message.
	 * @param	string		$html		(optional) HTML content of the message.
	 * @param	?string		$text		(optional) Raw text content of the message.
	 * @param	?array		$attachments	(optional) List of files to attach, each one represented by an associative array containing these keys:
	 *  			               		- filename	Name of the file.
	 * 			               		- mimetype	MIME type of the file.
	 * 			               		- data		Binary content of the file.
	 * @param	string|array	$cc		(optional) Other recipient, or list of recipients.
	 * @param	string|array	$bcc		(optional) Blinded recipient, or list of recipients.
	 * @param	?string		$unsubscribe	(optional) Content for the "List-Unsubscribe" header.
	 *						For example: "<mailto:contact@site.com?subject=Unsubscribe>, <https://www.site.com/mail/unsubscribe>"
	 * @param	?string		$envelopeSender	(optional) Envelope sender passed to sendmail.
	 */
	static public function fullMail(string $from, string|array $to, string $title='', string $html='', ?string $text=null,
	                                ?array $attachments=null, string|array $cc='', string|array $bcc='',
	                                ?string $unsubscribe=null, ?string $envelopeSender=null) : void {
		// headers
		$headers = [
			'MIME-Version' => '1.0',
			'From'         => $from,
		];
		if ($unsubscribe)
			$headers['List-Unsubscribe'] = $unsubscribe;
		// recipient
		if (is_array($to)) {
			$to = array_filter($to);
			$to = $to ? implode(', ', $to) : '';
		}
		// other recipients
		if (is_array($cc)) {
			$cc = array_filter($cc);
			$cc = implode(', ', $cc);
		}
		if ($cc)
			$headers['Cc'] = $cc;
		// blinded recipients
		if (is_array($bcc)) {
			$bcc = array_filter($bcc);
			$bcc = implode(', ', $bcc);
		}
		if ($bcc)
			$headers['Bcc'] = $bcc;
		// content type and body
		$content = self::_composeBody($html, $text, $attachments);
		$headers = array_merge($headers, $content['headers']);
		$message = $content['body'];
		// management of the envelope sender
		$params = '';
		if ($envelopeSender)
			$params = "-f$envelopeSender";
		// send the message
		mail($to, $title, $message, $headers, $params);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Build and deliver a message from an instance, through the configured transport if any,
	 * or through PHP's mail() otherwise.
	 * @param	string	$from		Sender (form "Name <address@domain>" or "address@domain").
	 * @param	array	$toList		List of recipients.
	 * @param	string	$title		Message title.
	 * @param	string	$html		HTML content (empty for a plain-text message).
	 * @param	?string	$text		Raw text content.
	 * @param	?array	$attachments	List of attachments.
	 * @param	array	$ccList		List of carbon-copy recipients.
	 * @param	array	$bccList	List of blind carbon-copy recipients.
	 * @param	?string	$unsubscribe	"List-Unsubscribe" header content.
	 * @param	?string	$envelopeSender	Envelope sender.
	 */
	protected function _deliver(string $from, array $toList, string $title, string $html, ?string $text,
	                            ?array $attachments, array $ccList, array $bccList, ?string $unsubscribe,
	                            ?string $envelopeSender) : void {
		$transport = $this->_getTransport();
		if (!$transport) {
			// no transport configured: fall back to the local MTA through mail()
			self::fullMail($from, $toList, $title, $html, $text, $attachments, $ccList, $bccList, $unsubscribe, $envelopeSender);
			return;
		}
		// build the complete message and hand it to the transport datasource
		$content = self::_composeBody($html, $text, $attachments);
		$messageId = self::_generateMessageId($from);
		$message = self::_composeMessage($from, $toList, $ccList, $title, $unsubscribe, $content, $messageId);
		// envelope recipients (RCPT TO): bare addresses of to + cc + bcc, deduplicated
		$recipients = [];
		foreach (array_merge($toList, $ccList, $bccList) as $recipient) {
			$address = self::_cleanAddress((string)$recipient);
			if ($address)
				$recipients[$address] = true;
		}
		$envelope = self::_cleanAddress($envelopeSender ?: $from);
		$transport->set($messageId, [
			'from'       => $envelope,
			'recipients' => array_keys($recipients),
			'message'    => $message,
		]);
	}
	/**
	 * Resolve the transport datasource, from a runtime setter or the 'x-email' configuration.
	 * Priority: runtime setter > 'datasource' (reference) > 'smtp' (inline). Null means "use mail()".
	 * @return	?\Temma\Base\Datasource	The transport datasource, or null.
	 */
	protected function _getTransport() : ?\Temma\Base\Datasource {
		if ($this->_transportResolved)
			return ($this->_transport);
		$this->_transportResolved = true;
		$config = $this->_loader->get('config', null, false);
		if (!($config instanceof \Temma\Web\Config))
			return ($this->_transport);
		// (b) reference to a datasource declared in the 'datasources' configuration section
		$name = $config->xtra('email', 'datasource');
		if (is_string($name) && $name) {
			$registry = $this->_loader->get('dataSources', null, false);
			if ($registry instanceof \Temma\Utils\Registry && isset($registry[$name]) &&
			    $registry[$name] instanceof \Temma\Base\Datasource)
				$this->_transport = $registry[$name];
			else {
				$datasource = $this->_loader->get($name, null, false);
				if ($datasource instanceof \Temma\Base\Datasource)
					$this->_transport = $datasource;
			}
			if ($this->_transport)
				return ($this->_transport);
		}
		// (c) inline SMTP configuration (DSN string or associative array of parameters)
		$smtp = $config->xtra('email', 'smtp');
		if (is_string($smtp) && $smtp)
			$this->_transport = \Temma\Base\Datasource::factory($smtp);
		else if (is_array($smtp) && $smtp)
			$this->_transport = \Temma\Datasources\Smtp::fromParams($smtp);
		return ($this->_transport);
	}
	/**
	 * Build the content-type header and body of a message (shared by the mail() and transport paths).
	 * @param	string	$html		HTML content (empty for a plain-text message).
	 * @param	?string	$text		Raw text content.
	 * @param	?array	$attachments	List of attachments.
	 * @return	array	Associative array with the keys 'headers' (content-related headers) and 'body'.
	 */
	static protected function _composeBody(string $html, ?string $text, ?array $attachments) : array {
		$headers = [];
		if (!$html && !$attachments) {
			$headers['Content-Type'] = 'text/plain; charset=utf-8';
			$body = (string)$text;
		} else if ($html && !$text && !$attachments) {
			$headers['Content-Type'] = 'text/html; charset=utf-8';
			$body = $html;
		} else {
			$mixedBoundary = bin2hex(random_bytes(16));
			$altBoundary = bin2hex(random_bytes(16));
			if ($attachments) {
				$headers['Content-Type'] = "multipart/mixed; boundary=\"$mixedBoundary\"";
				$boundary = $mixedBoundary;
			} else {
				$headers['Content-Type'] = "multipart/alternative; boundary=\"$altBoundary\"";
				$boundary = $altBoundary;
			}
			$message = [];
			$message[] = "This is a multipart message using MIME.";
			if ($text && $html && $attachments) {
				$message[] = "--$mixedBoundary";
				$message[] = "Content-type: multipart/alternative; boundary=\"$altBoundary\"";
				$message[] = '';
				$boundary = $altBoundary;
			}
			if ($text) {
				$message[] = "--$boundary";
				$message[] = "Content-Type: text/plain; charset=UTF-8";
				$message[] = "Content-Transfer-Encoding: 7bit";
				$message[] = '';
				$message[] = $text;
				$message[] = '';
			}
			if ($html) {
				$message[] = "--$boundary";
				$message[] = "Content-Type: text/html; charset=UTF-8";
				$message[] = "Content-Transfer-Encoding: 7bit";
				$message[] = '';
				$message[] = $html;
				$message[] = '';
			}
			$message[] = "--{$boundary}--";
			if ($attachments) {
				foreach ($attachments as $attachment) {
					$attachment['mimetype'] ??= 'application/octet-stream';
					$attachment['filename'] ??= 'unnamed_file.bin';
					$attachment['data'] ??= '';
					$message[] = "--$mixedBoundary";
					$message[] = "Content-Type: " . $attachment['mimetype'] . ";";
					$message[] = "Content-Transfer-Encoding: base64";
					$message[] = "Content-Disposition: attachment;  filename=\"" . $attachment['filename'] . "\"";
					$message[] = '';
					$message[] = chunk_split(base64_encode($attachment['data']));
					$message[] = '';
				}
				$message[] = "--{$mixedBoundary}--";
			}
			// avoid null bytes
			foreach ($message as &$msg)
				$msg = str_replace(chr(0), '', $msg);
			$body = implode("\r\n", $message);
		}
		return (['headers' => $headers, 'body' => $body]);
	}
	/**
	 * Build a complete RFC 5322 message stream for the transport path (no Bcc header).
	 * @param	string	$from		Sender.
	 * @param	array	$toList		List of recipients.
	 * @param	array	$ccList		List of carbon-copy recipients.
	 * @param	string	$title		Message title.
	 * @param	?string	$unsubscribe	"List-Unsubscribe" header content.
	 * @param	array	$content	Content built by _composeBody() (keys 'headers' and 'body').
	 * @param	string	$messageId	Value of the "Message-ID" header.
	 * @return	string	The complete RFC 5322 message.
	 */
	static protected function _composeMessage(string $from, array $toList, array $ccList, string $title,
	                                          ?string $unsubscribe, array $content, string $messageId) : string {
		$lines = [];
		$lines[] = 'From: ' . $from;
		$to = implode(', ', array_filter($toList));
		if ($to !== '')
			$lines[] = 'To: ' . $to;
		$cc = implode(', ', array_filter($ccList));
		if ($cc !== '')
			$lines[] = 'Cc: ' . $cc;
		$lines[] = 'Subject: ' . self::_encodeHeader($title);
		$lines[] = 'Date: ' . date('r');
		$lines[] = 'Message-ID: ' . $messageId;
		$lines[] = 'MIME-Version: 1.0';
		if ($unsubscribe)
			$lines[] = 'List-Unsubscribe: ' . $unsubscribe;
		foreach ($content['headers'] as $name => $value)
			$lines[] = "$name: $value";
		return (implode("\r\n", $lines) . "\r\n\r\n" . $content['body']);
	}
	/**
	 * Encode a header value using RFC 2047 if it contains non-ASCII characters.
	 * @param	string	$str	Header value.
	 * @return	string	The encoded value.
	 */
	static private function _encodeHeader(string $str) : string {
		if (preg_match('/[^\x00-\x7F]/', $str))
			return ('=?UTF-8?B?' . base64_encode($str) . '?=');
		return ($str);
	}
	/**
	 * Generate a "Message-ID" header value, using the sender's domain.
	 * @param	string	$from	Sender.
	 * @return	string	The generated Message-ID (between angle brackets).
	 */
	static private function _generateMessageId(string $from) : string {
		$domain = 'localhost';
		$address = self::_cleanAddress($from);
		if (($pos = mb_strrpos($address, '@')) !== false)
			$domain = mb_substr($address, $pos + 1) ?: 'localhost';
		return ('<' . bin2hex(random_bytes(16)) . "@$domain>");
	}
	/**
	 * Extract a bare email address from a "Name <address>" or "address" string.
	 * @param	string	$address	Address, possibly with a display name.
	 * @return	string	The bare email address.
	 */
	static private function _cleanAddress(string $address) : string {
		if (preg_match('/<([^>]+)>/', $address, $matches))
			return (trim($matches[1]));
		return (trim($address));
	}
	/**
	 * Filter the list of recipients using the list of allowedDomains.
	 * @param	string|array	$to	Recipient of the message, or list of recipients (each recipient in the form "Name <address@domain>" or "address@domain").
	 * @return	array	The filtered list of recipients.
	 */
	private function _filterRecipients(string|array $to) : array {
		$to = is_array($to) ? $to : [$to];
		if (!$this->_allowedDomains)
			return ($to);
		$filtered = [];
		foreach ($this->_allowedDomains as $domain) {
			foreach ($to as $recipient) {
				if (str_ends_with($recipient, "@$domain") || str_ends_with($recipient, "@$domain>")) {
					$filtered[] = $recipient;
				}
			}
		}
		return ($filtered);
	}
}

