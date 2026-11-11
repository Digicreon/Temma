<?php

/**
 * JsonSchemaExportable Interface
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

/**
 * Interface for validators which are able to convert their contract to JSON Schema.
 * Used by \Temma\Utils\DataFilter::toJsonSchema().
 */
interface JsonSchemaExportable {
	/**
	 * Convert a contract to JSON Schema (draft 2020-12).
	 * Annotations (title, description, default value, examples) are managed by DataFilter.
	 * @param	array	$contract	Contract parameters (the 'currentType' key contains the used type).
	 * @return	array	The JSON Schema, as an associative array (empty array if any value is accepted).
	 * @throws	\Temma\Exceptions\IO	If the contract is invalid.
	 */
	public function toJsonSchema(array $contract) : array;
}
