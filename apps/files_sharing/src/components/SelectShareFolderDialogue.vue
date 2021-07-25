<!--
  - @copyright 2021 Hinrich Mahler <nextcloud@mahlerhome.de>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program.  If not, see <http://www.gnu.org/licenses/>.
  -->

<template>
	<div>
		<h3>{{ t('files', 'Set default folder for accepted shares') }} </h3>
		<form @submit.prevent="submit">
			<p class="transfer-select-row">
				<span>{{ readableDirectory }}</span>
				<button @click.prevent="submit">
					{{ t('files', 'Change') }}
				</button>
				<button @click.prevent="reset">
					{{ t('files', 'Reset') }}
				</button>
				<span class="error">{{ directoryPickerError }}</span>
			</p>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getFilePickerBuilder } from '@nextcloud/dialogs'

import { loadState } from '@nextcloud/initial-state'

const picker = getFilePickerBuilder(t('files', 'Choose a default folder for accepted shares'))
	.setMultiSelect(false)
	.setModal(true)
	.setType(1)
	.setMimeTypeFilter(['httpd/unix-directory'])
	.allowDirectories()
	.build()

export default {
	name: 'SelectShareFolderDialogue',
	data() {
		return {
			directory: loadState('files_sharing', 'share_folder'),
			default_directory: loadState('files_sharing', 'default_share_folder'),
			allowCustomDirectory: loadState('files_sharing', 'allow_custom_share_folder'),
			directoryPickerError: undefined,
		}
	},
	computed: {
		readableDirectory() {
			if (!this.directory) {
				return '/'
			}
			return this.directory
		},
	},
	methods: {
		submit() {
			this.directoryPickerError = undefined

			picker.pick()
				.then(dir => dir === '' ? '/' : dir)
				.then(dir => {
					if (!dir.startsWith('/')) {
						throw new Error(t('files', 'Invalid path selected'))
					}
					this.directory = dir

					axios.put(
						generateUrl('/apps/files_sharing/settings/shareFolder'),
						{
							shareFolder: this.directory,
						}
					)
				}).catch(error => {
					this.directoryPickerError = error.message || t('files', 'Unknown error')
				})
		},
		reset() {
			this.directory = this.default_directory

			axios.delete(
				generateUrl('/apps/files_sharing/settings/shareFolder')
			)
		},
	},
}
</script>

<style scoped lang="scss">
.middle-align {
	vertical-align: middle;
}
p {
	margin-top: 12px;
	margin-bottom: 12px;
}
.new-owner-row {
	display: flex;

	label {
		display: flex;
		align-items: center;

		span {
			margin-right: 8px;
		}
	}

	.multiselect {
		flex-grow: 1;
		max-width: 280px;
	}
}
.transfer-select-row {
	span {
		margin-right: 8px;
	}
}
</style>
