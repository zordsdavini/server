<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Bjoern Schiessle <bjoern@schiessle.org>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
// load needed apps
use OC\Files\Filesystem;
use OC\Files\Storage\Wrapper\PermissionsMask;
use OC\Files\View;
use OC\Security\Bruteforce\Throttler;
use OCA\DAV\Files\Sharing\FilesDropPlugin;
use OCA\DAV\Files\Sharing\PublicLinkCheckPlugin;
use OCA\DAV\Storage\PublicOwnerWrapper;
use OCA\FederatedFileSharing\FederatedShareProvider;
use OCP\Constants;
use OCP\Files\Mount\IMountManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IRequest;
use OCP\ISession;
use OCP\ITagManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Share\IManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Server;

$RUNTIME_APPTYPES = ['filesystem', 'authentication', 'logging'];

OC_App::loadApps($RUNTIME_APPTYPES);

OC_Util::obEnd();
/** @var ISession $session */
$session = \OC::$server->get(ISession::class);
$session->close();

/** @var IRequest $request */
$request = \OC::$server->get(IRequest::class);
/** @var IConfig $config */
$config = \OC::$server->get(IConfig::class);
/** @var IFactory $i10nFactory */
$i10nFactory = \OC::$server->get(IFactory::class);

// Backends
$authBackend = new OCA\DAV\Connector\PublicAuth(
	$request,
	\OC::$server->get(IManager::class),
	$session,
	\OC::$server->get(Throttler::class)
);
$authPlugin = new AuthPlugin($authBackend);

$serverFactory = new OCA\DAV\Connector\Sabre\ServerFactory(
	$config,
	\OC::$server->get(LoggerInterface::class),
	\OC::$server->get(IDBConnection::class),
	\OC::$server->get(IUserSession::class),
	\OC::$server->get(IMountManager::class),
	\OC::$server->get(ITagManager::class),
	$request,
	\OC::$server->get(IPreview::class),
	\OC::$server->getEventDispatcher(),
	$i10nFactory->get('dav')
);

$requestUri = $request->getRequestUri();

$linkCheckPlugin = new PublicLinkCheckPlugin();
$filesDropPlugin = new FilesDropPlugin();

$server = $serverFactory->createServer($baseuri, $requestUri, $authPlugin, function (Server $server) use ($authBackend, $linkCheckPlugin, $filesDropPlugin) {
	$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
	/** @var FederatedShareProvider $shareProvider */
	$federatedShareProvider = \OC::$server->get(FederatedShareProvider::class);
	if ($federatedShareProvider->isOutgoingServer2serverShareEnabled() === false && !$isAjax) {
		// this is what is thrown when trying to access a non-existing share
		throw new \Sabre\DAV\Exception\NotAuthenticated();
	}

	$share = $authBackend->getShare();
	$owner = $share->getShareOwner();
	$isReadable = $share->getPermissions() & Constants::PERMISSION_READ;
	$fileId = $share->getNodeId();

	// FIXME: should not add storage wrappers outside of preSetup, need to find a better way
	$previousLog = Filesystem::logWarningWhenAddingStorageWrapper(false);
	Filesystem::addStorageWrapper('sharePermissions', function ($mountPoint, $storage) use ($share) {
		return new PermissionsMask(['storage' => $storage, 'mask' => $share->getPermissions() | Constants::PERMISSION_SHARE]);
	});
	Filesystem::addStorageWrapper('shareOwner', function ($mountPoint, $storage) use ($share) {
		return new PublicOwnerWrapper(['storage' => $storage, 'owner' => $share->getShareOwner()]);
	});
	Filesystem::logWarningWhenAddingStorageWrapper($previousLog);

	OC_Util::tearDownFS();
	OC_Util::setupFS($owner);
	$ownerView = new View('/'. $owner . '/files');
	$path = $ownerView->getPath($fileId);
	$fileInfo = $ownerView->getFileInfo($path);
	$linkCheckPlugin->setFileInfo($fileInfo);

	// If not readable (files_drop) enable the filesdrop plugin
	if (!$isReadable) {
		$filesDropPlugin->enable();
	}

	$view = new View($ownerView->getAbsolutePath($path));
	$filesDropPlugin->setView($view);

	return $view;
});

$server->addPlugin($linkCheckPlugin);
$server->addPlugin($filesDropPlugin);

// And off we go!
$server->start();
