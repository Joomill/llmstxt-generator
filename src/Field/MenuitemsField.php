<?php
/*
 *  package: Joomla LLMs.txt Generator
 *  copyright: Copyright (c) 2026. Jeroen Moolenschot | Joomill
 *  license: GNU General Public License version 3 or later
 *  link: https://www.joomill-extensions.com
 */

namespace Joomill\Plugin\Task\Llmstxt\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Fancy multi-select of every published site menu item.
 *
 * Renders a choices.js fancy-select and ships the full item list (with menutype)
 * as JSON so the section form can, client-side, show only the items belonging to
 * the menu selected in the same subform row.
 */
class MenuitemsField extends ListField
{
	/**
	 * The form field type.
	 *
	 * @var  string
	 */
	protected $type = 'Menuitems';

	/**
	 * Cached menu items.
	 *
	 * @var  ?array
	 */
	private ?array $menuItems = null;

	/**
	 * Render the fancy multi-select and load the row-filtering script.
	 *
	 * @return  string
	 */
	protected function getInput(): string
	{
		$selected = array_map('strval', (array) $this->value);

		$select = HTMLHelper::_(
			'select.genericlist',
			$this->getOptions(),
			$this->name,
			'multiple',
			'value',
			'text',
			$selected,
			$this->id
		);

		// Load choices.js, the fancy-select web component and the row-filtering script.
		$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
		$wa->usePreset('choicesjs')->useScript('webcomponent.field-fancy-select');
		Text::script('JGLOBAL_SELECT_NO_RESULTS_MATCH');
		Text::script('JGLOBAL_SELECT_PRESS_TO_SELECT');
		HTMLHelper::_('script', 'plg_task_llmstxt/sections.js', ['relative' => true, 'version' => 'auto']);

		// Full item list (value/label/menutype) for the client-side menutype filter.
		$data = [];

		foreach ($this->getMenuItems() as $item) {
			$data[] = [
				'value'    => (string) $item->id,
				'label'    => $item->label,
				'menutype' => (string) $item->menutype,
			];
		}

		$json        = htmlspecialchars((string) json_encode($data), ENT_QUOTES, 'UTF-8');
		$placeholder = htmlspecialchars(Text::_('JGLOBAL_TYPE_OR_SELECT_SOME_OPTIONS'), ENT_QUOTES, 'UTF-8');

		return '<joomla-field-fancy-select class="llmstxt-menuitems" data-menuitems="' . $json . '" placeholder="' . $placeholder . '">'
			. $select
			. '</joomla-field-fancy-select>';
	}

	/**
	 * Build the option list for the native select.
	 *
	 * @return  array
	 */
	protected function getOptions(): array
	{
		$options = [];

		foreach ($this->getMenuItems() as $item) {
			$options[] = HTMLHelper::_('select.option', $item->id, $item->label);
		}

		return array_merge(parent::getOptions(), $options);
	}

	/**
	 * Load and cache the published site menu items, with an indented label.
	 *
	 * @return  array
	 */
	private function getMenuItems(): array
	{
		if ($this->menuItems !== null) {
			return $this->menuItems;
		}

		$db    = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'title', 'menutype', 'level']))
			->from($db->quoteName('#__menu'))
			->where($db->quoteName('published') . ' = 1')
			->where($db->quoteName('client_id') . ' = 0')
			->where($db->quoteName('level') . ' > 0')
			->whereNotIn($db->quoteName('type'), ['separator', 'heading'], ParameterType::STRING)
			->order($db->quoteName('menutype') . ' ASC')
			->order($db->quoteName('lft') . ' ASC');

		$db->setQuery($query);
		$items = $db->loadObjectList() ?: [];

		foreach ($items as $item) {
			$item->label = str_repeat('- ', max(0, (int) $item->level - 1)) . $item->title;
		}

		return $this->menuItems = $items;
	}
}
