<?php

/**
 * Rss view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	2009-2023, Amaury Bouchard
 */

namespace Temma\Views;

use \Temma\Base\Log as TµLog;

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
 *         <li>guid: (optional) Unique identifier of the article (use its URL if not defined).</li>
 *         <li>author: (optional) Name and email address of the article's author.</li>
 *         <li>pubDate: (optional) Date of publication.</li>
 *         <li>image: (optional) URL of the article's image.</li>
 *         <li>imageType: (optional) Type of the article's image ('jpg', 'png', 'gif' or 'webp').</li>
 *         <li>categories: (optional) List of categories.</li>
 *     </ul>
 * </li>
 * </ul>
 */
class Rss extends \Temma\Web\View {
	/** Site domain. */
	private ?string $_domain = null;
	/** Site title. */
	private ?string $_title = null;
	/** Site description. */
	private ?string $_description = null;
	/** Site language. */
	private ?string $_language = null;
	/** Contact email address. */
	private ?string $_contact = null;
	/** Category. */
	private ?string $_category = null;
	/** Copyright. */
	private ?string $_copyright = null;
	/** List of articles. */
	private ?array $_articles = null;

	/** Init. */
	public function init() : void {
		$this->_domain = $this->_response->getData('domain');
		$this->_title = $this->_response->getData('title');
		$this->_description = $this->_response->getData('description');
		$this->_language = $this->_response->getData('language');
		$this->_contact = $this->_response->getData('contact');
		$this->_category = $this->_response->getData('category');
		$this->_copyright = $this->_response->getData('copyright');
		$this->_articles = $this->_response->getData('articles');
	}
	/** Write HTTP headers. */
	public function sendHeaders($headers=null) :void {
		parent::sendHeaders([
			'Content-Type'  => 'application/rss+xml; charset=UTF-8',
			'Cache-Control'	=> 'no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0',
			'Pragma'        => 'no-cache',
			'Expires'       => '0',
		]);
	}
	/** Write body. */
	public function sendBody() : void {
		print('<' . '?xml version="1.0" encoding="UTF-8"?' . ">\n");
		print("<rss version=\"2.0\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">\n");
		print("<channel>\n");
		if ($this->_domain)
			print("\t<link>" . htmlspecialchars($this->_domain, ENT_COMPAT, 'UTF-8') . "/</link>\n");
		if ($this->_title)
			print("\t<title>" . htmlspecialchars($this->_title, ENT_COMPAT, 'UTF-8') . "</title>\n");
		if ($this->_description)
			print("\t<description>" . htmlspecialchars($this->_description, ENT_COMPAT, 'UTF-8') . "</description>\n");
		if ($this->_language)
			print("\t<language>" . htmlspecialchars($this->_language, ENT_COMPAT, 'UTF-8') . "</language>\n");
		if ($this->_contact) {
			print("\t<managingEditor>" . htmlspecialchars($this->_contact, ENT_COMPAT, 'UTF-8') . "</managingEditor>\n");
			print("\t<webMaster>" . htmlspecialchars($this->_contact, ENT_COMPAT, 'UTF-8') . "</webMaster>\n");
		}
		print("\t<generator>Temma RSS generator 1.0.0</generator>\n");
		if ($this->_copyright)
			print("\t<copyright>" . htmlspecialchars($this->_copyright, ENT_COMPAT, 'UTF-8') . "</copyright>\n");
		if ($this->_category)
			print("\t<category>" . htmlspecialchars($this->_category, ENT_COMPAT, 'UTF-8') . "</category>\n");
		else
			print("\t<category>Blog</category>\n");
		foreach ($this->_articles as $article) {
			if (!($article['url'] ?? false) || !trim($article['title'] ?? ''))
				continue;
			print("\t<item>\n");
			print("\t\t<title>" . htmlspecialchars($article['title'], ENT_COMPAT, 'UTF-8') . "</title>\n");
			print("\t\t<link>" . htmlspecialchars($article['url'], ENT_COMPAT, 'UTF-8') . "</link>\n");
			// guid
			if (($article['guid'] ?? false))
				print("\t\t<guid isPermalink=\"true\">" . htmlspecialchars($article['guid'], ENT_COMPAT, 'UTF-8') . "</guid>\n");
			else
				print("\t\t<guid isPermaLink=\"true\">" . htmlspecialchars($article['url'], ENT_COMPAT, 'UTF-8') . "</guid>\n");
			// author
			if (($article['author'] ?? false)) {
				print("\t\t<author>" . htmlspecialchars($article['author'], ENT_COMPAT, 'UTF-8') . "</author>\n");
				print("\t\t<dc:creator>" . htmlspecialchars($article['author'], ENT_COMPAT, 'UTF-8') . "</dc:creator>\n");
			}
			// date
			if (($article['pubDate'] ?? false)) {
				$date = $article['pubDate'];
				$year = (int)substr($date, 0, 4);
				$month = (int)substr($date, 5, 2);
				$day = (int)substr($date, 8, 2);
				$hour = (int)substr($date, 11, 2);
				$min = (int)substr($date, 14, 2);
				$sec = (int)substr($date, 17, 2);
				$time = mktime($hour, $min, $sec, $month, $day, $year);
				$pubDate = date('r', $time);
				print("\t\t<pubDate>$pubDate</pubDate>\n");
			}
			// image
			if (($article['image'] ?? false)) {
				$mimetype = 'image/jpeg';
				if (($article['imageType'] ?? null) === 'png')
					$mimetype = 'image/png';
				else if (($article['imageType'] ?? null) === 'gif')
					$mimetype = 'image/gif';
				else if (($article['imageType'] ?? null) === 'webp')
					$mimietype = 'image/webp';
				print("\t\t<enclosure type=\"$mimetype\" length=\"10000\" url=\"" . htmlspecialchars($article['image'], ENT_COMPAT, 'UTF-8') . "\"/>\n");
			}
			// description
			$content = $article['abstract'] ?? '';
			$content = str_replace('href="/', 'href="' . $this->_domain . '/', $content);
			$content = str_replace('src="/', 'src="' . $this->_domain . '/', $content);
			$content = trim($content);
			if ($content)
				print("\t\t<description>" . htmlspecialchars($content, ENT_COMPAT, 'UTF-8') . "</description>\n");
			// categories
			if (isset($article['categories']) && is_array($article['categories'])) {
				foreach ($article['categories'] as $cat) {
					$cat = trim($cat);
					if (!$cat)
						continue;
					print("\t\t<category>" . htmlspecialchars($cat, ENT_COMPAT, 'UTF-8') . "</category>\n");
				}
			}
			print("\t</item>\n");
		}
		print("</channel>\n</rss>");
	}
}

