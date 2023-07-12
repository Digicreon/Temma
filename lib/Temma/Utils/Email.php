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
 * Could be used directly as a staticc object:
 * ```php
 * use \Temma\Utils\Email as TµEmail;
 * TµEmail::simpleMail('luke@rebellion.org', 'vader@empire.com', 'I can't beleive it', 'You are my father?');
 * TµEmail::fullMail('vader@empire.com', 'luke@rebellion.org', 'Yes', '<h1>Yes</h1><p>I am your father</p>');
 * ```
 *
 * The "temma.json" configuration file may contain an "x-email" extended configuration,
 * to define automatic recipients ("cc" and "bcc"), and to define the envelope sender passed to sendmail.
 * ```json
 * {
 *     "x-email": {
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
 * use \Temma\Utils\Email as TµEmail;
 * // initilization at first use
 * $this->_loader->TµEmail->simpleMail( ... );
 * // subsequent calls can be static, the configuration has been set
 * TµEmail::simpleMail( ... );
 * ```
 *
 * Remember you can use the array-like syntax of the loader:
 * ```php
 * $this->_loader['\Temma\Utils\Email']->simpleMail( ... );
 * ```
 *
 * @link	https://www.php.net/manual/en/function.mail.php
 */
class Email implements \Temma\Base\Loadable {
	/** Extended configuration from the "temma.json" file. */
	static private ?array $_config = null;
	/** Recipients added to all messages. */
	static private ?array $_cc = [];
	/** Blinded recipients added to all messages. */
	static private ?array $_bcc = [];
	/** Envelope sender used for all messages. */
	static private string $_envelopeSender = '';

	/**
	 * Constructeur.
	 * @param       \Temma\Base\Loader      $loader Composant d'injection de dépendances.
	 */
	public function __construct(\Temma\Base\Loader $loader) {
		$cc = $loader->config?->xtra('email', 'cc');
		if (is_array($cc))
			self::$_cc = array_filter($cc);
		else if (is_string($cc) && $cc)
			self::$_cc = [$cc];
		$bcc = $loader->config?->xtra('email', 'bcc');
		if (is_array($bcc))
			self::$_bcc = array_filter($bcc);
		else if (is_string($bcc) && $bcc)
			self::$_bcc = [$bcc];
		$envelopeSender = $loader->config?->xtra('email', 'envelopeSender');
		if (is_string($envelopeSender))
			self::$_envelopeSender = trim($envelopeSender);
	}
	/**
	 * Define recipients for all messages.
	 * @param	string|array	$cc	Additional recipients.
	 */
	static public function setCc(string|array $cc) : void {
		self::$_cc = is_array($cc) ? $cc : [$cc];
	}
	/**
	 * Define blinded recipients for all messages.
	 * @param	string|array	$cc	Additional blinded recipients.
	 */
	static public function setBcc(string|array $bcc) : void {
		self::$_bcc = is_array($bcc) ? $bcc : [$bcc];
	}
	/**
	 * Define the envelope sender for all messages.
	 * @param	string	$envelopeSender	The envelope sender.
	 */
	static public function setEnvelopeSender(string $envelopeSender) : void {
		self::$_envelopeSender = trim($envelopeSender);
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
		if (self::$_cc) {
			$cc .= $cc ? ', ' : '';
			$cc .= implode(', ', self::$_cc);
		}
		if ($cc)
			$headers[] = "Cc: $cc";
		// blinded recipients
		if (is_array($bcc)) {
			$bcc = array_filter($bcc);
			$bcc = implode(', ', $bcc);
		}
		if (self::$_bcc) {
			$bcc .= $bcc ? ", " : "";
			$bcc .= implode(', ', self::$_bcc);
		}
		if ($bcc)
			$headers[] = "Bcc: $bcc";
		// management of the envelope sender
		$params = '';
		if ($envelopeSender)
			$params = "-f$envelopeSender";
		else if (isset(self::$_config['envelopeSender']) && self::$_config['envelopeSender'])
			$params = '-f' . self::$_config['envelopeSender'];
		// send the message
		mail($to, $title, $message, implode("\r\n", $headers), $params);
	}
	/**
	 * Send an HTML mail, with or without a raw text version, with or without attached files.
	 * @param	string		$from		Sender of the message (in the form "Name <address@domain>" or "address@domain").
	 * @param	string|array	$to		Recipient of the message, or list of recipients (each recipient in the form "Name <address@domain>" or "address@domain").
	 * @param	string		$title		(optional) Title of the message.
	 * @param	string		$html		(optional) HTML content of the message.
	 * @param	?string		$text		(optional) Raw text content of the message.
	 * @param	?array		$attachments	(optional) Associative array with the list of files to attach, containing these keys:
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
		if ($ubsubscribe)
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
		if (self::$_cc) {
			$cc .= $cc ? ', ' : '';
			$cc .= implode(', ', self::$_cc);
		}
		if ($cc)
			$headers[] = "Cc: $cc";
		// blinded recipients
		if (is_array($bcc)) {
			$bcc = array_filter($bcc);
			$bcc = implode(', ', $bcc);
		}
		if (self::$_bcc) {
			$bcc .= $bcc ? ", " : "";
			$bcc .= implode(', ', self::$_bcc);
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
		else if (isset(self::$_config['envelopeSender']) && self::$_config['envelopeSender'])
			$params = '-f' . self::$_config['envelopeSender'];
		// send the message
		mail($to, $title, $message, implode("\r\n", $headers), $params);
	}
}

