<?php
/*
 *  package: Joomla LLMs.txt Generator
 *  copyright: Copyright (c) 2026. Jeroen Moolenschot | Joomill
 *  license: GNU General Public License version 3 or later
 *  link: https://www.joomill-extensions.com
 */

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

/**
 * Installation script class for LLMs.txt Generator plugin
 *
 * @since  1.0.0
 */
class PlgTaskLlmstxtInstallerScript implements InstallerScriptInterface
{
    /**
     * Minimum Joomla version to check
     *
     * @var    string
     * @since  1.0.0
     */
    private $minimumJoomlaVersion = '5.0';

    /**
     * Minimum PHP version to check
     *
     * @var    string
     * @since  1.0.0
     */
    private $minimumPHPVersion = JOOMLA_MINIMUM_PHP;

    /**
     * Function called after the extension is installed
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     *
     * @since   1.0.0
     */
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Function called after the extension is updated
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     *
     * @since   1.0.0
     */
    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Function called after the extension is uninstalled
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     *
     * @since   1.0.0
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        $this->removeTasks();

        return true;
    }

    /**
     * Function called before extension installation/update/removal procedure commences
     *
     * @param   string            $type    The type of change (install, update, discover_install or uninstall)
     * @param   InstallerAdapter  $parent  The adapter calling this method
     *
     * @return  boolean  True on success
     *
     * @since   1.0.0
     */
    public function preflight(string $type, InstallerAdapter $parent): bool
    {
        try {
            if ($type !== 'uninstall') {
                // Check for the minimum PHP version before continuing
                if (!empty($this->minimumPHPVersion) && version_compare(PHP_VERSION, $this->minimumPHPVersion, '<')) {
                    Log::add(
                        Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', $this->minimumPHPVersion),
                        Log::WARNING,
                        'jerror'
                    );
                    return false;
                }
                // Check for the minimum Joomla version before continuing
                if (!empty($this->minimumJoomlaVersion) && version_compare(JVERSION, $this->minimumJoomlaVersion, '<')) {
                    Log::add(
                        Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', $this->minimumJoomlaVersion),
                        Log::WARNING,
                        'jerror'
                    );
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            Log::add('Error during preflight check: ' . $e->getMessage(), Log::ERROR, 'llmstxt');
            return false;
        }
    }

    /**
     * Function called after extension installation/update/removal procedure commences
     *
     * @param   string            $type    The type of change (install, update, discover_install or uninstall)
     * @param   InstallerAdapter  $parent  The adapter calling this method
     *
     * @return  boolean  True on success
     *
     * @since   1.0.0
     */
    public function postflight(string $type, InstallerAdapter $parent): bool
    {
        try {
            $this->loadInstallLanguage();

            if ($type === 'install') {
                $this->enablePlugin();
                $this->createDailyTask();
                $this->printInstallMessage();
            }

            if ($type === 'uninstall') {
                $this->printUninstallMessage();
            }

            return true;
        } catch (\Exception $e) {
            Log::add('Error during postflight: ' . $e->getMessage(), Log::ERROR, 'llmstxt');
            // Still return true to not block the installation/uninstallation process
            // The error is logged but we don't want to prevent the process from completing
            return true;
        }
    }

    /**
     * Enable the plugin right after installation
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function enablePlugin(): void
    {
        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('task'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('llmstxt'));
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            Log::add('Could not enable the plugin: ' . $e->getMessage(), Log::WARNING, 'llmstxt');
        }
    }

    /**
     * Create a daily scheduled task for the generate routine
     *
     * Idempotent: it does nothing when a task for this routine already exists,
     * so a re-install never creates a duplicate.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function createDailyTask(): void
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // Do not add a second task if one already exists for this routine.
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('llmstxt_generate'));
            $db->setQuery($query);

            if ((int) $db->loadResult() > 0) {
                return;
            }

            $factory = Factory::getApplication()->bootComponent('com_scheduler')->getMVCFactory();
            $model   = $factory->createModel('Task', 'Administrator', ['ignore_request' => true]);

            $data = [
                'id'              => 0,
                'title'           => 'Generate llms.txt',
                'type'            => 'llmstxt_generate',
                'state'           => 1,
                'execution_rules' => [
                    'rule-type'     => 'interval-days',
                    'interval-days' => 1,
                    'exec-day'      => gmdate('d'),
                    'exec-time'     => '03:00',
                ],
                'params'          => [
                    'base_url'    => '',
                    'output_file' => 'llms.txt',
                    'sections'    => [
                        [
                            'heading'            => 'Main menu',
                            'source'             => 'menu',
                            'menutype'           => 'mainmenu',
                            'description_source' => 'auto',
                            'limit'              => 50,
                        ],
                    ],
                ],
                'priority'        => 0,
            ];

            $model->save($data);
        } catch (\Throwable $e) {
            Log::add('Could not create the scheduled task: ' . $e->getMessage(), Log::WARNING, 'llmstxt');
        }
    }

    /**
     * Remove the scheduled tasks for this routine on uninstall
     *
     * Without the plugin the routine can no longer run, so leaving the tasks
     * behind would only produce failing orphans.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function removeTasks(): void
    {
        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('llmstxt_generate'));
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            Log::add('Could not remove the scheduled tasks: ' . $e->getMessage(), Log::WARNING, 'llmstxt');
        }
    }

    /**
     * Make the plugin install strings available to the script
     *
     * The installer normally auto-loads the .sys.ini; this is a safety net so the
     * install and uninstall screens never show raw language keys.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function loadInstallLanguage(): void
    {
        $language = Factory::getApplication()->getLanguage();
        $language->load('plg_task_llmstxt.sys', JPATH_ADMINISTRATOR)
            || $language->load('plg_task_llmstxt.sys', JPATH_PLUGINS . '/task/llmstxt');
    }

    /**
     * Render the Joomill thank-you and quickstart screen after installation
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function printInstallMessage(): void
    {
        echo '<style>a[target="_blank"]::before {display: none;}</style>';
        echo '<div class="mb-3 text-center"><img src="https://www.joomill-extensions.com/images/joomill-logo.png" alt="Joomill Extensions" /></div>';
        echo '<div class="mb-3 text-center">' . Text::_('PLG_TASK_LLMSTXT_THANKYOU') . '</div>';
        echo '<br>';
        echo '<h3>' . Text::_('PLG_TASK_LLMSTXT_INSTALL_QUICKSTART') . ':</h3>';
        echo '<ul>';
        echo '<li><a style="text-decoration: underline;" href="index.php?option=com_scheduler&view=tasks" target="_blank">' . Text::_('PLG_TASK_LLMSTXT_INSTALL_CONFIGURATION') . '</a></li>';
        echo '<li><a style="text-decoration: underline;" href="https://www.joomill-extensions.com/documentation/llms-txt-generator-plugin" target="_blank">' . Text::_('PLG_TASK_LLMSTXT_INSTALL_NEEDHELP') . '</a></li>';
        echo '</ul>';
        echo '<hr>';
        echo '<div class="text-center">' . Text::_('PLG_TASK_LLMSTXT_FOLLOWME') . ':</div>';
        echo $this->socialIcons();
    }

    /**
     * Render the Joomill thank-you screen after uninstallation
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function printUninstallMessage(): void
    {
        echo '<style>a[target="_blank"]::before {display: none;}</style>';
        echo '<div class="mb-3 text-center"><img src="https://www.joomill-extensions.com/images/joomill-logo.png" alt="Joomill Extensions" /></div>';
        echo '<br>';
        echo '<h3 class="text-center">' . Text::_('PLG_TASK_LLMSTXT_THANKYOU') . '</h3>';
        echo '<br>';
        echo '<div class="text-center">' . Text::_('PLG_TASK_LLMSTXT_FOLLOWME') . ':</div>';
        echo $this->socialIcons();
    }

    /**
     * Render the Joomill social media follow links
     *
     * @return  string  The social links HTML
     *
     * @since   1.0.0
     */
    private function socialIcons(): string
    {
        return '<div class="text-center">'
            . '<a class="m-2" href="https://www.linkedin.com/in/jeroenmoolenschot/" target="_blank"><i class="fa-brands fa-linkedin"> </i></a>'
            . '<a class="m-2" href="https://www.facebook.com/Joomill" target="_blank"><i class="fa-brands fa-facebook-f"> </i></a>'
            . '<a class="m-2" href="https://www.instagram.com/Joomill" target="_blank"><i class="fa-brands fa-instagram"> </i></a>'
            . '<a class="m-2" href="https://bsky.app/profile/joomill.bsky.social" target="_blank"><i class="fa-brands fa-bluesky"> </i></a>'
            . '<a class="m-2" href="https://joomla.social/@joomill" target="_blank"><i class="fa-brands fa-mastodon"> </i></a>'
            . '<a class="m-2" href="https://www.threads.net/@joomill" target="_blank"><i class="fa-brands fa-threads"> </i></a>'
            . '<a class="m-2" href="https://www.twitter.com/Joomill" target="_blank"><i class="fa-brands fa-x-twitter"> </i></a>'
            . '<a class="m-2" href="https://community.joomla.org/service-providers-directory/listings/67:joomill.html" target="_blank"><i class="fa-brands fa-joomla"> </i></a>'
            . '</div>';
    }
}

return new PlgTaskLlmstxtInstallerScript();
