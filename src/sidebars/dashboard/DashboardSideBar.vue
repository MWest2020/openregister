<script setup>
import { objectStore, registerStore, schemaStore, dashboardStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		name="Dashboard"
		subname="Get real-time insights into your organization's data health by focusing on on registers, schema definitions, and object storage and usage."
		:open="isSidebarOpen"
		class="dashboard-sidebar"
		@update:open="(e) => isSidebarOpen = e">
		<NcAppSidebarTab id="overview-tab" name="Overview" :order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Filter Statistics') }}</h3>
				<div class="filterGroup">
					<label for="registerSelect">{{ t('openregister', 'Register') }}</label>
					<NcSelect v-bind="registerOptions"
						id="registerSelect"
						:model-value="selectedRegisterValue"
						:loading="registerLoading"
						:disabled="registerLoading"
						:input-label="t('openregister', 'Register')"
						placeholder="Select a register"
						@update:model-value="handleRegisterChange" />
				</div>
				<div class="filterGroup">
					<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
					<NcSelect v-bind="schemaOptions"
						id="schemaSelect"
						:model-value="selectedSchemaValue"
						:loading="schemaLoading"
						:disabled="!registerStore.registerItem || schemaLoading"
						:input-label="t('openregister', 'Schema')"
						placeholder="Select a schema"
						@update:model-value="handleSchemaChange" />
				</div>
			</div>

			<!-- System Totals Section -->
			<div class="section">
				<h3 class="sectionTitle">
					{{ t('openregister', 'Totals') }}
				</h3>
				<div v-if="dashboardStore.loading" class="loadingContainer">
					<NcLoadingIcon :size="20" />
					<span>{{ t('openregister', 'Loading statistics...') }}</span>
				</div>
				<div v-else-if="systemTotals" class="statsContainer">
					<table class="statisticsTable">
						<tbody>
							<tr>
								<td>{{ t('openregister', 'Registers') }}</td>
								<td>{{ filteredRegisters.length }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Schemas') }}</td>
								<td>{{ totalSchemas }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Objects') }}</td>
								<td>{{ systemTotals.stats?.objects?.total || 0 }}</td>
								<td>{{ formatBytes(systemTotals.stats?.objects?.size || 0) }}</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Invalid') }}
								</td>
								<td>{{ systemTotals.stats?.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Deleted') }}
								</td>
								<td>{{ systemTotals.stats?.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Locked') }}
								</td>
								<td>{{ systemTotals.stats?.objects?.locked || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Published') }}
								</td>
								<td>{{ systemTotals.stats?.objects?.published || 0 }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Logs') }}</td>
								<td>{{ systemTotals.stats?.logs?.total || 0 }}</td>
								<td>{{ formatBytes(systemTotals.stats?.logs?.size || 0) }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Files') }}</td>
								<td>{{ systemTotals.stats?.files?.total || 0 }}</td>
								<td>{{ formatBytes(systemTotals.stats?.files?.size || 0) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Orphaned Items Section -->
			<div class="section">
				<h3 class="sectionTitle">
					{{ t('openregister', 'Orphaned Items') }}
				</h3>
				<div v-if="dashboardStore.loading" class="loadingContainer">
					<NcLoadingIcon :size="20" />
					<span>{{ t('openregister', 'Loading statistics...') }}</span>
				</div>
				<div v-else-if="orphanedItems" class="statsContainer">
					<table class="statisticsTable">
						<tbody>
							<tr>
								<td>{{ t('openregister', 'Objects') }}</td>
								<td>{{ orphanedItems.stats?.objects?.total || 0 }}</td>
								<td>{{ formatBytes(orphanedItems.stats?.objects?.size || 0) }}</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Invalid') }}
								</td>
								<td>{{ orphanedItems.stats?.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Deleted') }}
								</td>
								<td>{{ orphanedItems.stats?.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Locked') }}
								</td>
								<td>{{ orphanedItems.stats?.objects?.locked || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Published') }}
								</td>
								<td>{{ orphanedItems.stats?.objects?.published || 0 }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Logs') }}</td>
								<td>{{ orphanedItems.stats?.logs?.total || 0 }}</td>
								<td>{{ formatBytes(orphanedItems.stats?.logs?.size || 0) }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Files') }}</td>
								<td>{{ orphanedItems.stats?.files?.total || 0 }}</td>
								<td>{{ formatBytes(orphanedItems.stats?.files?.size || 0) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import formatBytes from '../../services/formatBytes.js'
import { NcAppSidebar, NcAppSidebarTab, NcSelect, NcLoadingIcon } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

export default {
	name: 'DashboardSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcLoadingIcon,
		Magnify,
	},
	data() {
		return {
			registerLoading: false,
			schemaLoading: false,
			ignoreNextPageWatch: false,
			searchQuery: '',
			activeTab: 'overview-tab',
			searchTimeout: null,
			isSidebarOpen: true,
		}
	},
	computed: {
		registerOptions() {
			return {
				options: registerStore.registerList.map(register => ({
					value: register.id,
					label: register.title,
					title: register.title,
					register,
				})),
				reduce: option => option.register,
				label: 'title',
				getOptionLabel: option => {
					return option.title || (option.register && option.register.title) || option.label || ''
				},
			}
		},
		schemaOptions() {
			if (!registerStore.registerItem) return { options: [] }

			return {
				options: schemaStore.schemaList
					.filter(schema => registerStore.registerItem.schemas.includes(schema.id))
					.map(schema => ({
						value: schema.id,
						label: schema.title,
						title: schema.title,
						schema,
					})),
				reduce: option => option.schema,
				label: 'title',
				getOptionLabel: option => {
					return option.title || (option.schema && option.schema.title) || option.label || ''
				},
			}
		},
		selectedRegisterValue() {
			if (!registerStore.registerItem) return null
			const register = registerStore.registerItem
			return {
				value: register.id,
				label: register.title,
				title: register.title,
				register,
			}
		},
		selectedSchemaValue() {
			if (!schemaStore.schemaItem) return null
			const schema = schemaStore.schemaItem
			return {
				value: schema.id,
				label: schema.title,
				title: schema.title,
				schema,
			}
		},
		metadataColumns() {
			return Object.entries(objectStore.metadata).map(([id, meta]) => ({
				id,
				...meta,
			}))
		},
		/**
		 * Get system totals from dashboardStore
		 * @return {object | null}
		 */
		systemTotals() {
			return dashboardStore.registers.find(register => register.title === 'System Totals')
		},
		/**
		 * Get orphaned items from dashboardStore
		 * @return {object | null}
		 */
		orphanedItems() {
			return dashboardStore.registers.find(register => register.title === 'Orphaned Items')
		},
		/**
		 * Get filtered registers (excluding system and orphaned)
		 * @return {Array}
		 */
		filteredRegisters() {
			return dashboardStore.registers.filter(register =>
				register.title !== 'System Totals'
				&& register.title !== 'Orphaned Items',
			)
		},
		/**
		 * Get total number of schemas in filtered registers
		 * @return {number}
		 */
		totalSchemas() {
			return this.filteredRegisters.reduce((total, register) => {
				return total + (register.schemas?.length || 0)
			}, 0)
		},
	},
	watch: {
		'searchQuery'(value) {
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}
			this.searchTimeout = setTimeout(() => {
				objectStore.setFilters({
					_search: value || '',
				})
				if (registerStore.registerItem && schemaStore.schemaItem) {
					objectStore.refreshObjectList({
						register: registerStore.registerItem.id,
						schema: schemaStore.schemaItem.id,
					})
				}
			}, 1000)
		},
		// Watch for schema changes to initialize properties
		// Use immediate: true equivalent in mounted
		// This watcher will update properties when schema changes
		'$root.schemaStore.schemaItem': {
			handler(newSchema) {
				if (newSchema) {
					objectStore.initializeProperties(newSchema)
				} else {
					objectStore.properties = {}
					objectStore.initializeColumnFilters()
				}
			},
			deep: true,
		},
	},
	mounted() {
		objectStore.initializeColumnFilters()
		this.registerLoading = true
		this.schemaLoading = true

		// Only load lists if they're empty
		if (!registerStore.registerList.length) {
			registerStore.refreshRegisterList()
				.finally(() => (this.registerLoading = false))
		} else {
			this.registerLoading = false
		}

		if (!schemaStore.schemaList.length) {
			schemaStore.refreshSchemaList()
				.finally(() => (this.schemaLoading = false))
		} else {
			this.schemaLoading = false
		}

		// Load objects if register and schema are already selected
		if (registerStore.registerItem && schemaStore.schemaItem) {
			objectStore.refreshObjectList()
		}
	},
	methods: {
		handleRegisterChange(option) {
			registerStore.setRegisterItem(option)
			schemaStore.setSchemaItem(null)
		},
		async handleSchemaChange(option) {
			schemaStore.setSchemaItem(option)
			if (option) {
				objectStore.initializeProperties(option)
				objectStore.refreshObjectList()
			}
		},
		handleSearch() {
			if (registerStore.registerItem && schemaStore.schemaItem) {
				objectStore.refreshObjectList({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id,
					search: this.searchQuery,
				})
			}
		},
	},
}
</script>

<style>
.dashboard-sidebar .app-sidebar-header__subname {
	white-space: normal !important;
}
</style>

<style lang="scss" scoped>
.section {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.section:last-child {
	border-bottom: none;
}

.sectionTitle {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.loadingContainer {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0 16px;
	color: var(--color-text-maxcontrast);
}

.statsContainer {
	padding: 0 16px;
}

.statisticsTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;

	td {
		padding: 4px 8px;
		border-bottom: 1px solid var(--color-border);

		&:nth-child(2),
		&:nth-child(3) {
			text-align: right;
		}
	}

	.subRow td {
		color: var(--color-text-maxcontrast);
	}

	.indented {
		padding-left: 24px;
	}

	tr:last-child td {
		border-bottom: none;
	}
}

.filterSection {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-bottom: 20px;
	border-bottom: 1px solid var(--color-border);

	h3 {
		margin: 0;
		font-size: 1.1em;
		color: var(--color-main-text);
	}
}

.filterGroup {
	display: flex;
	flex-direction: column;
	gap: 8px;

	label {
		font-size: 0.9em;
		color: var(--color-text-maxcontrast);
	}
}

.dateRangeInputs {
	display: flex;
	gap: 8px;
}
</style>
