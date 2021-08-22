<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
namespace OCA\DAV\Tests\unit\Comments;

use OC\EventDispatcher\EventDispatcher;
use OC\EventDispatcher\SymfonyAdapter;
use OCA\DAV\Comments\EntityTypeCollection as EntityTypeCollectionImplementation;
use OCA\DAV\Comments\RootCollection;
use OCP\Comments\CommentsEntityEvent;
use OCP\Comments\ICommentsManager;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAV\Exception\NotFound;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Test\TestCase;

class RootCollectionTest extends TestCase {

	/** @var ICommentsManager|MockObject */
	protected $commentsManager;
	/** @var IUserManager|MockObject */
	protected $userManager;
	/** @var LoggerInterface|MockObject */
	protected $logger;
	/** @var RootCollection */
	protected $collection;
	/** @var IUserSession|MockObject */
	protected $userSession;
	/** @var EventDispatcherInterface */
	protected $dispatcher;
	/** @var IUser|MockObject */
	protected $user;

	protected function setUp(): void {
		parent::setUp();

		$this->user = $this->getMockBuilder(IUser::class)
			->disableOriginalConstructor()
			->getMock();

		$this->commentsManager = $this->getMockBuilder(ICommentsManager::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userManager = $this->getMockBuilder(IUserManager::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userSession = $this->getMockBuilder(IUserSession::class)
			->disableOriginalConstructor()
			->getMock();
		$this->logger = $this->getMockBuilder(LoggerInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->dispatcher = new SymfonyAdapter(
			new EventDispatcher(
				new \Symfony\Component\EventDispatcher\EventDispatcher(),
				\OC::$server,
				$this->createMock(LoggerInterface::class)
			),
			$this->getMockBuilder(ILogger::class)
				->disableOriginalConstructor()
				->getMock()
		);

		$this->collection = new RootCollection(
			$this->commentsManager,
			$this->userManager,
			$this->userSession,
			$this->dispatcher,
			$this->logger
		);
	}

	protected function prepareForInitCollections(): void {
		$this->user->expects($this->any())
			->method('getUID')
			->willReturn('alice');

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($this->user);

		$this->dispatcher->addListener(CommentsEntityEvent::EVENT_ENTITY, function (CommentsEntityEvent $event) {
			$event->addEntityCollection('files', function () {
				return true;
			});
		});
	}


	public function testCreateFile(): void {
		$this->expectException(Forbidden::class);

		$this->collection->createFile('foo');
	}


	public function testCreateDirectory(): void {
		$this->expectException(Forbidden::class);

		$this->collection->createDirectory('foo');
	}

	public function testGetChild(): void {
		$this->prepareForInitCollections();
		$etc = $this->collection->getChild('files');
		$this->assertInstanceOf(EntityTypeCollectionImplementation::class, $etc);
	}


	public function testGetChildInvalid(): void {
		$this->expectException(NotFound::class);

		$this->prepareForInitCollections();
		$this->collection->getChild('robots');
	}


	public function testGetChildNoAuth(): void {
		$this->expectException(NotAuthenticated::class);

		$this->collection->getChild('files');
	}

	public function testGetChildren(): void {
		$this->prepareForInitCollections();
		$children = $this->collection->getChildren();
		$this->assertNotEmpty($children);
		foreach ($children as $child) {
			$this->assertInstanceOf(EntityTypeCollectionImplementation::class, $child);
		}
	}


	public function testGetChildrenNoAuth(): void {
		$this->expectException(NotAuthenticated::class);

		$this->collection->getChildren();
	}

	public function testChildExistsYes(): void {
		$this->prepareForInitCollections();
		$this->assertTrue($this->collection->childExists('files'));
	}

	public function testChildExistsNo(): void {
		$this->prepareForInitCollections();
		$this->assertFalse($this->collection->childExists('robots'));
	}


	public function testChildExistsNoAuth(): void {
		$this->expectException(NotAuthenticated::class);

		$this->collection->childExists('files');
	}


	public function testDelete(): void {
		$this->expectException(Forbidden::class);

		$this->collection->delete();
	}

	public function testGetName(): void {
		$this->assertSame('comments', $this->collection->getName());
	}


	public function testSetName(): void {
		$this->expectException(Forbidden::class);

		$this->collection->setName('foobar');
	}

	public function testGetLastModified(): void {
		$this->assertNull($this->collection->getLastModified());
	}
}
