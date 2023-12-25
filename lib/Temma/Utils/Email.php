<?php

/**
 * Email.
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
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
	/** Tell if message sending is disabled. */
	private bool $_disabled = false;
	/** List of allowed domains. */
	private ?array $_allowedDomains = null;
	/** Recipients added to all messages. */
	private ?array $_cc = [];
	/** Blinded recipients added to all messages. */
	private ?array $_bcc = [];
	/** Envelope sender used for all messages. */
	private string $_envelopeSender = '';

	/**
	 * Constructeur.
	 * @param       \Temma\Base\Loader      $loader Composant d'injection de dépendances.
	 */
	public function __construct(\Temma\Base\Loader $loader) {
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
		$this->_disabled = !$enabled;
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
		self::simpleMail($from, $to, $title, $message, $cc, $bcc, $envelopeSender);
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
		self::fullMail($from, $to, $title, $html, $text, $attachments, $cc, $bcc, $unsubscribe, $envelopeSender);
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
	static public function simpleMail(string $from, string|array $to, string $title='', string $message='',
	                                  string|array $cc='', string|array $bcc='', ?string $envelopeSender=null) : void {
		$headers = [ 
			'Content-Type: text/plain; charset=utf-8',
			"From: $from",
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
			$headers[] = "Cc: $cc";
		// blinded recipients
		if (is_array($bcc)) {
			$bcc = array_filter($bcc);
			$bcc = implode(', ', $bcc);
		}
		if ($bcc)
			$headers[] = "Bcc: $bcc";
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
			'MIME-Version: 1.0',
			"From: $from",
		];
		if ($unsubscribe)
			$headers[] = "List-Unsubscribe: $unsubscribe";
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
			$headers[] = "Cc: $cc";
		// blinded recipients
		if (is_array($bcc)) {
			$bcc = array_filter($bcc);
			$bcc = implode(', ', $bcc);
		}
		if ($bcc)
			$headers[] = "Bcc: $bcc";

		if (!$html && !$attachments) {
			$headers[] = 'Content-Type: text/plain; charset=utf-8';
			$message = $text;
		} else if ($html && !$text && !$attachments) {
			$headers[] = 'Content-Type: text/html; charset=utf-8';
			$message = $html;
		} else {
			$mixedBoundary = bin2hex(random_bytes(16));
			$altBoundary = bin2hex(random_bytes(16));
			if ($attachments) {
				$headers[] = "Content-type: multipart/mixed; boundary=\"$mixedBoundary\"";
				$boundary = $mixedBoundary;
			} else {
				$headers[] = "Content-type: multipart/alternative; boundary=\"$altBoundary\"";
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
			$message = implode("\r\n", $message);
		}
		// management of the envelope sender
		$params = '';
		if ($envelopeSender)
			$params = "-f$envelopeSender";
		// send the message
		mail($to, $title, $message, $headers, $params);
	}

	/* ********** PRIVATE METHODS ********** */
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

