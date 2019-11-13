<?php

namespace Temma\Views;

/**
 * View for RSS streams.
 *
 * This view read data from template variables:
 * <ul>
 * <li>domain : Domain name of the website.</li>
 * <li>title : Title of the website.</li>
 * <li>description : Description of the website.</li>
 * <li>language : Language of the site (fr, en, ...).</li>
 * <li>Contact : Contact email address.</li>
 * <li>articles : Array of associative arrays, each one with an aritcle data:
 *     <ul>
 *         <li>dateCreation: Creation date of the article.</li>
 *         <li>abstract: Short description of the article.</li>
 *         <li>url: URL of the article.</li>
 *         <li>title: Title of the article.</li>
 *     </ul>
 * </li>
 * </ul>
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	2009-2019, Amaury Bouchard
 * @package	Temma
 * @subpackage	Views
 */
class RssView extends \Temma\Web\View {
	/** Site title. */
	private $_title = null;
	/** Site URL. */
	private $_link = null;
	/** Site description. */
	private $_description = null;
	/** Site language. */
	private $_language = null;
	/** Contact email address. */
	private $_contact = null;
	/** List of articles. */
	private $_articles = null;

	/** Init. */
	public function init() {
		$this->_domain = $this->_response->getData('domain');
		$this->_title = $this->_response->getData('title');
		$this->_description = $this->_response->getData('description');
		$this->_language = $this->_response->getData('language');
		$this->_contact = $this->_response->getData('contact');
		$this->_articles = $this->_response->getData('articles');
	}
	/** iWrite HTTP headers. */
	public function sendHeaders($headers=null) {
		parent::sendHeaders([
			'Content-Type'	=> 'application/rss+xml; charset=UTF-8',
		]);
	}
	/** Write body. */
	public function sendBody() {
		print('<' . '?xml version="1.0" encoding="UTF-8"?' . ">\n");
		print("<rss version=\"2.0\">\n");
		print("<channel>\n");
		print("\t<link>http://" . $this->_domain . "/</link>\n");
		if (!empty($this->_title))
			print("\t<title>" . $this->_title . "</title>\n");
		if (!empty($this->_description))
			print("\t<description>" . $this->_description . "</description>\n");
		if (!empty($this->_language))
			print("\t<language>" . $this->_language . "</language>\n");
		if (!empty($this->_contact)) {
			print("\t<managingEditor>" . $this->_contact . "</managingEditor>\n");
			print("\t<webMaster>" . $this->_contact . "</webMaster>\n");
		}
		print("\t<generator>Temma RSS generator 0.5.0</generator>\n");
		print("\t<copyright>Copyright, Temma.net</copyright>\n");
		print("\t<category>Blog</category>\n");
		print("\t<docs>http://blogs.law.harvard.edu/tech/rss</docs>\n");
		print("\t<ttl>1440</ttl>\n");
		foreach ($this->_articles as $article) {
			if (isset($article['creationDate']))
				$date = $article['creationDate'];
			if (isset($date)) {
				$year = (int)substr($date, 0, 4);
				$month = (int)substr($date, 5, 2);
				$day = (int)substr($date, 8, 2);
				$hour = (int)substr($date, 11, 2);
				$min = (int)substr($date, 14, 2);
				$sec = (int)substr($date, 17, 2);
			}
			$time = mktime($hour, $min, $sec, $month, $day, $year);
			$pubDate = date('r', $time);
			$content = (!isset($article['abstract']) || empty($article['abstract'])) ? '' : ($article['abstract'] . ' (...)');
			$content = str_replace('href="/', 'href="http://' . $this->_domain . '/', $content);
			$content = str_replace('src="/', 'src="http://' . $this->_domain . '/', $content);
			$content = trim($content);
			if (isset($article['url']) && !empty($article['url']))
				$url = $article['url'];
			else
				$url = 'http://' . $this->_domain . '/' . $article['folderName'] .
					(($article['folderName'] == $article['name']) ? "" : ('/' . $article['name']));
			print("\t<item>\n");
			print("\t\t<title>" . str_replace('&', '&amp;', $article['title']) . "</title>\n");
			print("\t\t<pubDate>$pubDate</pubDate>\n");
			print("\t\t<link>" . $url . "</link>\n");
			print("\t\t<guid isPermaLink=\"true\">" . $url . "</guid>\n");
			if (!empty($content))
				print("\t\t<description>" . htmlspecialchars($content, ENT_COMPAT, 'UTF-8') . "</description>\n");
			print("\t</item>\n");
		}
		print("</channel>\n</rss>");
	}
}

