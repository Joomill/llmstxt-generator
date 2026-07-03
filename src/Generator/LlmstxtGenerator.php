<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Task.Llmstxt
 *
 * @copyright   (C) 2026 Joomill. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomill\Plugin\Task\Llmstxt\Generator;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Builds the llms.txt markdown from menus and content.
 *
 * Kept free of side effects: it returns a string, the plugin writes the file.
 */
class LlmstxtGenerator
{
	/**
	 * @var  DatabaseInterface
	 */
	private DatabaseInterface $db;

	/**
	 * View levels a guest (public) visitor may see.
	 *
	 * @var  array
	 */
	private array $viewLevels;

	/**
	 * Configured absolute base URL (no trailing slash), or empty to auto-detect.
	 *
	 * @var  string
	 */
	private string $baseUrl;

	/**
	 * Constructor.
	 *
	 * @param   DatabaseInterface  $db       The database driver.
	 * @param   string             $baseUrl  Optional absolute site URL for CLI runs.
	 */
	public function __construct(DatabaseInterface $db, string $baseUrl = '')
	{
		$this->db         = $db;
		$this->baseUrl    = rtrim($baseUrl, '/');

		// View levels a public (guest) visitor is allowed to see.
		$this->viewLevels = array_map('intval', Access::getAuthorisedViewLevels(0));
	}

	/**
	 * Build the complete llms.txt document.
	 *
	 * @param   object  $params  The task parameters.
	 *
	 * @return  string
	 */
	public function build(object $params): string
	{
		$out = [];

		$title = $this->oneLine((string) ($params->site_title ?? ''));

		if ($title === '') {
			$title = (string) Factory::getApplication()->get('sitename');
		}

		$out[] = '# ' . $title;
		$out[] = '';

		$summary = $this->oneLine((string) ($params->summary ?? ''));

		if ($summary !== '') {
			$out[] = '> ' . $summary;
			$out[] = '';
		}

		$intro = $this->oneLine((string) ($params->intro ?? ''));

		if ($intro !== '') {
			$out[] = $intro;
			$out[] = '';
		}

		foreach ((array) ($params->sections ?? []) as $section) {
			$section = (object) $section;
			$lines   = ($section->source ?? 'menu') === 'category'
				? $this->buildCategoryLines($section)
				: $this->buildMenuLines($section);

			if (empty($lines)) {
				continue;
			}

			$heading = $this->oneLine((string) ($section->heading ?? ''));
			$out[]   = '## ' . ($heading !== '' ? $heading : 'Links');
			$out[]   = '';

			foreach ($lines as $line) {
				$out[] = $line;
			}

			$out[] = '';
		}

		return rtrim(implode("\n", $out)) . "\n";
	}

	/**
	 * Build link lines from a menu.
	 *
	 * @param   object  $section  The section config.
	 *
	 * @return  string[]
	 */
	private function buildMenuLines(object $section): array
	{
		$menutype = trim((string) ($section->menutype ?? ''));

		if ($menutype === '') {
			return [];
		}

		$limit      = (int) ($section->limit ?? 50);
		$descSource = (string) ($section->description_source ?? 'auto');

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'title', 'path', 'link', 'type', 'access', 'params', 'home']))
			->from($db->quoteName('#__menu'))
			->where($db->quoteName('menutype') . ' = :menutype')
			->where($db->quoteName('published') . ' = 1')
			->where($db->quoteName('client_id') . ' = 0')
			->bind(':menutype', $menutype)
			->order($db->quoteName('lft') . ' ASC');

		$db->setQuery($query);
		$items = $db->loadObjectList() ?: [];

		$lines = [];
		$count = 0;

		foreach ($items as $item) {
			if ($limit > 0 && $count >= $limit) {
				break;
			}

			if (!\in_array((int) $item->access, $this->viewLevels, true)) {
				continue;
			}

			// Skip structural items that are not real pages.
			if (\in_array($item->type, ['separator', 'heading'], true)) {
				continue;
			}

			$url = $this->menuUrl($item);

			if ($url === '') {
				continue;
			}

			$desc = '';

			if ($descSource !== 'none') {
				$desc = (string) (new Registry($item->params))->get('menu-meta_description', '');
			}

			$lines[] = $this->formatLine($item->title, $url, $desc);
			$count++;
		}

		return $lines;
	}

	/**
	 * Build link lines from one or more content categories.
	 *
	 * @param   object  $section  The section config.
	 *
	 * @return  string[]
	 */
	private function buildCategoryLines(object $section): array
	{
		$cats = array_values(array_filter(array_map('intval', (array) ($section->categories ?? []))));

		if (empty($cats)) {
			return [];
		}

		$limit      = (int) ($section->limit ?? 50);
		$descSource = (string) ($section->description_source ?? 'auto');
		$ordering   = (string) ($section->ordering ?? 'created_desc');

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'title', 'alias', 'introtext', 'metadesc', 'catid', 'language', 'access']))
			->from($db->quoteName('#__content'))
			->whereIn($db->quoteName('catid'), $cats)
			->where($db->quoteName('state') . ' = 1');

		switch ($ordering) {
			case 'ordering_asc':
				$query->order($db->quoteName('ordering') . ' ASC');
				break;

			case 'title_asc':
				$query->order($db->quoteName('title') . ' ASC');
				break;

			default:
				$query->order($db->quoteName('created') . ' DESC');
		}

		$db->setQuery($query);
		$items = $db->loadObjectList() ?: [];

		$lines = [];
		$count = 0;

		foreach ($items as $item) {
			if ($limit > 0 && $count >= $limit) {
				break;
			}

			if (!\in_array((int) $item->access, $this->viewLevels, true)) {
				continue;
			}

			$url = $this->articleUrl($item);

			if ($url === '') {
				continue;
			}

			$lines[] = $this->formatLine($item->title, $url, $this->articleDescription($item, $descSource));
			$count++;
		}

		return $lines;
	}

	/**
	 * Build the canonical SEF URL for a menu item.
	 *
	 * @param   object  $item  The menu item row.
	 *
	 * @return  string
	 */
	private function menuUrl(object $item): string
	{
		// External link menu type: use the link as-is.
		if ($item->type === 'url') {
			return $this->absolutise((string) $item->link);
		}

		try {
			$url = Route::link('site', 'index.php?Itemid=' . (int) $item->id, false, Route::TLS_IGNORE, true);
		} catch (\Throwable $e) {
			$url = '';
		}

		// Fall back to the stored SEF path if the router could not resolve it.
		if ($url === '' && $this->baseUrl !== '') {
			$url = $this->baseUrl . '/' . ltrim((string) $item->path, '/');
		}

		return $this->normaliseHost($url);
	}

	/**
	 * Build the canonical SEF URL for an article.
	 *
	 * @param   object  $item  The content row.
	 *
	 * @return  string
	 */
	private function articleUrl(object $item): string
	{
		$internal = RouteHelper::getArticleRoute(
			$item->id . ':' . $item->alias,
			(int) $item->catid,
			$item->language
		);

		try {
			$url = Route::link('site', $internal, false, Route::TLS_IGNORE, true);
		} catch (\Throwable $e) {
			$url = '';
		}

		return $this->normaliseHost($url);
	}

	/**
	 * Pick the description for an article based on the configured source.
	 *
	 * @param   object  $item    The content row.
	 * @param   string  $source  The description source.
	 *
	 * @return  string
	 */
	private function articleDescription(object $item, string $source): string
	{
		if ($source === 'none') {
			return '';
		}

		if ($source === 'introtext') {
			return $this->snippet((string) $item->introtext);
		}

		if ($source === 'metadesc') {
			return $this->oneLine((string) $item->metadesc);
		}

		// Auto: meta description first, fall back to an intro-text snippet.
		$meta = $this->oneLine((string) $item->metadesc);

		return $meta !== '' ? $meta : $this->snippet((string) $item->introtext);
	}

	/**
	 * Format a single markdown link line.
	 *
	 * @param   string  $title  The link title.
	 * @param   string  $url    The absolute URL.
	 * @param   string  $desc   Optional description.
	 *
	 * @return  string
	 */
	private function formatLine(string $title, string $url, string $desc): string
	{
		$line = '- [' . $this->oneLine($title) . '](' . $url . ')';
		$desc = $this->oneLine($desc);

		if ($desc !== '') {
			$line .= ': ' . $desc;
		}

		return $line;
	}

	/**
	 * Make an internal or relative link absolute.
	 *
	 * @param   string  $link  The link.
	 *
	 * @return  string
	 */
	private function absolutise(string $link): string
	{
		if ($link === '' || preg_match('#^https?://#i', $link) || preg_match('#^(mailto:|tel:)#i', $link)) {
			return $link;
		}

		$base = $this->baseUrl !== '' ? $this->baseUrl : rtrim(Uri::root(), '/');

		return $base . '/' . ltrim($link, '/');
	}

	/**
	 * Swap the auto-detected root for the configured base URL (for CLI runs).
	 *
	 * @param   string  $url  The URL.
	 *
	 * @return  string
	 */
	private function normaliseHost(string $url): string
	{
		if ($this->baseUrl === '' || $url === '') {
			return $url;
		}

		$root = rtrim(Uri::root(), '/');

		if ($root !== '' && strpos($url, $root) === 0) {
			return $this->baseUrl . substr($url, \strlen($root));
		}

		return $url;
	}

	/**
	 * Collapse any text to a clean single line: strip shortcodes, tags and whitespace.
	 *
	 * @param   string  $text  The raw text.
	 *
	 * @return  string
	 */
	private function oneLine(string $text): string
	{
		// Remove Joomla-style {shortcodes} such as {changelog element="..."}.
		$text = preg_replace('/\{[^}]*\}/', '', $text);
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/', ' ', $text);

		return trim($text);
	}

	/**
	 * Turn HTML intro text into a short plain-text snippet.
	 *
	 * @param   string   $html  The HTML.
	 * @param   integer  $max   Maximum length.
	 *
	 * @return  string
	 */
	private function snippet(string $html, int $max = 200): string
	{
		$text = $this->oneLine($html);

		if (mb_strlen($text) <= $max) {
			return $text;
		}

		$cut       = mb_substr($text, 0, $max);
		$lastSpace = mb_strrpos($cut, ' ');

		if ($lastSpace !== false) {
			$cut = mb_substr($cut, 0, $lastSpace);
		}

		return rtrim($cut) . '…';
	}
}
