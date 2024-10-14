<?php

/**
 * Smarty modifier to hash data.
 * @param	?string	$content	Content to hash.
 * @param	string	$algo		(optional) Name of the used algorithm. Defaults to SHA-256.
 * @return	string	The hashed string.
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2024, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-smarty_hash
 */
function smarty_modifier_hash(?string $content, string $algo='sha256') {
	return hash($algo, ($content ?? ''));
}

