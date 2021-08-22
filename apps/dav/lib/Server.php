<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bjoern Schiessle <bjoern@schiessle.org>
 * @author Brandon Kirsch <brandonkirsch@github.com>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Georg Ehrke <oc.list@georgehrke.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Citharel <nextcloud@tcit.fr>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <vincent@nextcloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV;

use OC;
use OC\Authentication\TwoFactorAuth\Manager;
use OC\Files\AppData\Factory;
use OC\Files\Filesystem;
use OC\Security\Bruteforce\Throttler;
use OCA\DAV\AppInfo\PluginManager;
use OCA\DAV\CalDAV\BirthdayCalendar\EnablePlugin;
use OCA\DAV\CalDAV\BirthdayService;
use OCA\DAV\CalDAV\ICSExportPlugin\ICSExportPlugin;
use OCA\DAV\CalDAV\Publishing\PublishPlugin;
use OCA\DAV\CalDAV\Schedule\IMipPlugin;
use OCA\DAV\CalDAV\Trashbin\Plugin as TrashbinPlugin;
use OCA\DAV\CalDAV\Search\SearchPlugin;
use OCA\DAV\CalDAV\Schedule\Plugin as SchedulePlugin;
use OCA\DAV\CalDAV\WebcalCaching\Plugin as WebcalCachingPlugin;
use OCA\DAV\CalDAV\Plugin as CalDAVPlugin;
use OCA\DAV\CardDAV\HasPhotoPlugin;
use OCA\DAV\CardDAV\ImageExportPlugin;
use OCA\DAV\CardDAV\MultiGetExportPlugin;
use OCA\DAV\CardDAV\PhotoCache;
use OCA\DAV\Comments\CommentsPlugin;
use OCA\DAV\Connector\Sabre\AnonymousOptionsPlugin;
use OCA\DAV\Connector\Sabre\Auth;
use OCA\DAV\Connector\Sabre\BearerAuth;
use OCA\DAV\Connector\Sabre\BlockLegacyClientPlugin;
use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\CommentPropertiesPlugin;
use OCA\DAV\Connector\Sabre\CopyEtagHeaderPlugin;
use OCA\DAV\Connector\Sabre\DavAclPlugin;
use OCA\DAV\Connector\Sabre\DummyGetResponsePlugin;
use OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin;
use OCA\DAV\Connector\Sabre\FakeLockerPlugin;
use OCA\DAV\Connector\Sabre\FilesPlugin;
use OCA\DAV\Connector\Sabre\FilesReportPlugin;
use OCA\DAV\Connector\Sabre\LockPlugin;
use OCA\DAV\Connector\Sabre\MaintenancePlugin;
use OCA\DAV\Connector\Sabre\PropfindCompressionPlugin;
use OCA\DAV\Connector\Sabre\QuotaPlugin;
use OCA\DAV\Connector\Sabre\Server as SabreServer;
use OCA\DAV\Connector\Sabre\SharesPlugin;
use OCA\DAV\Connector\Sabre\TagsPlugin;
use OCA\DAV\DAV\CustomPropertiesBackend;
use OCA\DAV\DAV\PublicAuth;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\DAV\Files\BrowserErrorPagePlugin;
use OCA\DAV\Files\FileSearchBackend;
use OCA\DAV\Files\LazySearchBackend;
use OCA\DAV\Provisioning\Apple\AppleProvisioningPlugin;
use OCA\DAV\SystemTag\SystemTagPlugin;
use OCA\DAV\Upload\ChunkingPlugin;
use OCP\App\IAppManager;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IPreview;
use OCP\IRequest;
use OCP\ISession;
use OCP\ITagManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\SabrePluginEvent;
use OCP\Share\IManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;
use Sabre\CardDAV\VCFExportPlugin;
use Sabre\DAV\Sync\Plugin as SyncPlugin;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Browser\Plugin as BrowserPlugin;
use Sabre\CalDAV\Notifications\Plugin as NotificationsPlugin;
use Sabre\DAV\PropertyStorage\Plugin as PropertyStoragePlugin;
use Sabre\CalDAV\Subscriptions\Plugin as SubscriptionsPlugin;
use Sabre\DAV\UUIDUtil;

class Server {

	/** @var SabreServer  */
	public $server;

	public function __construct(IRequest $request, string $baseUri) {
		/** @var LoggerInterface $logger */
		$logger = OC::$server->get(LoggerInterface::class);
		$dispatcher = OC::$server->getEventDispatcher();
		/** @var IEventDispatcher $newDispatcher */
		$newDispatcher = OC::$server->get(IEventDispatcher::class);
		/** @var IConfig $config */
		$config = OC::$server->get(IConfig::class);
		/** @var IFactory $i10nFactory */
		$i10nFactory = OC::$server->get(IFactory::class);
		/** @var IUserSession $userSession */
		$userSession = OC::$server->get(IUserSession::class);
		/** @var ISession $session */
		$session = OC::$server->get(ISession::class);

		$root = new RootCollection();
		$this->server = new SabreServer(new CachingTree($root));

		// Add maintenance plugin
		$this->server->addPlugin(new MaintenancePlugin($config, $i10nFactory->get('dav')));

		// Backends
		$authBackend = new Auth(
			$session,
			$userSession,
			$request,
			OC::$server->get(Manager::class),
			OC::$server->get(Throttler::class),
		);

		// Set URL explicitly due to reverse-proxy situations
		$this->server->httpRequest->setUrl($request->getRequestUri());
		$this->server->setBaseUri($baseUri);

		$this->server->addPlugin(new BlockLegacyClientPlugin($config));
		$this->server->addPlugin(new AnonymousOptionsPlugin());
		$authPlugin = new AuthPlugin();
		$authPlugin->addBackend(new PublicAuth());
		$this->server->addPlugin($authPlugin);

		// allow setup of additional auth backends
		$event = new SabrePluginEvent($this->server);
		$dispatcher->dispatch('OCA\DAV\Connector\Sabre::authInit', $event);

		$newAuthEvent = new SabrePluginAuthInitEvent($this->server);
		$newDispatcher->dispatchTyped($newAuthEvent);

		$bearerAuthBackend = new BearerAuth(
			$userSession,
			$session,
			$request
		);
		$authPlugin->addBackend($bearerAuthBackend);
		// because we are throwing exceptions this plugin has to be the last one
		$authPlugin->addBackend($authBackend);

		// debugging
		if ($config->getSystemValueBool('debug', false)) {
			$this->server->addPlugin(new BrowserPlugin());
		} else {
			$this->server->addPlugin(new DummyGetResponsePlugin());
		}

		$this->server->addPlugin(new ExceptionLoggerPlugin('webdav', $logger));
		$this->server->addPlugin(new LockPlugin());
		$this->server->addPlugin(new SyncPlugin());

		// acl
		$acl = new DavAclPlugin();
		$acl->principalCollectionSet = [
			'principals/users',
			'principals/groups',
			'principals/calendar-resources',
			'principals/calendar-rooms',
		];
		$acl->defaultUsernamePath = 'principals/users';
		$this->server->addPlugin($acl);

		// calendar plugins
		if ($this->requestIsForSubtree(['calendars', 'public-calendars', 'system-calendars', 'principals'])) {
			$this->server->addPlugin(new CalDAVPlugin());
			$this->server->addPlugin(new ICSExportPlugin($config, OC::$server->get(LoggerInterface::class)));
			$this->server->addPlugin(new SchedulePlugin($config));
			if ($config->getAppValue('dav', 'sendInvitations', 'yes') === 'yes') {
				$this->server->addPlugin(OC::$server->get(IMipPlugin::class));
			}

			$this->server->addPlugin(OC::$server->get(TrashbinPlugin::class));
			$this->server->addPlugin(new WebcalCachingPlugin($request));
			$this->server->addPlugin(new SubscriptionsPlugin());

			$this->server->addPlugin(new NotificationsPlugin());
			$this->server->addPlugin(new DAV\Sharing\Plugin($authBackend, $request, $config));
			$this->server->addPlugin(new PublishPlugin(
				$config,
				OC::$server->get(IURLGenerator::class)
			));
		}

		/** @var Factory $appDataFactory */
		$appDataFactory = OC::$server->get(Factory::class);

		// addressbook plugins
		if ($this->requestIsForSubtree(['addressbooks', 'principals'])) {
			$this->server->addPlugin(new DAV\Sharing\Plugin($authBackend, $request, $config));
			$this->server->addPlugin(new CardDAV\Plugin());
			$this->server->addPlugin(new VCFExportPlugin());
			$this->server->addPlugin(new MultiGetExportPlugin());
			$this->server->addPlugin(new HasPhotoPlugin());
			$this->server->addPlugin(new ImageExportPlugin(new PhotoCache(
				$appDataFactory->get('dav-photocache'),
				$logger)
			));
		}

		// system tags plugins
		$this->server->addPlugin(new SystemTagPlugin(
			OC::$server->get(ISystemTagManager::class),
			OC::$server->get(IGroupManager::class),
			$userSession
		));

		// comments plugin
		$this->server->addPlugin(new CommentsPlugin(
			OC::$server->get(ICommentsManager::class),
			$userSession
		));

		$this->server->addPlugin(new CopyEtagHeaderPlugin());
		$this->server->addPlugin(new ChunkingPlugin());

		// allow setup of additional plugins
		$dispatcher->dispatch('OCA\DAV\Connector\Sabre::addPlugin', $event);

		// Some WebDAV clients do require Class 2 WebDAV support (locking), since
		// we do not provide locking we emulate it using a fake locking plugin.
		if ($request->isUserAgent([
			'/WebDAVFS/',
			'/OneNote/',
			'/^Microsoft-WebDAV/',// Microsoft-WebDAV-MiniRedir/6.1.7601
		])) {
			$this->server->addPlugin(new FakeLockerPlugin());
		}

		if (BrowserErrorPagePlugin::isBrowserRequest($request)) {
			$this->server->addPlugin(new BrowserErrorPagePlugin());
		}

		$lazySearchBackend = new LazySearchBackend();
		$this->server->addPlugin(new SearchPlugin());

		// wait with registering these until auth is handled and the filesystem is setup
		$this->server->on('beforeMethod:*', function () use ($root, $lazySearchBackend, $userSession, $config, $request, $i10nFactory) {
			// custom properties plugin must be the last one
			$user = $userSession->getUser();
			if ($user !== null) {
				$view = Filesystem::getView();
				$this->server->addPlugin(
					new FilesPlugin(
						$this->server->tree,
						$config,
						$request,
						OC::$server->get(IPreview::class),
						$userSession,
						false,
						!$config->getSystemValueBool('debug', false)
					)
				);

				$this->server->addPlugin(
					new PropertyStoragePlugin(
						new CustomPropertiesBackend(
							$this->server->tree,
							OC::$server->get(IDBConnection::class),
							$userSession->getUser()
						)
					)
				);
				if ($view !== null) {
					$this->server->addPlugin(
						new QuotaPlugin($view));
				}
				$this->server->addPlugin(
					new TagsPlugin(
						$this->server->tree, OC::$server->get(ITagManager::class)
					)
				);
				// TODO: switch to LazyUserFolder
				/** @var IRootFolder $root */
				$root = OC::$server->get(IRootFolder::class);
				$userFolder = $root->getUserFolder($userSession->getUser()->getUID());

				$this->server->addPlugin(new SharesPlugin(
					$this->server->tree,
					$userSession,
					$userFolder,
					OC::$server->get(IManager::class)
				));
				$this->server->addPlugin(new CommentPropertiesPlugin(
					OC::$server->get(ICommentsManager::class),
					$userSession
				));
				$this->server->addPlugin(new SearchPlugin());
				if ($view !== null) {
					$this->server->addPlugin(new FilesReportPlugin(
						$this->server->tree,
						$view,
						OC::$server->get(ISystemTagManager::class),
						OC::$server->get(ISystemTagObjectMapper::class),
						OC::$server->get(ITagManager::class),
						$userSession,
						OC::$server->get(IGroupManager::class),
						$userFolder,
						OC::$server->get(IAppManager::class)
					));
					$lazySearchBackend->setBackend(new FileSearchBackend(
						$this->server->tree,
						$user,
						OC::$server->get(IRootFolder::class),
						OC::$server->get(IManager::class),
						$view
					));
				}
				$this->server->addPlugin(new EnablePlugin(
					$config,
					OC::$server->get(BirthdayService::class)
				));
				$this->server->addPlugin(new AppleProvisioningPlugin(
					$userSession,
					OC::$server->get(IURLGenerator::class),
					OC::$server->get('ThemingDefaults'),
					$request,
					$i10nFactory->get('dav'),
					function () {
						return UUIDUtil::getUUID();
					}
				));
			}

			// register plugins from apps
			$pluginManager = new PluginManager(
				OC::$server,
				OC::$server->get(IAppManager::class)
			);
			foreach ($pluginManager->getAppPlugins() as $appPlugin) {
				$this->server->addPlugin($appPlugin);
			}
			foreach ($pluginManager->getAppCollections() as $appCollection) {
				$root->addChild($appCollection);
			}
		});

		$this->server->addPlugin(
			new PropfindCompressionPlugin()
		);
	}

	/**
	 * @deprecated use start()
	 */
	public function exec(): void {
		$this->start();
	}

	public function start(): void {
		$this->server->start();
	}

	private function requestIsForSubtree(array $subTrees): bool {
		foreach ($subTrees as $subTree) {
			$subTree = trim($subTree, ' /');
			if (strpos($this->server->getRequestUri(), $subTree.'/') === 0) {
				return true;
			}
		}
		return false;
	}
}
