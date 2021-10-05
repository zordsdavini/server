<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2018 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
namespace OCA\Accessibility\AppInfo;

use OC\AppFramework\Http\Request;
use OCA\Accessibility\Service\JSDataService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use function count;
use function implode;
use function md5;

class Application extends App implements IBootstrap {

	/** @var string */
	public const APP_ID = 'accessibility';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerInitialStateProvider(JSDataService::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn([$this, 'injectCss']);
	}

	public function injectCss(IUserSession $userSession,
							   IConfig $config,
							   IRequest $request,
							   IURLGenerator $urlGenerator): void {
		// Inject the fake css on all pages if enabled and user is logged
		$loggedUser = $userSession->getUser();
		if ($loggedUser !== null) {
			$features = [
				'theme' => $config->getUserValue($loggedUser->getUID(), self::APP_ID, 'theme', 'default'),
				'highcontrast' => $config->getUserValue($loggedUser->getUID(), self::APP_ID, 'highcontrast', 'default'),
				'font' => $config->getUserValue($loggedUser->getUID(), self::APP_ID, 'font', ''),
			];
		} else {
			$features = [
				'theme' => 'default',
				'highcontrast' => 'default',
				'font' => '',
			];
		}

		if (!$request->isUserAgent([Request::USER_AGENT_SAFARI])) {
			// Only Safari understands "prefers-contrast" at the moment.
			// The problem is other browsers return false "prefers-contrast" and "not(prefers-contrast)"
			if ($features['highcontrast'] === 'default') {
				$features['highcontrast'] = '';
			}
		}

		$this->injectAccessibilityCss($urlGenerator, $features);
//		\OCP\Util::addScript('accessibility', 'debug2');
		\OCP\Util::addScript('accessibility', 'accessibilityoca');
	}

	protected function injectAccessibilityCss(IURLGenerator $urlGenerator, array $features): void {
		$enabledFeatures = [];
		$systemColorScheme = $features['theme'] === 'default';
		$systemContrast = $features['highcontrast'] === 'default';
		if ($features['theme'] === 'dark') {
			$enabledFeatures[] = $features['theme'];
		}
		if ($features['highcontrast'] === 'highcontrast') {
			$enabledFeatures[] = $features['highcontrast'];
		}
		if ($features['font'] !== '') {
			$enabledFeatures[] = $features['font'];
		}

		// None
		if (!$systemColorScheme && !$systemContrast) {
			$this->addCssHeader($urlGenerator, '', implode($enabledFeatures));
		}

		// Dark mode
		if ($systemColorScheme && !$systemContrast) {
			$this->addCssHeader($urlGenerator, '(prefers-color-scheme: dark)', 'dark' . implode($enabledFeatures));
			$this->addCssHeader($urlGenerator, '(prefers-color-scheme: light)', implode($enabledFeatures));
		}

		// High contrast
		if (!$systemColorScheme && $systemContrast) {
			$this->addCssHeader($urlGenerator, '(prefers-contrast: more)', 'highcontrast' . implode($enabledFeatures));
			$this->addCssHeader($urlGenerator, '(prefers-contrast: less)', implode($enabledFeatures));
		}

		// Dark and high contrast
		if ($systemColorScheme && $systemContrast) {
			$this->addCssHeader($urlGenerator, '(prefers-contrast: more) and (prefers-color-scheme: dark)', 'darkhighcontrast' . implode($enabledFeatures));
			$this->addCssHeader($urlGenerator, '(prefers-contrast: more) and (prefers-color-scheme: light)', 'highcontrast' . implode($enabledFeatures));
			$this->addCssHeader($urlGenerator, '(prefers-contrast: less) and (prefers-color-scheme: dark)', 'dark' . implode($enabledFeatures));
			$this->addCssHeader($urlGenerator, '(prefers-contrast: less) and (prefers-color-scheme: light)', implode($enabledFeatures));
		}
	}

	protected function addCssHeader(IURLGenerator $urlGenerator, string $mediaQuery, string $features): void {
		if ($features === '') {
			return;
		}

		$linkToCss = $urlGenerator->linkToRoute(self::APP_ID . '.accessibility.getCss', ['md5' => $features]);
		if ($mediaQuery === '') {
			\OCP\Util::addHeader('link', [
				'rel' => 'stylesheet',
				'href' => $linkToCss,
			]);
		} else {
			\OCP\Util::addHeader('link', [
				'rel' => 'stylesheet',
				'media' => $mediaQuery,
				'href' => $linkToCss,
			]);
		}
	}
}
