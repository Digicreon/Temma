<?php

/**
 * ContentType
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_contenttype
 */

namespace Temma\Attributes;

/**
 * Attribute used to define the content type of the response, sent by the view as Content-Type header
 * instead of the view's default content type.
 *
 * The content type could be a full MIME type, or one of the aliases defined in \Temma\Web\View::MIME_ALIASES
 * ('html', 'xhtml', 'json', 'rss', 'csv', 'calendar').
 * A "text/*" content type without charset information is sent with a "charset=UTF-8" parameter.
 *
 * Examples:
 * - Send all actions of a controller as Markdown:
 * ```
 * use \Temma\Attributes\ContentType as TµContentType;
 *
 * #[TµContentType('text/markdown')]
 * class SomeController extends \Temma\Web\Controller {
 *     public function someAction() { }
 * }
 * ```
 *
 * - The same, but only for one action of the controller:
 * ```
 * use \Temma\Attributes\ContentType as TµContentType;
 *
 * class SomeController extends \Temma\Web\Controller {
 *     #[TµContentType('text/markdown')]
 *     public function someAction() { }
 * }
 * ```
 *
 * - Use an alias:
 * ```
 * #[TµContentType('json')]
 * ```
 *
 * - Reset to the view's default content type (useful on an action, when the attribute is set on the controller):
 * ```
 * #[TµContentType]
 * ```
 *
 * @see	\Temma\Web\Controller::_contentType()
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class ContentType extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	?string	$contentType	(optional) The MIME type (like "image/png") or an alias (like "json").
	 *					Null (or left empty) to use the view's default content type.
	 */
	public function __construct(protected ?string $contentType=null) {
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 * @throws	\Temma\Exceptions\Framework	If the given alias is unknown.
	 */
	public function apply(\Reflector $context) : void {
		$this->_contentType($this->contentType);
	}
}

