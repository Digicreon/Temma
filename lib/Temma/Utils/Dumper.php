<?php

/**
 * Dumper
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-dumper
 */

namespace Temma\Utils;

use \Temma\Base\Log as TµLog;
use \Temma\Utils\Serializer as TµSerializer;
use \Temma\Utils\ExtendedArray as TµExtendedArray;
use \Temma\Utils\Ansi as TµAnsi;

/**
 * Dumper object used to write the content of a variable in a readable way.
 */
class Dumper {
	/** Constant: color close to black. */
	const COLOR_BLACK = '#334';
	/** Constant : dark color. */
	const COLOR_DARK = '#0069D9';
	/** Constant : medium color. */
	const COLOR_MEDIUM = '#107EF4';
	/** Constant : light color. */
	const COLOR_LIGHT = '#DCE9F7';
	/** Constant : color close to white. */
	const COLOR_WHITE = '#F8F8FF';
	/** Constant : light contrast color. */
	const COLOR_CONTRAST_LIGHT = '#F7E9DC';
	/** Constant : medium contrast color. */
	const COLOR_CONTRAST_MEDIUM = '#FFDBC6';
	/** List of known elements, to avoid recursion. */
	protected array $_known = [];
	/** Indentation, used for text dump. */
	protected int $_indentation = 0;
	/** Stack of array counters. */
	protected array $_arrayCounterStack = [];
	/** Stack of array/object background colors, used for HTML dump. */
	protected array $_lightBgStack = [];
	/** Callback for recursion. */
	protected /*callable*/ $_dumpRecursion;
	/** Callback for null values. */
	protected /*callable*/ $_dumpNull;
	/** Callback for boolean values. */
	protected /*callable*/ $_dumpBool;
	/** Callback for scalar values. */
	protected /*callable*/ $_dumpScalar;
	/** Callback for resource values. */
	protected /*callable*/ $_dumpResource;
	/** Callback for the beginning of an array value. */
	protected /*callable*/ $_dumpArrayStart;
	/** Callback for the end of an array value. */
	protected /*callable*/ $_dumpArrayEnd;
	/** Callback for the beginning of an array item. */
	protected /*callable*/ $_dumpItemStart;
	/** Callback for the end of an array item. */
	protected /*callable*/ $_dumpItemEnd;
	/** Callback for the beginning of an object value. */
	protected /*callable*/ $_dumpObjectStart;
	/** Callback for the end of an object value. */
	protected /*callable*/ $_dumpObjectEnd;
	/** Callback for the beginning of an object property. */
	protected /*callable*/ $_dumpPropertyStart;
	/** Callback for the end of an object property. */
	protected /*callable*/ $_dumpPropertyEnd;

	/**
	 * Factory for text dumper.
	 * @return	\Temma\Utils\Dumper	Instance of the object, configured for text dump.
	 */
	static public function factoryText() : \Temma\Utils\Dumper {
		$dumper = new self();
		$dumper->setCallbacks(
			[$dumper, '_dumpTextRecursion'],
			[$dumper, '_dumpTextNull'],
			[$dumper, '_dumpTextBool'],
			[$dumper, '_dumpTextScalar'],
			[$dumper, '_dumpTextResource'],
			[$dumper, '_dumpTextArrayStart'],
			[$dumper, '_dumpTextArrayEnd'],
			[$dumper, '_dumpTextItemStart'],
			[$dumper, '_dumpTextItemEnd'],
			[$dumper, '_dumpTextObjectStart'],
			[$dumper, '_dumpTextObjectEnd'],
			[$dumper, '_dumpTextPropertyStart'],
			[$dumper, '_dumpTextPropertyEnd'],
		);
		return ($dumper);
	}
	/**
	 * Factory for ANSI dumper.
	 * @return	\Temma\Utils\Dumper	Instance of the object, configured for ANSI dump.
	 */
	static public function factoryAnsi() : \Temma\Utils\Dumper {
		$dumper = new self();
		$dumper->setCallbacks(
			[$dumper, '_dumpAnsiRecursion'],
			[$dumper, '_dumpAnsiNull'],
			[$dumper, '_dumpAnsiBool'],
			[$dumper, '_dumpAnsiScalar'],
			[$dumper, '_dumpAnsiResource'],
			[$dumper, '_dumpAnsiArrayStart'],
			[$dumper, '_dumpAnsiArrayEnd'],
			[$dumper, '_dumpAnsiItemStart'],
			[$dumper, '_dumpAnsiItemEnd'],
			[$dumper, '_dumpAnsiObjectStart'],
			[$dumper, '_dumpAnsiObjectEnd'],
			[$dumper, '_dumpAnsiPropertyStart'],
			[$dumper, '_dumpAnsiPropertyEnd'],
		);
		return ($dumper);
	}
	/**
	 * Factory for HTML dumper.
	 * @return	\Temma\Utils\Dumper	Instance of the object, configured for HTML dump.
	 */
	static public function factoryHtml() : \Temma\Utils\Dumper {
		$dumper = new self();
		$dumper->setCallbacks(
			[$dumper, '_dumpHtmlRecursion'],
			[$dumper, '_dumpHtmlNull'],
			[$dumper, '_dumpHtmlBool'],
			[$dumper, '_dumpHtmlScalar'],
			[$dumper, '_dumpHtmlResource'],
			[$dumper, '_dumpHtmlArrayStart'],
			[$dumper, '_dumpHtmlArrayEnd'],
			[$dumper, '_dumpHtmlItemStart'],
			[$dumper, '_dumpHtmlItemEnd'],
			[$dumper, '_dumpHtmlObjectStart'],
			[$dumper, '_dumpHtmlObjectEnd'],
			[$dumper, '_dumpHtmlPropertyStart'],
			[$dumper, '_dumpHtmlPropertyEnd'],
		);
		return ($dumper);
	}
	/**
	 * Static dumper. On CLI SAPI, dump ANSI text; otherwise dump HTML stream.
	 * @param	mixed	$data	The data to be dumped.
	 * @return	string	The generated string.
	 * @link	https://www.binarytides.com/php-check-running-cli/
	 */
	static public function dump(mixed $data) : string {
		if (php_sapi_name() == 'cli' ||
		    defined('STDIN') ||
		    (!($_SERVER['REMOTE_ADDR'] ?? null) && ($_SERVER['argv'] ?? null))) {
			// CLI
			return (self::dumpAnsi($data));
		}
		return (self::dumpHtml($data));
	}
	/**
	 * Dump the content of a variable and exits.
	 * @param	mixed	$data	The data to be dumped.
	 */
	static public function die(mixed $data) : void {
		print(self::dump($data));
		exit(1);
	}
	/**
	 * Static text dumper.
	 * @param	mixed	$data	The data to be dumped.
	 * @return	string	The text string.
	 */
	static public function dumpText(mixed $data) : string {
		$dumper = self::factoryText();
		return ($dumper->execDump($data));
	}
	/**
	 * Static ANSI dumper.
	 * @param	mixed	$data	The data to be dumped.
	 * @return	string	The ANSI string.
	 */
	static public function dumpAnsi(mixed $data) : string {
		$dumper = self::factoryAnsi();
		return ($dumper->execDump($data));
	}
	/**
	 * Static HTML dumper.
	 * @param	mixed	$data	The data to be dumped.
	 * @return	string	The HTML string.
	 */
	static public function dumpHtml(mixed $data) : string {
		$dumper = self::factoryHtml();
		return ($dumper->execDump($data));
	}
	/**
	 * Callbacks setter.
	 * @param	callable	$dumpRecursion		Callback for recursion.
	 * @param	callable	$dumpNull		Callback for null values.
	 * @param	callable	$dumpBool		Callback for boolean values.
	 * @param	callable	$dumpScalar		Callback for scalar values.
	 * @param	callable	$dumpResource		Callback for resource values.
	 * @param	callable	$dumpArrayStart		Callback for the beginning of array values.
	 * @param	callable	$dumpArrayEnd		Callback for the end of array values.
	 * @param	callable	$dumpItemStart		Callback for the beginning of an array item value.
	 * @param	callable	$dumpItemEnd		Callback for the end of an array item value.
	 * @param	callable	$dumpObjectStart	Callback for the beginning of object values.
	 * @param	callable	$dumpObjectEnd		Callback for the beginning of object values.
	 * @param	callable	$dumpPropertyStart	Callback for the beginning of an object property value.
	 * @param	callable	$dumpPropertyEnd	Callback for the end of an object property value.
	 */
	public function setCallbacks(
		/*callable*/ $dumpRecursion,
		/*callable*/ $dumpNull,
		/*callable*/ $dumpBool,
		/*callable*/ $dumpScalar,
		/*callable*/ $dumpResource,
		/*callable*/ $dumpArrayStart,
		/*callable*/ $dumpArrayEnd,
		/*callable*/ $dumpItemStart,
		/*callable*/ $dumpItemEnd,
		/*callable*/ $dumpObjectStart,
		/*callable*/ $dumpObjectEnd,
		/*callable*/ $dumpPropertyStart,
		/*callable*/ $dumpPropertyEnd,
	) {
		$this->_dumpRecursion = $dumpRecursion;
		$this->_dumpNull = $dumpNull;
		$this->_dumpBool = $dumpBool;
		$this->_dumpScalar = $dumpScalar;
		$this->_dumpResource = $dumpResource;
		$this->_dumpArrayStart = $dumpArrayStart;
		$this->_dumpArrayEnd = $dumpArrayEnd;
		$this->_dumpItemStart = $dumpItemStart;
		$this->_dumpItemEnd = $dumpItemEnd;
		$this->_dumpObjectStart = $dumpObjectStart;
		$this->_dumpObjectEnd = $dumpObjectEnd;
		$this->_dumpPropertyStart = $dumpPropertyStart;
		$this->_dumpPropertyEnd = $dumpPropertyEnd;
	}
	/**
	 * Show the dump of a data.
	 * @param	mixed	$data	The data to dump.
	 * @return	string	The generated dump.
	 */
	public function execDump(mixed $data) : string {
		if (is_null($data))
			return (($this->_dumpNull)());
		if (is_bool($data))
			return (($this->_dumpBool)($data));
		if (is_scalar($data))
			return (($this->_dumpScalar)($data));
		if (is_resource($data))
			return (($this->_dumpResource)($data));
		if (is_array($data)) {
			// manage recursion for arrays with more than 3 values or non scalar values
			if (!(count($data) <= 3 && TµExtendedArray::hasOnlyScalarValues($data)) &&
			    !$this->_addToKnownList($data))
				return (($this->_dumpRecursion)());
			$res = ($this->_dumpArrayStart)($data);
			foreach ($data as $key => $value) {
				$res .= ($this->_dumpItemStart)($key, $value);
				$res .= $this->execDump($value);
				$res .= ($this->_dumpItemEnd)($key, $value);
			}
			$res .= ($this->_dumpArrayEnd)($data);
			return ($res);
		}
		if (is_object($data)) {
			if (!$this->_addToKnownList($data))
				return (($this->_dumpRecursion)());
			// get object reflection
			$reflector = new \ReflectionClass($data);
			$properties = $reflector->getProperties();
			$objectName = $reflector->getName();
			$res = ($this->_dumpObjectStart)($data, $objectName, $properties);
			// create the ordered list of properties
			$list = [
				'public'    => ['static' => [], 'instance' => []],
				'protected' => ['static' => [], 'instance' => []],
				'private'   => ['static' => [], 'instance' => []],
			];
			foreach ($properties as $property) {
				$visibility = $property->isPublic() ? 'public' : ($property->isProtected() ? 'protected' : 'private');
				$staticness = $property->isStatic() ? 'static' : 'instance';
				$property->setAccessible(true);
				$list[$visibility][$staticness][$property->getName()] = $property;
			}
			// loop on object properties
			foreach ($list as $visibility => $sublist) {
				foreach ($sublist as $staticness => $subsublist) {
					ksort($subsublist);
					foreach ($subsublist as $propertyName => $property) {
						$value = $property->getValue($data);
						$res .= ($this->_dumpPropertyStart)($value, $propertyName, $visibility, $staticness);
						$res .= $this->execDump($value);
						$res .= ($this->_dumpPropertyEnd)($value, $propertyName, $visibility, $staticness);
					}
				}
			}
			$res .= ($this->_dumpObjectEnd)($data, $objectName, $properties);
			return ($res);
		}
		return ("<em>Unknown type</em>\n");
	}

	/* ********** TEXT DUMPER PSEUDO-PRIVATE METHODS ********** */
	/** Text recursion. */
	public function _dumpTextRecursion() : string {
		return ("RECURSION\n");
	}
	/** Text null. */
	public function _dumpTextNull() : string {
		return ("null\n");
	}
	/** Text bool. */
	public function _dumpTextBool(bool $data) : string {
		$value = $data ? 'true' : 'false';
		return ("(bool) $value\n");
	}
	/** Text scalar. */
	public function _dumpTextScalar(mixed $data) : string {
		if (is_int($data)) {
			$type = 'int';
		} else if (is_float($data)) {
			$type = 'float';
		} else {
			$type = 'string';
			$data = json_encode($data);
			$data = str_replace("\\n", ("\n" . str_repeat('    ', $this->_indentation)), $data);
		}
		return ("($type) $data\n");
	}
	/** Text array start. */
	public function _dumpTextArrayStart(array $data) : string {
		$this->_indentation++;
		$this->_arrayCounterStack[] = 0;
		return ("(array) " . ($data ? '[' : '[]') . "\n");
	}
	/** Text array item start. */
	public function _dumpTextItemStart(int|string $key, mixed $value) : string {
		$res = str_repeat('    ', $this->_indentation);
		if ($key == end($this->_arrayCounterStack)) {
			$this->_arrayCounterStack[count($this->_arrayCounterStack) - 1]++;
		} else {
			if (is_int($key)) {
				$this->_arrayCounterStack[count($this->_arrayCounterStack) - 1] = $key + 1;
			} else {
				$key = json_encode($key);
			}
			$res .= $key . ' => ';
		}
		return ($res);
	}
	/** Text array item end. */
	public function _dumpTextItemEnd(int|string $key, mixed $value) : string {
		return ('');
	}
	/** Text array end. */
	public function _dumpTextArrayEnd(array $data) : string {
		$this->_indentation--;
		array_pop($this->_arrayCounterStack);
		if (!$data)
			return ('');
		return (str_repeat('    ', $this->_indentation) . "]\n");
	}
	/** Text object start. */
	public function _dumpTextObjectStart(object $data, string $objectName, array $properties) : string {
		$this->_indentation++;
		return ("($objectName) #" . spl_object_id($data) . ($properties ? ' {' : ' {}') . "\n");
	}
	/** Text object property start. */
	public function _dumpTextPropertyStart(mixed $value, string $propertyName, string $visibility, string $staticness) : string {
		$title = (($staticness == 'static') ? 'static ' : '') . $visibility;
		return (str_repeat('    ', $this->_indentation) . "$title \$$propertyName: ");
	}
	/** Text objet property end. */
	public function _dumpTextPropertyEnd(mixed $value, string $propertyName, string $visibility, string $staticness) : string {
		return ('');
	}
	/** Text object end. */
	public function _dumpTextObjectEnd(object $data, string $objectName, array $properties) : string {
		$this->_indentation--;
		if (!$properties)
			return ('');
		return (str_repeat('    ', $this->_indentation) . "}\n");
	}

	/* ********** ANSI DUMPER PSEUDO-PRIVATE METHODS ********** */
	/** ANSI recursion. */
	public function _dumpAnsiRecursion() : string {
		return (TµAnsi::italic("RECURSION\n"));
	}
	/** ANSI null. */
	public function _dumpAnsiNull() : string {
		return (TµAnsi::bold(TµAnsi::color('yellow', "null\n")));
	}
	/** ANSI bool. */
	public function _dumpAnsiBool(bool $data) : string {
		$value = $data ? 'true' : 'false';
		return (TµAnsi::bold(TµAnsi::color('light-red', "$value\n")));
	}
	/** ANSI scalar. */
	public function _dumpAnsiScalar(mixed $data) : string {
		$color = 'light-blue';
		if (is_string($data)) {
			$data = json_encode($data);
			$data = str_replace("\\n", "\n" . str_repeat('    ', $this->_indentation) . ' ', $data);
			$color = 'magenta';
		}
		if (str_starts_with($data, '"') && str_ends_with($data, '"')) {
			return (TµAnsi::color('yellow', '"') .
			        TµAnsi::color($color, mb_substr($data, 1, -1)) .
			        TµAnsi::color('yellow', '"') . "\n");
		}
		return (TµAnsi::color($color, "$data\n"));
	}
	/** ANSI array start. */
	public function _dumpAnsiArrayStart(array $data) : string {
		$this->_indentation++;
		$this->_arrayCounterStack[] = 0;
		return (TµAnsi::color('green', 'array') . ' ' .
		        TµAnsi::color('yellow', ($data ? '[' : '[]')) . "\n");
	}
	/** ANSI array item start. */
	public function _dumpAnsiItemStart(int|string $key, mixed $value) : string {
		$res = str_repeat('    ', $this->_indentation);
		if ($key == end($this->_arrayCounterStack)) {
			$this->_arrayCounterStack[count($this->_arrayCounterStack) - 1]++;
		} else {
			if (is_string($key)) {
				$key = json_encode($key);
				if (str_starts_with($key, '"') && str_ends_with($key, '"')) {
					$res .= TµAnsi::color('yellow', '"') .
						TµAnsi::color('magenta', mb_substr($key, 1, -1)) .
						TµAnsi::color('yellow', '"');
				} else
					$res .= TµAnsi::color('magenta', $key);
			} else {
				$this->_arrayCounterStack[count($this->_arrayCounterStack) - 1] = $key + 1;
				$res .= TµAnsi::color('light-blue', (string)$key);
			}
			$res .= TµAnsi::color('yellow', ' => ');
		}
		return ($res);
	}
	/** ANSI array item end. */
	public function _dumpAnsiItemEnd(int|string $key, mixed $value) : string {
		return ('');
	}
	/** ANSI array end. */
	public function _dumpAnsiArrayEnd(array $data) : string {
		$this->_indentation--;
		array_pop($this->_arrayCounterStack);
		if (!$data)
			return ('');
		return (
			str_repeat('    ', $this->_indentation) .
			TµAnsi::color('yellow', "]\n")
		);
	}
	/** ANSI object start. */
	public function _dumpAnsiObjectStart(object $data, string $objectName, array $properties) : string {
		$this->_indentation++;
		return (
			TµAnsi::color('green', $objectName) .
			TµAnsi::color('gray', ' #' . spl_object_id($data)) .
			TµAnsi::color('yellow', ($properties ? ' {' : ' {}')) .
			"\n"
		);
	}
	/** ANSI object property start. */
	public function _dumpAnsiPropertyStart(mixed $value, string $propertyName, string $visibility, string $staticness) : string {
		$visibility = ($visibility == 'public') ? '+' : (($visibility == 'protected') ? '#' : '-');
		$res = str_repeat('    ', $this->_indentation) .
		       TµAnsi::color('yellow', $visibility);
		if ($staticness == 'static')
			$res .= TµAnsi::underline($propertyName);
		else
			$res .= $propertyName;
		$res .= TµAnsi::color('yellow', ': ');
		return ($res);
	}
	/** ANSI objet property end. */
	public function _dumpAnsiPropertyEnd(mixed $value, string $propertyName, string $visibility, string $staticness) : string {
		return ('');
	}
	/** ANSI object end. */
	public function _dumpAnsiObjectEnd(object $data, string $objectName, array $properties) : string {
		$this->_indentation--;
		if (!$properties)
			return ('');
		return (
			str_repeat('    ', $this->_indentation) .
			TµAnsi::color('yellow', "}\n")
		);
	}

	/* ********** HTML DUMPER PSEUDO-PRIVATE METHODS ********** */
	/** HTML recursion. */
	public function _dumpHtmlRecursion() : string {
		return ('<em>RECURSION</em>');
	}
	/** HTML null. */
	public function _dumpHtmlNull() : string {
		return ("<pre title='null' style='font-weight: bold; color: #0a0;'>null</pre>\n");
	}
	/** HTML bool. */
	public function _dumpHtmlBool(bool $data) : string {
		$value = $data ? 'true' : 'false';
		return ("<pre title='$value' style='font-weight: bold; color: #e44;'>$value</pre>\n");
	}
	/** HTML scalar. */
	public function _dumpHtmlScalar(mixed $data) : string {
		$color = '#00c';
		if (is_int($data)) {
			$type = 'int';
		} else if (is_float($data)) {
			$type = 'float';
		} else {
			$type = 'string';
			$color = '#a0a';
		}
		return ("<pre title='$type' class='tµ-wrap' style='color: $color;'>" . htmlspecialchars($data) . "</pre>\n");
	}
	/** HTML array start. */
	public function _dumpHtmlArrayStart(array $data) : string {
		$count = count($data);
		$this->_lightBgStack[] = !$this->_lightBgStack ? false : !end($this->_lightBgStack);
		$id = bin2hex(random_bytes(5));
		return(
			"<div>
				<tt>
					array ($count)" . ($count ? ':' : '') . "
					<span id='tµ-array-tr-$id' style='color: #666; cursor: pointer; display: none;'
					 onclick=\"document.getElementById('tµ-array-td-$id').style.display = 'inline'; document.getElementById('tµ-array-$id').style.display = 'block'; this.style.display = 'none';\">▶</span>
					<span id='tµ-array-td-$id' style='color: #666; cursor: pointer;'
					 onclick=\"document.getElementById('tµ-array-tr-$id').style.display = 'inline'; document.getElementById('tµ-array-$id').style.display = 'none'; this.style.display = 'none';\">▼</span>
				</tt>
			</div>
			<table id='tµ-array-$id' class='tµ-data'>\n"
		);
	}
	/** HTML array item start. */
	public function _dumpHtmlItemStart(int|string $key, mixed $value) : string {
		$bgColor = end($this->_lightBgStack) ? self::COLOR_WHITE : self::COLOR_LIGHT;
		return (
			"<tr valign='top'>
				<td style='background-color: $bgColor; width: 1%;'><pre>" . htmlspecialchars($key) . "</pre></td>
				<td style='background-color: $bgColor;'>"
		);
	}
	/** HTML array item end. */
	public function _dumpHtmlItemEnd(int|string $key, mixed $value) : string {
		return ("</td></tr>\n");
	}
	/** HTML array end. */
	public function _dumpHtmlArrayEnd(array $data) : string {
		array_pop($this->_lightBgStack);
		return ("</table>\n");
	}
	/** HTML object start. */
	public function _dumpHtmlObjectStart(object $data, string $objectName, array $properties) : string {
		$this->_lightBgStack[] = !$this->_lightBgStack ? false : !end($this->_lightBgStack);
		$id = bin2hex(random_bytes(5));
		return (
			"<div>
				<tt>
					Object [" . htmlspecialchars($objectName) . "]" . ($properties ? ':' : '') . "
					<span id='tµ-object-tr-$id' style='color: #666; cursor: pointer; display: none;'
					 onclick=\"document.getElementById('tµ-object-td-$id').style.display = 'inline'; document.getElementById('tµ-object-$id').style.display = 'block'; this.style.display = 'none';\">▶</span>
					<span id='tµ-object-td-$id' style='color: #666; cursor: pointer;'
					 onclick=\"document.getElementById('tµ-object-tr-$id').style.display = 'inline'; document.getElementById('tµ-object-$id').style.display = 'none'; this.style.display = 'none';\">▼</span>
				</tt>
			</div>
			<table id='tµ-object-$id' class='tµ-data'>\n"
		);
	}
	/** HTML object property start. */
	public function _dumpHtmlPropertyStart(mixed $value, string $propertyName, string $visibility, string $staticness) : string {
		$bgColor = end($this->_lightBgStack) ? self::COLOR_WHITE : self::COLOR_LIGHT;
		$title = (($staticness == 'static') ? 'static ' : '') . $visibility;
		return (
			"<tr valign='top'>
				<td style='background-color: $bgColor; width: 1%;'>
					<pre title='$title'>" .
					"<span style='color: #840;'>" . (($visibility == 'public') ? '+' : (($visibility == 'protected') ? '#' : '-')) . "</span>" .
					"<span" . (($staticness == 'static') ? " style='text-decoration: underline;'" : '') . '>$' . htmlspecialchars($propertyName) .
				"</span></pre></td>
				<td style='background-color: $bgColor;'>"
		);
	}
	/** HTML objet property end. */
	public function _dumpHtmlPropertyEnd(mixed $value, string $propertyName, string $visibility, string $staticness) : string {
		return ("</td></tr>\n");
	}
	/** HTML object end. */
	public function _dumpHtmlObjectEnd(object $data, string $objectName, array $properties) : string {
		array_pop($this->_lightBgStack);
		return ("</table>");
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Add an element to the list of known elements.
	 * @param	mixed	$data	The element to add.
	 * @return	bool	True if the element has been added, false if it was already known (recursion).
	 */
	protected function _addToKnownList(mixed $data) : bool {
		if (is_array($data))
			$id = md5(TµSerializer::encode($data, TµSerializer::JSON));
		else if (is_object($data))
			$id = spl_object_hash($data);
		else
			return (true);
		if (isset($this->_known[$id]))
			return (false);
		$this->_known[$id] = true;
		return (true);
	}
}

