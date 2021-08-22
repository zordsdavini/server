<?php
/**
 * @copyright Copyright (c) 2018, Georg Ehrke.
 *
 * @author Georg Ehrke <oc.list@georgehrke.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Citharel <nextcloud@tcit.fr>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\DAV\CalDAV\InvitationResponse;

use OCA\DAV\AppInfo\PluginManager;
use OCA\DAV\CalDAV\Publishing\PublishPlugin;
use OCA\DAV\Connector\Sabre\AnonymousOptionsPlugin;
use OCA\DAV\Connector\Sabre\BlockLegacyClientPlugin;
use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\DavAclPlugin;
use OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin;
use OCA\DAV\Connector\Sabre\LockPlugin;
use OCA\DAV\Connector\Sabre\MaintenancePlugin;
use OCA\DAV\Connector\Sabre\Server;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\DAV\RootCollection;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Sabre\CalDAV\ICSExportPlugin;
use Sabre\DAV\Auth\Plugin;
use Sabre\VObject\ITip\Message;

class InvitationResponseServer {

	/** @var Server */
	public $server;

	/**
	 * InvitationResponseServer constructor.
	 */
	public function __construct() {
		$baseUri = \OC::$WEBROOT . '/remote.php/dav/';
		$logger = \OC::$server->get(LoggerInterface::class);
		$config = \OC::$server->get(IConfig::class);
		$languageFactory = \OC::$server->get(IFactory::class)->get('dav');
		/** @var IEventDispatcher $dispatcher */
		$dispatcher = \OC::$server->get(IEventDispatcher::class);

		$root = new RootCollection();
		$this->server = new Server(new CachingTree($root));

		// Add maintenance plugin
		$this->server->addPlugin(new MaintenancePlugin($config, $languageFactory));

		// Set URL explicitly due to reverse-proxy situations
		$this->server->httpRequest->setUrl($baseUri);
		$this->server->setBaseUri($baseUri);

		$this->server->addPlugin(new BlockLegacyClientPlugin($config));
		$this->server->addPlugin(new AnonymousOptionsPlugin());
		$this->server->addPlugin(new class() extends Plugin {
			public function getCurrentPrincipal(): string {
				return 'principals/system/public';
			}
		});

		// allow setup of additional auth backends
		$event = new SabrePluginAuthInitEvent($this->server);
		$dispatcher->dispatchTyped($event);

		$this->server->addPlugin(new ExceptionLoggerPlugin('webdav', $logger));
		$this->server->addPlugin(new LockPlugin());
		$this->server->addPlugin(new \Sabre\DAV\Sync\Plugin());

		// acl
		$acl = new DavAclPlugin();
		$acl->principalCollectionSet = [
			'principals/users', 'principals/groups'
		];
		$acl->defaultUsernamePath = 'principals/users';
		$this->server->addPlugin($acl);

		// calendar plugins
		$this->server->addPlugin(new \OCA\DAV\CalDAV\Plugin());
		$this->server->addPlugin(new ICSExportPlugin());
		$this->server->addPlugin(new \OCA\DAV\CalDAV\Schedule\Plugin($config));
		$this->server->addPlugin(new \Sabre\CalDAV\Subscriptions\Plugin());
		$this->server->addPlugin(new \Sabre\CalDAV\Notifications\Plugin());
		//$this->server->addPlugin(new \OCA\DAV\DAV\Sharing\Plugin($authBackend, \OC::$server->getRequest()));
		$this->server->addPlugin(new PublishPlugin(
			$config,
			\OC::$server->get(IURLGenerator::class)
		));

		// wait with registering these until auth is handled and the filesystem is setup
		$this->server->on('beforeMethod:*', function () use ($root) {
			// register plugins from apps
			$pluginManager = new PluginManager(
				\OC::$server,
				\OC::$server->get(IAppManager::class)
			);
			foreach ($pluginManager->getAppPlugins() as $appPlugin) {
				$this->server->addPlugin($appPlugin);
			}
			foreach ($pluginManager->getAppCollections() as $appCollection) {
				$root->addChild($appCollection);
			}
		});
	}

	/**
	 * @param Message $iTipMessage
	 * @return void
	 */
	public function handleITipMessage(Message $iTipMessage): void {
		/** @var \OCA\DAV\CalDAV\Schedule\Plugin $schedulingPlugin */
		$schedulingPlugin = $this->server->getPlugin('caldav-schedule');
		$schedulingPlugin->scheduleLocalDelivery($iTipMessage);
	}
}
