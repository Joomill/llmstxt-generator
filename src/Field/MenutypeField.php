<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Task.Llmstxt
 *
 * @copyright   (C) 2026 Joomill Extensions
 * @license     GNU General Public License version 3 or later
 */

namespace Joomill\Plugin\Task\Llmstxt\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

/**
 * Field that lists the available site menus (menu types).
 */
class MenutypeField extends ListField
{
	/**
	 * The form field type.
	 *
	 * @var  string
	 */
	protected $type = 'Menutype';

	/**
	 * Build the list of menu types.
	 *
	 * @return  array
	 */
	protected function getOptions(): array
	{
		$db    = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName(['menutype', 'title']))
			->from($db->quoteName('#__menu_types'))
			->order($db->quoteName('title') . ' ASC');

		$db->setQuery($query);
		$items = $db->loadObjectList() ?: [];

		$options = [
			HTMLHelper::_('select.option', '', Text::_('PLG_TASK_LLMSTXT_MENUTYPE_SELECT')),
		];

		foreach ($items as $item) {
			$options[] = HTMLHelper::_(
				'select.option',
				$item->menutype,
				$item->title . ' (' . $item->menutype . ')'
			);
		}

		return array_merge(parent::getOptions(), $options);
	}
}
