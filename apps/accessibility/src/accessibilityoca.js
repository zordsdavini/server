/**
 * @copyright Copyright (c) 2020 Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Jan C. Borchardt <hey@jancborchardt.net>
 * @author John Molakvoæ <skjnldsv@protonmail.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import { loadState } from '@nextcloud/initial-state'

OCA.Accessibility = loadState('accessibility', 'data')
if (OCA.Accessibility.theme === 'default') {
	if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
		console.error('Overwriting OCA.Accessibility.theme: ' + OCA.Accessibility.theme + ' with dark')
		OCA.Accessibility.theme = 'dark'
	} else {
		console.error('Overwriting OCA.Accessibility.theme: ' + OCA.Accessibility.theme + ' with false')
		OCA.Accessibility.theme = false
	}
}

if (OCA.Accessibility.highcontrast === 'default') {
	if (window.matchMedia('(prefers-contrast: more)').matches) {
		console.error('Overwriting OCA.Accessibility.highcontrast: ' + OCA.Accessibility.theme + ' with true')
		OCA.Accessibility.highcontrast = true
	} else {
		console.error('Overwriting OCA.Accessibility.highcontrast: ' + OCA.Accessibility.theme + ' with false')
		OCA.Accessibility.highcontrast = false
	}
}

console.error('OCA.Accessibility.theme: ' + OCA.Accessibility.theme)
console.error('OCA.Accessibility.highcontrast: ' + OCA.Accessibility.highcontrast)

if (OCA.Accessibility.theme !== false) {
	document.body.classList.add(`theme--${OCA.Accessibility.theme}`)
} else {
	document.body.classList.add('theme--light')
}

if (OCA.Accessibility.highcontrast !== false) {
	document.body.classList.add('theme--highcontrast')
}
