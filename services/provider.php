<?php
/**
 * @package   plg_content_fieldsshowon
 * @copyright Copyright (c)2022 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 2, or later; see LICENSE.txt
 */

defined('_JEXEC') || die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\Content\FieldsShowOn\Extension\FieldsShowOn;

return new class implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   5.0.0
	 */
	public function register(Container $container)
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$config  = (array) PluginHelper::getPlugin('content', 'fieldsshowon');
				$subject = $container->get(DispatcherInterface::class);
				$plugin  = new FieldsShowOn($subject, $config);

				$plugin->setApplication(\Joomla\CMS\Factory::getApplication());

				return $plugin;
			}
		);
	}
};
