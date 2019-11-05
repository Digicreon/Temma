<?php

/** Object for human-readable JSON generation. */
class JsonExport {
	/**
	 * Generate a human-readable stream.
	 * @param	mixed	$data	.
	 * @param	int	$indent	(optionnel) Indentation level.
	 * @return	string	The generated JSON.
	 */
	static public function generate($data, $indent=0) {
		$indent++;
		if (is_null($data)) {
			return ('null');
		} else if (is_bool($data)) {
			return ($data ? 'true' : 'false');
		} else if (is_int($data) || is_float($data)) {
			return ($data);
		} else if (is_string($data)) {
			return ('"' . addcslashes($data, '\"/') . '"');
		} else if (is_array($data)) {
			$result = '';
			// key type checking
			$numericKeys = true;
			$i = 0;
			foreach ($data as $key => $subdata) {
				if (!is_numeric($key) || $key != $i) {
					$numericKeys = false;
					break;
				}
				$i++;
			}
			// writing
			$result .= ($numericKeys ? '[' : '{') . "\n";
			$loopNbr = 1;
			foreach ($data as $key => $subdata) {
				$result .= self::_indent($indent);
				if (!$numericKeys)
					$result .= '"' . addcslashes($key, "\"'") . '": ';
				$result .= self::generate($subdata, $indent);
				if ($loopNbr < count($data))
					$result .= ',';
				$result .= "\n";
				$loopNbr++;
			}
			$result .= self::_indent($indent - 1);
			$result .= $numericKeys ? ']' : '}';
			return ($result);
		} else
			throw new Exception("Non-scalar data\n" . print_r($data, true));
		$indent--;
	}
	/**
	 * Add indentation.
	 * @param	int	$nbr	Number of tabs.
	 * @return	string	The generated text.
	 */
	static private function _indent($nbr) {
		$result = '';
		for ($i = 0; $i < $nbr; $i++)
			$result .= "\t";
		return ($result);
	}
}
