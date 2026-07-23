<?php

/**
 * Smtp
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-smtp
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * SMTP relay management object.
 *
 * This object is used to send emails through a single SMTP relay (external, like Gmail or
 * Mailgun, or local, like Postfix or Exim). It doesn't perform direct-to-MX delivery: there
 * is always a relay.
 *
 * <b>Usage</b>
 * <code>
 * // creation from a DSN (plain / STARTTLS / implicit TLS)
 * $smtp = \Temma\Base\Datasource::factory('smtp://localhost:25');
 * $smtp = \Temma\Base\Datasource::factory('smtp+tls://user:pass@smtp.gmail.com:587');
 * $smtp = \Temma\Base\Datasource::factory('smtps://user:pass@relay:465');
 *
 * // creation from separate parameters (handy for passwords with special characters)
 * $smtp = \Temma\Datasources\Smtp::fromParams([
 *     'host'     => 'smtp.gmail.com',
 *     'port'     => 587,
 *     'security' => 'starttls', // 'none', 'starttls' or 'tls'
 *     'user'     => 'me@gmail.com',
 *     'password' => 's3cr#t',
 * ]);
 *
 * // send a message built elsewhere (structured form, used by \Temma\Utils\Email)
 * $smtp->set('<message-id>', [
 *     'from'       => 'sender@domain.com',      // envelope sender (MAIL FROM)
 *     'recipients' => ['a@x.com', 'b@y.com'],   // envelope recipients (RCPT TO)
 *     'message'    => $rawRfc822Message,        // complete RFC 5322 stream (no Bcc header)
 * ]);
 *
 * // send a raw message, envelope carried in the options (direct use)
 * $smtp->write('<message-id>', $rawRfc822Message, [
 *     'from'       => 'sender@domain.com',
 *     'recipients' => ['a@x.com', 'b@y.com'],
 * ]);
 * </code>
 */
class Smtp extends \Temma\Base\Datasource {
	/** Relay host name. */
	private string $_host;
	/** Relay port. */
	private int $_port;
	/** Connection security: 'none', 'starttls' or 'tls' (implicit). */
	private string $_security;
	/** Authentication login, or null for no authentication. */
	private ?string $_user;
	/** Authentication password. */
	private ?string $_password;
	/** EHLO/HELO host name. */
	private ?string $_helo;
	/** Connection timeout, in seconds. */
	private int $_timeout;
	/** True to verify the TLS certificate. */
	private bool $_verify;
	/** Connection stream. */
	private $_stream = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class from a DSN.
	 * @param	string	$dsn	Connection string ('smtp://', 'smtp+tls://' or 'smtps://').
	 * @return	\Temma\Datasources\Smtp	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Smtp {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Smtp object creation with DSN: '$dsn'.");
		// security mode and default port from the scheme
		if (str_starts_with($dsn, 'smtp+tls://')) {
			$security = 'starttls';
			$defaultPort = 587;
		} else if (str_starts_with($dsn, 'smtps://')) {
			$security = 'tls';
			$defaultPort = 465;
		} else if (str_starts_with($dsn, 'smtp://')) {
			$security = 'none';
			$defaultPort = 25;
		} else {
			TµLog::log('Temma/Base', 'WARN', "Invalid SMTP DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid SMTP DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$url = parse_url($dsn);
		if ($url === false || !isset($url['host']))
			throw new \Temma\Exceptions\Database("Invalid SMTP DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$params = [
			'host'     => $url['host'],
			'port'     => $url['port'] ?? $defaultPort,
			'security' => $security,
			'user'     => isset($url['user']) ? rawurldecode($url['user']) : null,
			'password' => isset($url['pass']) ? rawurldecode($url['pass']) : null,
		];
		if (isset($url['query'])) {
			parse_str($url['query'], $query);
			if (isset($query['helo']))
				$params['helo'] = $query['helo'];
			if (isset($query['timeout']))
				$params['timeout'] = $query['timeout'];
			if (isset($query['verify']))
				$params['verify'] = filter_var($query['verify'], FILTER_VALIDATE_BOOLEAN);
		}
		return (self::fromParams($params));
	}
	/**
	 * Create a new instance of this class from separate parameters.
	 * @param	array	$params	Associative array with the keys 'host' (mandatory), 'port', 'security'
	 *				('none', 'starttls' or 'tls'), 'user', 'password', 'helo', 'timeout', 'verify'.
	 * @return	\Temma\Datasources\Smtp	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the 'host' parameter is missing.
	 */
	static public function fromParams(array $params) : \Temma\Datasources\Smtp {
		$host = $params['host'] ?? null;
		if (!is_string($host) || !$host)
			throw new \Temma\Exceptions\Database("Missing SMTP host.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$security = $params['security'] ?? 'none';
		if (!in_array($security, ['none', 'starttls', 'tls']))
			throw new \Temma\Exceptions\Database("Invalid SMTP security '$security'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$defaultPort = ($security == 'tls') ? 465 : (($security == 'starttls') ? 587 : 25);
		return (new self(
			$host,
			(int)($params['port'] ?? $defaultPort),
			$security,
			isset($params['user']) ? (string)$params['user'] : null,
			isset($params['password']) ? (string)$params['password'] : null,
			isset($params['helo']) ? (string)$params['helo'] : null,
			(int)($params['timeout'] ?? 30),
			isset($params['verify']) ? (bool)$params['verify'] : true
		));
	}
	/**
	 * Constructor.
	 * @param	string	$host		Relay host name.
	 * @param	int	$port		Relay port.
	 * @param	string	$security	Connection security: 'none', 'starttls' or 'tls'.
	 * @param	?string	$user		(optional) Authentication login (no authentication if null).
	 * @param	?string	$password	(optional) Authentication password.
	 * @param	?string	$helo		(optional) EHLO/HELO host name.
	 * @param	int	$timeout	(optional) Connection timeout in seconds (defaults to 30).
	 * @param	bool	$verify		(optional) True to verify the TLS certificate (defaults to true).
	 */
	public function __construct(string $host, int $port, string $security, ?string $user=null, ?string $password=null,
	                            ?string $helo=null, int $timeout=30, bool $verify=true) {
		$this->_host = $host;
		$this->_port = $port;
		$this->_security = $security;
		$this->_user = $user;
		$this->_password = $password;
		$this->_helo = $helo;
		$this->_timeout = $timeout;
		$this->_verify = $verify;
	}
	/** Destructor. Closes the connection. */
	public function __destruct() {
		$this->disconnect();
	}

	/* ********** CONNECTION ********** */
	/**
	 * Open the connection to the relay (EHLO, optional STARTTLS, optional authentication).
	 * @throws	\Temma\Exceptions\Database	If the connection or the SMTP dialog fails.
	 */
	public function connect() {
		if (!$this->_enabled || $this->_stream)
			return;
		$this->reconnect();
	}
	/**
	 * Reopen the connection to the relay.
	 * @throws	\Temma\Exceptions\Database	If the connection or the SMTP dialog fails.
	 */
	public function reconnect() {
		if (!$this->_enabled)
			return;
		$this->disconnect();
		$transport = ($this->_security == 'tls') ? 'ssl' : 'tcp';
		$context = stream_context_create($this->_verify ? [] : [
			'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
		]);
		$errno = 0;
		$errstr = '';
		$stream = @stream_socket_client("$transport://{$this->_host}:{$this->_port}", $errno, $errstr,
		                                (float)$this->_timeout, STREAM_CLIENT_CONNECT, $context);
		if ($stream === false)
			throw new \Temma\Exceptions\Database("Unable to connect to SMTP relay '{$this->_host}:{$this->_port}': $errstr.", \Temma\Exceptions\Database::CONNECTION);
		$this->_stream = $stream;
		stream_set_timeout($this->_stream, $this->_timeout);
		// server greeting
		$this->_expect(220);
		// EHLO
		$helo = $this->_helo ?: (gethostname() ?: 'localhost');
		$capabilities = $this->_command("EHLO $helo", 250);
		// STARTTLS
		if ($this->_security == 'starttls') {
			$this->_command('STARTTLS', 220);
			if (!stream_socket_enable_crypto($this->_stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT))
				throw new \Temma\Exceptions\Database("Unable to enable STARTTLS on SMTP relay '{$this->_host}'.", \Temma\Exceptions\Database::CONNECTION);
			$capabilities = $this->_command("EHLO $helo", 250);
		}
		// authentication
		if ($this->_user !== null)
			$this->_authenticate($capabilities);
	}
	/** Close the connection. */
	public function disconnect() {
		if (!$this->_stream)
			return;
		@fwrite($this->_stream, "QUIT\r\n");
		@fclose($this->_stream);
		$this->_stream = null;
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Send a raw RFC 5322 message, with the envelope given in the options.
	 * @param	string	$key		Message identifier (used for logging only).
	 * @param	string	$value		Complete RFC 5322 message stream (without Bcc header).
	 * @param	mixed	$options	Associative array with the keys 'from' (envelope sender) and 'recipients'
	 *					(string or list of envelope recipients).
	 * @return	bool	True on success.
	 * @throws	\Temma\Exceptions\Database	If the envelope is incomplete or the delivery fails.
	 */
	public function write(string $key, string $value, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		$from = '';
		$recipients = [];
		if (is_array($options)) {
			$from = (string)($options['from'] ?? '');
			$recipients = $options['recipients'] ?? [];
		}
		return ($this->_send($from, is_array($recipients) ? $recipients : [$recipients], $value, $key));
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Send a message given in the structured form ['from' => ..., 'recipients' => [...], 'message' => ...].
	 * @param	string	$key		Message identifier (used for logging only).
	 * @param	mixed	$value		Associative array with the keys 'from', 'recipients' and 'message'.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	bool	True on success.
	 * @throws	\Temma\Exceptions\Database	If the value is malformed or the delivery fails.
	 */
	public function set(string $key, mixed $value=null, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		if (!is_array($value))
			throw new \Temma\Exceptions\Database("SMTP set() expects a structured array ['from', 'recipients', 'message'].", \Temma\Exceptions\Database::FUNDAMENTAL);
		$from = (string)($value['from'] ?? '');
		$recipients = $value['recipients'] ?? [];
		$message = (string)($value['message'] ?? '');
		return ($this->_send($from, is_array($recipients) ? $recipients : [$recipients], $message, $key));
	}
	/**
	 * Disabled mSet().
	 * @param	array	$data		Not used.
	 * @param	mixed	$options	(optional) Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		throw new \Temma\Exceptions\Database("No mSet() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Disabled mWrite().
	 * @param	array	$data		Not used.
	 * @param	mixed	$options	(optional) Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function mWrite(array $data, mixed $options=null) : int {
		throw new \Temma\Exceptions\Database("No mWrite() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Perform an SMTP transaction (MAIL FROM / RCPT TO / DATA) for one message.
	 * @param	string	$from		Envelope sender.
	 * @param	array	$recipients	List of envelope recipients.
	 * @param	string	$message	RFC 5322 message stream.
	 * @param	string	$id		Message identifier (used for logging).
	 * @return	bool	True on success.
	 * @throws	\Temma\Exceptions\Database	If the envelope is incomplete or the delivery fails.
	 */
	private function _send(string $from, array $recipients, string $message, string $id) : bool {
		$from = $this->_cleanAddress($from);
		$recipients = array_values(array_unique(array_filter(array_map([$this, '_cleanAddress'], $recipients))));
		if (!$from || !$recipients)
			throw new \Temma\Exceptions\Database("SMTP send: missing envelope sender or recipients.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$this->connect();
		$this->_command("MAIL FROM:<$from>", 250);
		foreach ($recipients as $recipient)
			$this->_command("RCPT TO:<$recipient>", [250, 251]);
		$this->_command('DATA', 354);
		fwrite($this->_stream, $this->_prepareData($message) . "\r\n.\r\n");
		$this->_expect(250);
		TµLog::log('Temma/Base', 'DEBUG', "SMTP message '$id' sent to " . implode(', ', $recipients) . '.');
		return (true);
	}
	/**
	 * Authenticate on the relay, choosing a mechanism advertised by the server.
	 * @param	array	$capabilities	Lines of the EHLO response.
	 * @throws	\Temma\Exceptions\Database	If no usable mechanism is found or authentication fails.
	 */
	private function _authenticate(array $capabilities) : void {
		$mechanisms = [];
		foreach ($capabilities as $line) {
			if (preg_match('/^\d{3}[ -]AUTH\s+(.+)$/i', $line, $matches))
				$mechanisms = preg_split('/\s+/', strtoupper(trim($matches[1])));
		}
		if (in_array('PLAIN', $mechanisms) || !$mechanisms) {
			$this->_command('AUTH PLAIN ' . base64_encode("\0{$this->_user}\0{$this->_password}"), 235);
		} else if (in_array('LOGIN', $mechanisms)) {
			$this->_command('AUTH LOGIN', 334);
			$this->_command(base64_encode((string)$this->_user), 334);
			$this->_command(base64_encode((string)$this->_password), 235);
		} else {
			throw new \Temma\Exceptions\Database("No supported SMTP authentication mechanism (server offers: " . implode(', ', $mechanisms) . ").", \Temma\Exceptions\Database::CONNECTION);
		}
	}
	/**
	 * Send a command and check the response code.
	 * @param	string		$command	Command to send (without trailing CRLF).
	 * @param	int|array	$expected	Expected response code, or list of accepted codes.
	 * @return	array	The lines of the response.
	 * @throws	\Temma\Exceptions\Database	If the response code is not expected.
	 */
	private function _command(string $command, int|array $expected) : array {
		fwrite($this->_stream, "$command\r\n");
		return ($this->_expect($expected));
	}
	/**
	 * Read a response from the server and check its code.
	 * @param	int|array	$expected	Expected response code, or list of accepted codes.
	 * @return	array	The lines of the response.
	 * @throws	\Temma\Exceptions\Database	If the response code is not expected.
	 */
	private function _expect(int|array $expected) : array {
		$lines = [];
		do {
			$line = fgets($this->_stream, 515);
			if ($line === false)
				throw new \Temma\Exceptions\Database("SMTP read error (no response from '{$this->_host}').", \Temma\Exceptions\Database::CONNECTION);
			$line = rtrim($line, "\r\n");
			$lines[] = $line;
			// a multiline response has a '-' right after the 3-digit code
			$continue = (mb_strlen($line) > 3 && $line[3] == '-');
		} while ($continue);
		$code = (int)mb_substr($lines[0], 0, 3);
		$expected = is_array($expected) ? $expected : [$expected];
		if (!in_array($code, $expected)) {
			$message = implode(' ', $lines);
			throw new \Temma\Exceptions\Database("Unexpected SMTP response: '$message' (expected " . implode('/', $expected) . ").", \Temma\Exceptions\Database::QUERY);
		}
		return ($lines);
	}
	/**
	 * Prepare a message for the DATA command: normalize line endings to CRLF and dot-stuff.
	 * @param	string	$message	Message stream.
	 * @return	string	The prepared stream.
	 */
	private function _prepareData(string $message) : string {
		$message = preg_replace('/\r\n|\r|\n/', "\r\n", $message);
		// dot-stuffing: a line starting with a dot gets an extra leading dot
		$message = preg_replace('/^\./m', '..', $message);
		return ($message);
	}
	/**
	 * Extract a bare email address from a "Name <address>" or "address" string.
	 * @param	string	$address	Address, possibly with a display name.
	 * @return	string	The bare email address.
	 */
	private function _cleanAddress(string $address) : string {
		if (preg_match('/<([^>]+)>/', $address, $matches))
			return (trim($matches[1]));
		return (trim($address));
	}
}

