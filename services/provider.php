<?php

/**
 * LLMs.txt Generator
 *
 * @copyright   Copyright (c) 2026 Jeroen Moolenschot | Joomill
 * @license     GNU General Public License version 3 or later; see LICENSE
 * @link        https://www.joomill-extensions.com
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomill\Plugin\Task\Llmstxt\Extension\Llmstxt;

return new class () implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 */
	public function register(Container $container): void
	{
		$pluginFactory = function (Container $container) {
			$plugin = new Llmstxt(
				$container->get(DispatcherInterface::class),
				(array) PluginHelper::getPlugin('task', 'llmstxt')
			);
			$plugin->setApplication(Factory::getApplication());

			return $plugin;
		};

		// Lazy loading (Joomla 6.1+ with PHP 8.4+): the plugin class is only
		// instantiated when one of its subscribed events is dispatched.
		// Container::lazy() does not exist on Joomla 5, hence the guard.
		$container->set(
			PluginInterface::class,
			method_exists($container, 'lazy')
				? $container->lazy(Llmstxt::class, $pluginFactory)
				: $pluginFactory
		);
	}
};
