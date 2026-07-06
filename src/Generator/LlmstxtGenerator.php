<?php
/*
 *  package: Joomla LLMs.txt Generator
 *  copyright: Copyright (c) 2026. Jeroen Moolenschot | Joomill
 *  license: GNU General Public License version 3 or later
 *  link: https://www.joomill-extensions.com
 */

namespace Joomill\Plugin\Task\Llmstxt\Generator;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
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
	 * Whether to strip the SEF language code from generated URLs.
	 *
	 * @var  boolean
	 */
	private bool $stripLang = false;

	/**
	 * SEF language codes (e.g. en, nl) of the published site languages.
	 *
	 * @var  string[]
	 */
	private array $langCodes = [];

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
		$this->stripLang = (int) ($params->remove_language_code ?? 0) === 1;
		$this->langCodes = $this->stripLang ? $this->loadLanguageCodes() : [];

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

			switch ($section->source ?? 'menu') {
				case 'category':
					$lines = $this->buildCategoryLines($section);
					break;

				case 'articles':
					$lines = $this->buildArticleLines($section);
					break;

				default:
					$lines = $this->buildMenuLines($section);
			}

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

		$limit          = (int) ($section->limit ?? 50);
		$descSource     = (string) ($section->description_source ?? 'auto');
		$include        = $this->intList($section->menu_include ?? []);
		$exclude        = $this->intList($section->menu_exclude ?? []);
		$includeSubmenu = (int) ($section->include_submenu ?? 1) === 1;
		$language       = trim((string) ($section->language ?? ''));

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'title', 'path', 'link', 'type', 'access', 'params', 'home', 'level']))
			->from($db->quoteName('#__menu'))
			->where($db->quoteName('menutype') . ' = :menutype')
			->where($db->quoteName('published') . ' = 1')
			->where($db->quoteName('client_id') . ' = 0')
			->bind(':menutype', $menutype)
			->order($db->quoteName('lft') . ' ASC');

		// Optional language filter (language-neutral items are always kept).
		if ($language !== '') {
			$query->whereIn($db->quoteName('language'), [$language, '*'], ParameterType::STRING);
		}

		$db->setQuery($query);
		$items = $db->loadObjectList() ?: [];

		$lines = [];
		$count = 0;

		foreach ($items as $item) {
			if ($limit > 0 && $count >= $limit) {
				break;
			}

			$id = (int) $item->id;

			if (!empty($include)) {
				// Allowlist set: keep only the selected items (explicit choice wins).
				if (!\in_array($id, $include, true)) {
					continue;
				}
			} elseif (!$includeSubmenu && (int) $item->level > 1) {
				// No allowlist: optionally keep only top-level items.
				continue;
			}

			// Blocklist: always drop excluded items.
			if (\in_array($id, $exclude, true)) {
				continue;
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
		$cats = $this->intList($section->categories ?? []);

		if (empty($cats)) {
			return [];
		}

		if ((int) ($section->include_subcategories ?? 0) === 1) {
			$cats = $this->expandCategories($cats);
		}

		return $this->buildContentLines($section, $cats);
	}

	/**
	 * Build link lines from all published articles (no category restriction).
	 *
	 * @param   object  $section  The section config.
	 *
	 * @return  string[]
	 */
	private function buildArticleLines(object $section): array
	{
		return $this->buildContentLines($section, []);
	}

	/**
	 * Build link lines from content articles, optionally restricted to categories.
	 *
	 * @param   object  $section  The section config.
	 * @param   int[]   $cats     Category ids to restrict to, or empty for all.
	 *
	 * @return  string[]
	 */
	private function buildContentLines(object $section, array $cats): array
	{
		$limit      = (int) ($section->limit ?? 50);
		$descSource = (string) ($section->description_source ?? 'auto');
		$ordering   = (string) ($section->ordering ?? 'created_desc');
		$language   = trim((string) ($section->language ?? ''));
		$include    = $this->intList($section->article_include ?? []);
		$exclude    = $this->intList($section->article_exclude ?? []);
		$maxAge     = (int) ($section->max_age ?? 0);

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'title', 'alias', 'introtext', 'metadesc', 'catid', 'language', 'access']))
			->from($db->quoteName('#__content'))
			->where($db->quoteName('state') . ' = 1');

		if (!empty($cats)) {
			$query->whereIn($db->quoteName('catid'), $cats);
		}

		// Allowlist / blocklist of specific articles.
		if (!empty($include)) {
			$query->whereIn($db->quoteName('id'), $include);
		}

		if (!empty($exclude)) {
			$query->whereNotIn($db->quoteName('id'), $exclude);
		}

		// Optional maximum age: only articles published within the last N days (0 = no limit).
		if ($maxAge > 0) {
			$threshold = Factory::getDate('now', 'UTC')->sub(new \DateInterval('P' . $maxAge . 'D'))->toSql();
			$query->where(
				'COALESCE(' . $db->quoteName('publish_up') . ', ' . $db->quoteName('created') . ') >= ' . $db->quote($threshold)
			);
		}

		// Optional language filter (language-neutral items are always kept).
		if ($language !== '') {
			$query->whereIn($db->quoteName('language'), [$language, '*'], ParameterType::STRING);
		}

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
	 * Normalise a form value (array or scalar) to a list of positive integers.
	 *
	 * @param   mixed  $value  The raw value.
	 *
	 * @return  int[]
	 */
	private function intList($value): array
	{
		return array_values(array_filter(array_map('intval', (array) $value)));
	}

	/**
	 * Expand a set of content categories with all their published descendants.
	 *
	 * Uses the nested-set (lft/rgt) bounds of the selected categories.
	 *
	 * @param   int[]  $cats  The selected category ids.
	 *
	 * @return  int[]
	 */
	private function expandCategories(array $cats): array
	{
		if (empty($cats)) {
			return $cats;
		}

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName(['lft', 'rgt']))
			->from($db->quoteName('#__categories'))
			->whereIn($db->quoteName('id'), $cats)
			->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'));

		$db->setQuery($query);
		$bounds = $db->loadObjectList() ?: [];

		$all = $cats;

		foreach ($bounds as $bound) {
			$sub = $db->getQuery(true)
				->select($db->quoteName('id'))
				->from($db->quoteName('#__categories'))
				->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
				->where($db->quoteName('published') . ' = 1')
				->where($db->quoteName('lft') . ' > ' . (int) $bound->lft)
				->where($db->quoteName('rgt') . ' < ' . (int) $bound->rgt);

			$db->setQuery($sub);
			$all = array_merge($all, array_map('intval', $db->loadColumn() ?: []));
		}

		return array_values(array_unique($all));
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

		return $this->stripLanguageCode($this->normaliseHost($url));
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

		return $this->stripLanguageCode($this->normaliseHost($url));
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
	 * Remove the SEF language code (e.g. /en) from the start of an internal URL.
	 *
	 * Only the first path segment right after the site root is removed, and only
	 * when it matches a published language's SEF code. External links are untouched.
	 *
	 * @param   string  $url  The absolute URL.
	 *
	 * @return  string
	 */
	private function stripLanguageCode(string $url): string
	{
		if (!$this->stripLang || $url === '' || empty($this->langCodes)) {
			return $url;
		}

		$root = $this->baseUrl !== '' ? $this->baseUrl : rtrim(Uri::root(), '/');

		if ($root === '' || strpos($url, $root) !== 0) {
			return $url;
		}

		$rest = substr($url, \strlen($root));

		foreach ($this->langCodes as $sef) {
			$prefix = '/' . $sef;

			if ($rest === $prefix || strpos($rest, $prefix . '/') === 0) {
				return $root . substr($rest, \strlen($prefix));
			}
		}

		return $url;
	}

	/**
	 * Load the SEF codes of the published site languages.
	 *
	 * @return  string[]
	 */
	private function loadLanguageCodes(): array
	{
		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName('sef'))
			->from($db->quoteName('#__languages'))
			->where($db->quoteName('published') . ' = 1');

		$db->setQuery($query);

		return array_values(array_filter(array_map('strval', $db->loadColumn() ?: [])));
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
