<?php

namespace Temma\Utils;

/**
 * Object for INI file generation.
 *
 * Example:
 * <code>
 * $data = [
 *     'user1' => [
 *         'name' => 'Alice',
 *         'age'  => 28,
 *     ],
 *     'user2' => [
 *         'name' => 'Bob',
 *         'age'  => 54,
 *     ],
 * ];
 * $ini = \Temma\Utils\IniExport::generate($data, true);
 * print_r($ini);
 * </code>
 *
 * Result:
 * <code>
 * [user1]
 * "name"="Alice"
 * "age"=28
 * [user2]
 * "name"="Bob"
 * "age"=5
 * </code>
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2019, Amaury Bouchard
 * @package	Temma
 * @subpackage	Utils
 */
class IniExport {
	/**
	 * Generate a INI stream.
	 * @param	mixed	$data		Data to serialize.
	 * @param	bool	$sections	(optional) True if there is sections.
	 * @return	string	The generated INI.
	 * @throws	\Exception	If a non-scalar data is found.
	 */
	static public function generate(/* mixed */ $data, bool $sections=false) : string {
		if (!$sections) {
			return (self::_generateContent($data));
		}
		$ini = '';
		foreach ($data as $sectionName => $sectionValue) {
			$ini .= "[$sectionName]\n";
			$ini .= self::_generateContent($sectionValue);
		}
		return ($ini);
	}
	/**
	 * Private method, used to export data.
	 * @param	mixed	$data	Data to serialize.
	 * @return	string	The serialized string.
	 */
	static private function _generateContent(/* mixed */ $data) : string {
		$ini = '';
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				foreach ($value as $subvalue) {
					$ini .= '"' . $key . '[]"=';
					if (is_null($subvalue)) {
						$ini .= 'null';
					} else if (is_bool($subvalue)) {
						$ini .= $subvalue ? 'true' : 'false';
					} else if (is_int($subvalue) || is_float($subvalue)) {
						$ini .= $subvalue;
					} else if (is_string($subvalue)) {
						$ini .= '"' . addcslashes($subvalue, '\"/') . '"';
					} else
						throw new \Exception("Non-scalar data\n" . print_r($data, true));
					$ini .= "\n";
				}
			} else {
				$ini .= "\"$key\"=";
				if (is_null($value)) {
					$ini .= 'null';
				} else if (is_bool($value)) {
					$ini .= $value ? 'true' : 'false';
				} else if (is_int($value) || is_float($value)) {
					$ini .= $value;
				} else if (is_string($value)) {
					$ini .= '"' . addcslashes($value, '\"/') . '"';
				} else
					throw new \Exception("Non-scalar data\n" . print_r($data, true));
				$ini .= "\n";
			}
		}
		return ($ini);
	}
}

