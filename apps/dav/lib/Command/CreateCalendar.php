<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Georg Ehrke <oc.list@georgehrke.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Thomas Citharel <nextcloud@tcit.fr>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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
namespace OCA\DAV\Command;

use OC\KnownUser\KnownUserService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Proxy\ProxyMapper;
use OCA\DAV\Connector\Sabre\Principal;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use OCP\Share\IManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateCalendar extends Command {

	/** @var IUserManager */
	protected $userManager;

	/** @var IGroupManager $groupManager */
	private $groupManager;

	/** @var IDBConnection */
	protected $dbConnection;

	/** @var IManager */
	private $shareManager;

	/** @var IUserSession */
	private $userSession;

	/** @var IAppManager */
	private $appManager;

	/** @var IConfig */
	private $config;

	/** @var IL10N */
	private $il10nFactory;

	/**
	 * @param IUserManager $userManager
	 * @param IGroupManager $groupManager
	 * @param IDBConnection $dbConnection
	 * @param IManager $shareManager
	 */
	public function __construct(IUserManager $userManager, IGroupManager $groupManager, IDBConnection $dbConnection, IManager $shareManager, IUserSession $userSession, IAppManager $appManager, IConfig $config, IFactory $il10nFactory) {
		parent::__construct();
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->dbConnection = $dbConnection;
		$this->shareManager = $shareManager;
		$this->userSession = $userSession;
		$this->appManager = $appManager;
		$this->config = $config;
		$this->il10nFactory = $il10nFactory;
	}

	protected function configure() {
		$this
			->setName('dav:create-calendar')
			->setDescription('Create a dav calendar')
			->addArgument('user',
				InputArgument::REQUIRED,
				'User for whom the calendar will be created')
			->addArgument('name',
				InputArgument::REQUIRED,
				'Name of the calendar');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getArgument('user');
		if (!$this->userManager->userExists($user)) {
			throw new \InvalidArgumentException("User <$user> in unknown.");
		}
		$principalBackend = new Principal(
			$this->userManager,
			$this->groupManager,
			$this->shareManager,
			$this->userSession,
			$this->appManager,
			\OC::$server->get(ProxyMapper::class),
			\OC::$server->get(KnownUserService::class),
			$this->config,
			$this->il10nFactory,
		);
		$random = \OC::$server->get(ISecureRandom::class);
		$logger = \OC::$server->get(LoggerInterface::class);
		$dispatcher = \OC::$server->get(IEventDispatcher::class);
		$legacyDispatcher = \OC::$server->getEventDispatcher();
		$config = \OC::$server->get(IConfig::class);

		$name = $input->getArgument('name');
		$caldav = new CalDavBackend(
			$this->dbConnection,
			$principalBackend,
			$this->userManager,
			$this->groupManager,
			$random,
			$logger,
			$dispatcher,
			$legacyDispatcher,
			$config
		);
		$caldav->createCalendar("principals/users/$user", $name, []);
		return 0;
	}
}
