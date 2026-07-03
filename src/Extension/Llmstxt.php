<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Task.Llmstxt
 *
 * @copyright   (C) 2026 Joomill. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomill\Plugin\Task\Llmstxt\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;
use Joomill\Plugin\Task\Llmstxt\Generator\LlmstxtGenerator;

/**
 * Task plugin that generates a curated /llms.txt file and writes it to the site root.
 */
final class Llmstxt extends CMSPlugin implements SubscriberInterface
{
	use TaskPluginTrait;

	/**
	 * Load the plugin language automatically.
	 *
	 * @var  boolean
	 */
	protected $autoloadLanguage = true;

	/**
	 * The routines this plugin offers to the scheduler.
	 *
	 * @var  array
	 */
	private const TASKS_MAP = [
		'llmstxt_generate' => [
			'langConstPrefix' => 'PLG_TASK_LLMSTXT_GENERATE',
			'form'            => 'llmstxt',
			'method'          => 'generate',
		],
	];

	/**
	 * @inheritDoc
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onTaskOptionsList'    => 'advertiseRoutines',
			'onExecuteTask'        => 'standardRoutineHandler',
			'onContentPrepareForm' => 'enhanceTaskItemForm',
		];
	}

	/**
	 * Build the llms.txt content and write it to the site root.
	 *
	 * @param   ExecuteTaskEvent  $event  The task event.
	 *
	 * @return  integer  The exit status.
	 */
	protected function generate(ExecuteTaskEvent $event): int
	{
		$params  = $event->getArgument('params') ?? new \stdClass();
		$baseUrl = trim((string) ($params->base_url ?? ''));

		// Only ever a filename at the web root, never a path.
		$file = basename(trim((string) ($params->output_file ?? 'llms.txt')));

		if ($file === '') {
			$file = 'llms.txt';
		}

		$db        = Factory::getContainer()->get(DatabaseInterface::class);
		$generator = new LlmstxtGenerator($db, $baseUrl);

		try {
			$content = $generator->build($params);
		} catch (\Throwable $e) {
			$this->logTask(Text::sprintf('PLG_TASK_LLMSTXT_LOG_BUILD_FAILED', $e->getMessage()), 'error');

			return Status::KNOCKOUT;
		}

		$path = JPATH_ROOT . '/' . $file;

		if (file_exists($path) && !is_writable($path)) {
			$this->logTask(Text::sprintf('PLG_TASK_LLMSTXT_LOG_NOT_WRITABLE', $path), 'error');

			return Status::KNOCKOUT;
		}

		if (!file_exists($path) && !is_writable(JPATH_ROOT)) {
			$this->logTask(Text::sprintf('PLG_TASK_LLMSTXT_LOG_ROOT_NOT_WRITABLE', JPATH_ROOT), 'error');

			return Status::KNOCKOUT;
		}

		if (File::write($path, $content) === false) {
			$this->logTask(Text::sprintf('PLG_TASK_LLMSTXT_LOG_WRITE_FAILED', $path), 'error');

			return Status::KNOCKOUT;
		}

		$this->logTask(Text::sprintf('PLG_TASK_LLMSTXT_LOG_SUCCESS', $file, \strlen($content)), 'info');

		return Status::OK;
	}
}
