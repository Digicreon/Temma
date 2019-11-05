<?php

/** Object for INI generation. */
class IniExport {
	static private function _generateContent($data) {
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
						$ini .= '"' . addcslashes($data, '\"/') . '"';
					} else
						throw new Exception("Non-scalar data\n" . print_r($data, true));
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
				} else if (is_string($data)) {
					$ini .= '"' . addcslashes($data, '\"/') . '"';
				} else
					throw new Exception("Non-scalar data\n" . print_r($data, true));
				$ini .= "\n";
			}
		}
		return ($ini);
	}
	/**
	 * Generate a INI stream.
	 * @param	mixed	$data		.
	 * @param	bool	$sections	(optional) True if there is sections.
	 * @return	string	The generated INI.
	 */
	static public function generate($data, $sections=false) {
		if (!$sections) {
			return (self::_generateContent($data));
		}
		$ini = '';
		foreach ($data as $sectionName => $sectionValue) {
			$ini .= "[$sectionName]\n";
			$ini .= self::_generateContent($sectionValue);
		}
		return ($init);
	}
}
