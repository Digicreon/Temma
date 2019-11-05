<?php

/**
 * Extension du client SOAP de PHP, pour supporter l'authentificatoin WSSecurity.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2011, Fine Media
 * @package	FineBase
 * @version	$Id$
 */
class FineSoapClient extends SoapClient {
	/**
	 * Méthode de spécification des identifiants.
	 * @param	string	$username	Login.
	 * @param	string	$password	Mot de passe.
	 */
	public function setUsernameToken($username, $password) {
		$wsseHeader = new FineSoapHeader($username, $password);
		$this->__setSoapHeaders(array($wsseHeader));
	}
}

/**
 * Extension de l'objet SoapHeader, pour supporter l'authentification WSSecurity.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2011, Fine Media
 * @package	FineBase
 * @version	$Id$
 */
class FineSoapHeader extends SoapHeader {
	/** Constructeur. */
	function __construct($login, $password, $ns=null) {
		$timestamp = gmdate('Y-m-d\TH:i:s\Z');
		$nonce = mt_rand();
		$passdigest = base64_encode(
			pack('H*',
				sha1(
					pack('H*', $nonce) . pack('a*', $timestamp) .
					pack('a*', $password))));
		$auth = '<wsse:Security SOAP-ENV:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
	<wsse:UsernameToken>
		<wsse:Username>' . $login . '</wsse:Username>
		<wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">' . $passdigest . '</wsse:Password>
		<wsse:Nonce>' . base64_encode(pack('H*', $nonce)) . '</wsse:Nonce>
		<wsu:Created xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">' . $timestamp . '</wsu:Created>
	</wsse:UsernameToken>
</wsse:Security>
<PJ-CLIENT-ID>CC</PJ-CLIENT-ID>';
		$authvalues = new SoapVar($auth, XSD_ANYXML);
		parent::__construct('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'Security', $authvalues, true);
	}
}

?>
