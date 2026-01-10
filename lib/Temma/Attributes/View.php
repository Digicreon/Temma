<?php

/**
 * View
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_view
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;

/**
 * Attribute used to define the view used by a controller or an action.
 *
 * Examples:
 * - Tell Temma to use the \Temma\Views\Json view on all actions of a controller:
 * use \Temma\Attributes\View as TµView;
 *
 * #[TµView('\Temma\Views\Json')]
 * class SomeController extends \Temma\Web\Controller {
 *     public function someAction() { }
 * }
 *
 * - The same, but only for one action of the controller:
 * use \Temma\Attributes\View as TµView;
 *
 * class SomeController extends \Temma\Web\Controller {
 *     #[TµView('\Temma\Views\Json')]
 *     public function someAction() { }
 * }
 * 
 * - The same (written differently):
 * #[TµView(\Temma\Views\Json::class)]
 *
 * - The same (telling to use Temma's standard Json view):
 * #[TµView('~Json')]
 *
 * - Tell Temma to use the standard RSS view:
 * #[TµView('~Rss')]
 *
 * - Reset to the default view (as configured in the 'temma.json' configuration file):
 * #[TµView]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class View extends \Temma\Web\Attribute {
	/** Constant: map MIME types to view objects. */
	const MIME_TO_VIEW = [
		'application/json'    => '\Temma\Views\Json',
		'application/rss+xml' => '\Temma\Views\Rss',
		'text/csv'            => '\Temma\Views\Csv',
		'text/calendar'       => '\Temma\Views\ICal',
	];

	/**
	 * Constructor.
	 * @param	null|false|string	$view		(optional) The fully-namespaced name of the view object to use.
	 *							If left empty (or set to null), use the default view as configured
	 *							in the 'temma.json' configuration file.
	 *							If set to false, disable the processing of the view.
	 * @param	null|bool|string|array	$adaptative	(optional) Content negotiation option. Not used if set to null or false.
	 *							If set to true, manage JSON/RSS/CSV/iCal view from Accept HTTP header.
	 *							String: a comma-separated list accepted values in the Accept HTTP header.
	 *							Array: a list of accepted values in the Accept HTTP header.
	 *							Associative array: keys are Accept header content, values are object names.
	 *							If the Accept HTTP header value is not found, the default view is used.
	 */
	public function __construct(
		protected null|false|string $view=null,
		protected null|bool|string|array $adaptative=null,
	) {
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 */
	public function apply(\Reflector $context) : void {
		if ($this->adaptative) {
			if ($this->adaptative === true) {
				// try automatic adaptative view
				if ($this->_manageAutomaticAdaptativeView())
					return;
			} else if (is_string($this->adaptative)) {
				// convert to array
				$this->adaptative = array_map(function($item) {
					return (trim($item));
				}, explode(',', $this->adaptative));
			}
			if (is_array($this->adaptative)) {
				if ($this->_manageArrayAdaptativeView($this->adaptative))
					return;
			}
		}
		// fallback to the main view
		$this->_response->setView($this->view);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Manage automatic adaptative view.
	 * @return	bool	True if the view has been modified.
	 */
	private function _manageAutomaticAdaptativeView() : bool {
		$acceptedFormats = $this->_request->getAcceptedFormats();
		foreach ($acceptedFormats as $acceptedFormat) {
			if ($acceptedFormat == 'text/html')
				break;
			foreach (self::MIME_TO_VIEW as $mime => $view) {
				if ($acceptedFormat == $mime) {
					$this->_response->setView($view);
					return (true);
				}
			}
		}
		return (false);
	}
	/**
	 * Manage adptative view configured from an array.
	 * @param	array	$config	Cofiguration.
	 * @return	bool	True if the view has been modified.
	 */
	private function _manageArrayAdaptativeView(array $config) : bool {
		// reformat the configuration
		$newConfig = [];
		foreach ($config as $key => $value) {
			if (!is_int($key)) {
				$newConfig[$key] = $value;
				continue;
			}
			if (isset(self::MIME_TO_VIEW[$value]))
				$newConfig[$value] = self::MIME_TO_VIEW[$value];
		}
		// manage accepted formats
		$acceptedFormats = $this->_request->getAcceptedFormats();
		foreach ($acceptedFormats as $acceptedFormat) {
			foreach ($newConfig as $mimetype => $object) {
				if (\Temma\Utils\Text::mimeTypesMatch($mimetype, $acceptedFormat)) {
					$this->_response->setView($object);
					return (true);
				}
			}
		}
		return (false);
	}
}

