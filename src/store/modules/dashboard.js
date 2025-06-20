import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useRegisterStore } from './register.js'
import { useSchemaStore } from './schema.js'
import { watch } from 'vue'

export const useDashboardStore = defineStore('dashboard', {
	state: () => ({
		registers: [],
		loading: false,
		error: null,
		isInitialized: false,
		dateRange: {
			from: null,
			till: null,
		},
		chartData: {
			auditTrailActions: null,
			objectsByRegister: null,
			objectsBySchema: null,
			objectsBySize: null,
		},
		chartLoading: {
			auditTrailActions: false,
			objectsByRegister: false,
			objectsBySchema: false,
			objectsBySize: false,
		},
		statisticsData: {
			auditTrailStats: null,
			actionDistribution: null,
			mostActiveObjects: null,
		},
		statisticsLoading: {
			auditTrailStats: false,
			actionDistribution: false,
			mostActiveObjects: false,
		},
	}),

	getters: {
		getRegisters: (state) => state.registers,
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getSystemTotals: (state) => state.registers.find(register => register.title === 'System Totals'),
		getOrphanedItems: (state) => state.registers.find(register => register.title === 'Orphaned Items'),
		getDateRange: (state) => state.dateRange,
		getChartData: (state) => state.chartData,
		isChartLoading: (state) => state.chartLoading,
		getStatisticsData: (state) => state.statisticsData,
		isStatisticsLoading: (state) => state.statisticsLoading,
	},

	actions: {
		/**
		 * Set the date range for audit trail chart and refresh it
		 * @param {string|null} from - Start date
		 * @param {string|null} till - End date
		 * @return {void}
		 */
		setDateRange(from = null, till = null) {
			this.dateRange = { from, till }
			// Refresh audit trail chart when date range changes
			this.fetchAuditTrailActionChart()
		},

		/**
		 * Initialize the dashboard store and set up watchers for register and schema changes
		 * @return {void}
		 */
		init() {
			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			// Watch for changes in the active register or schema and refresh dashboard data
			watch([
				() => registerStore.registerItem?.id,
				() => schemaStore.schemaItem?.id,
			], async () => {
				// Fetch registers to update sidebar tables
				await this.fetchRegisters()
				// Fetch all chart data to update dashboard charts
				await this.fetchAllChartData()
				// Fetch all statistics to update audit trail sidebar
				await this.fetchAllStatistics()
			})
		},

		/**
		 * Fetch audit trail action chart data
		 * @return {Promise<void>}
		 */
		async fetchAuditTrailActionChart() {
			if (this.chartLoading.auditTrailActions) return

			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			try {
				this.chartLoading.auditTrailActions = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/charts/audit-trail-actions'), {
					params: {
						from: this.dateRange.from,
						till: this.dateRange.till,
						registerId: registerStore.registerItem?.id,
						schemaId: schemaStore.schemaItem?.id,
					},
				})
				this.chartData.auditTrailActions = response.data
			} catch (error) {
				console.error('Error fetching audit trail action chart:', error)
				throw error
			} finally {
				this.chartLoading.auditTrailActions = false
			}
		},

		/**
		 * Fetch objects by register chart data
		 * @return {Promise<void>}
		 */
		async fetchObjectsByRegisterChart() {
			if (this.chartLoading.objectsByRegister) return

			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			try {
				this.chartLoading.objectsByRegister = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/charts/objects-by-register'), {
					params: {
						registerId: registerStore.registerItem?.id,
						schemaId: schemaStore.schemaItem?.id,
					},
				})
				this.chartData.objectsByRegister = response.data
			} catch (error) {
				console.error('Error fetching objects by register chart:', error)
				throw error
			} finally {
				this.chartLoading.objectsByRegister = false
			}
		},

		/**
		 * Fetch objects by schema chart data
		 * @return {Promise<void>}
		 */
		async fetchObjectsBySchemaChart() {
			if (this.chartLoading.objectsBySchema) return

			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			try {
				this.chartLoading.objectsBySchema = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/charts/objects-by-schema'), {
					params: {
						registerId: registerStore.registerItem?.id,
						schemaId: schemaStore.schemaItem?.id,
					},
				})
				this.chartData.objectsBySchema = response.data
			} catch (error) {
				console.error('Error fetching objects by schema chart:', error)
				throw error
			} finally {
				this.chartLoading.objectsBySchema = false
			}
		},

		/**
		 * Fetch objects by size chart data
		 * @return {Promise<void>}
		 */
		async fetchObjectsBySizeChart() {
			if (this.chartLoading.objectsBySize) return

			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			try {
				this.chartLoading.objectsBySize = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/charts/objects-by-size'), {
					params: {
						registerId: registerStore.registerItem?.id,
						schemaId: schemaStore.schemaItem?.id,
					},
				})
				this.chartData.objectsBySize = response.data
			} catch (error) {
				console.error('Error fetching objects by size chart:', error)
				throw error
			} finally {
				this.chartLoading.objectsBySize = false
			}
		},

		/**
		 * Fetch all chart data in parallel
		 * @return {Promise<void>}
		 */
		async fetchAllChartData() {
			await Promise.all([
				this.fetchAuditTrailActionChart(),
				this.fetchObjectsByRegisterChart(),
				this.fetchObjectsBySchemaChart(),
				this.fetchObjectsBySizeChart(),
			])
		},

		/**
		 * Fetch audit trail statistics
		 * @param {number|null} hours - Number of hours to look back for recent activity (default: 24)
		 * @return {Promise<void>}
		 */
		async fetchAuditTrailStatistics(hours = 24) {
			if (this.statisticsLoading.auditTrailStats) return

			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			try {
				this.statisticsLoading.auditTrailStats = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/statistics/audit-trail'), {
					params: {
						registerId: registerStore.registerItem?.id,
						schemaId: schemaStore.schemaItem?.id,
						hours,
					},
				})
				this.statisticsData.auditTrailStats = response.data
			} catch (error) {
				console.error('Error fetching audit trail statistics:', error)
				throw error
			} finally {
				this.statisticsLoading.auditTrailStats = false
			}
		},

		/**
		 * Fetch action distribution data
		 * @param {number|null} hours - Number of hours to look back (default: 24)
		 * @return {Promise<void>}
		 */
		async fetchActionDistribution(hours = 24) {
			if (this.statisticsLoading.actionDistribution) return

			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			try {
				this.statisticsLoading.actionDistribution = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/statistics/audit-trail-distribution'), {
					params: {
						registerId: registerStore.registerItem?.id,
						schemaId: schemaStore.schemaItem?.id,
						hours,
					},
				})
				this.statisticsData.actionDistribution = response.data
			} catch (error) {
				console.error('Error fetching action distribution:', error)
				throw error
			} finally {
				this.statisticsLoading.actionDistribution = false
			}
		},

		/**
		 * Fetch most active objects
		 * @param {number|null} limit - Number of results to return (default: 10)
		 * @param {number|null} hours - Number of hours to look back (default: 24)
		 * @return {Promise<void>}
		 */
		async fetchMostActiveObjects(limit = 10, hours = 24) {
			if (this.statisticsLoading.mostActiveObjects) return

			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			try {
				this.statisticsLoading.mostActiveObjects = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/statistics/most-active-objects'), {
					params: {
						registerId: registerStore.registerItem?.id,
						schemaId: schemaStore.schemaItem?.id,
						limit,
						hours,
					},
				})
				this.statisticsData.mostActiveObjects = response.data
			} catch (error) {
				console.error('Error fetching most active objects:', error)
				throw error
			} finally {
				this.statisticsLoading.mostActiveObjects = false
			}
		},

		/**
		 * Fetch all statistics data in parallel
		 * @param {number|null} hours - Number of hours to look back (default: 24)
		 * @param {number|null} limit - Number of results for most active objects (default: 10)
		 * @return {Promise<void>}
		 */
		async fetchAllStatistics(hours = 24, limit = 10) {
			await Promise.all([
				this.fetchAuditTrailStatistics(hours),
				this.fetchActionDistribution(hours),
				this.fetchMostActiveObjects(limit, hours),
			])
		},

		/**
		 * Preload dashboard data
		 * @return {Promise<Array>}
		 */
		async preload() {
			if (!this.isInitialized && !this.loading) {
				await this.fetchRegisters()
				await this.fetchAllChartData()
				await this.fetchAllStatistics()
				this.isInitialized = true
			}
			return this.registers
		},

		/**
		 * Fetch registers for dashboard, filtered by the current register and schema from the stores
		 * @return {Promise<Array>}
		 */
		async fetchRegisters() {
			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()
			try {
				this.loading = true
				this.error = null
				const params = {}
				if (registerStore.registerItem?.id) params.registerId = registerStore.registerItem.id
				if (schemaStore.schemaItem?.id) params.schemaId = schemaStore.schemaItem.id
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard'), { params })
				this.registers = response.data.registers
				return this.registers
			} catch (error) {
				console.error('Error fetching registers:', error)
				this.error = error.message || 'Failed to fetch registers'
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Calculate sizes for a register and refresh
		 * @param {string} registerId - The ID of the register
		 * @return {Promise<boolean>}
		 */
		async calculateSizes(registerId) {
			try {
				await axios.post(generateUrl(`/apps/openregister/api/dashboard/calculate/${registerId}`))
				// Refresh the registers after calculation
				await this.fetchRegisters()
				return true
			} catch (error) {
				console.error('Error calculating sizes:', error)
				throw error
			}
		},

		/**
		 * Reset dashboard store state
		 * @return {void}
		 */
		reset() {
			this.registers = []
			this.loading = false
			this.error = null
			this.isInitialized = false
			this.dateRange = { from: null, till: null }
			this.chartData = {
				auditTrailActions: null,
				objectsByRegister: null,
				objectsBySchema: null,
				objectsBySize: null,
			}
			this.chartLoading = {
				auditTrailActions: false,
				objectsByRegister: false,
				objectsBySchema: false,
				objectsBySize: false,
			}
			this.statisticsData = {
				auditTrailStats: null,
				actionDistribution: null,
				mostActiveObjects: null,
			}
			this.statisticsLoading = {
				auditTrailStats: false,
				actionDistribution: false,
				mostActiveObjects: false,
			}
		},
	},
})

/**
 * Sets up watchers for register and schema changes to refresh dashboard data.
 * Call this function once in your app entry point after creating the stores.
 * @return {void}
 */
export function setupDashboardStoreWatchers() {
	const dashboardStore = useDashboardStore()
	const registerStore = useRegisterStore()
	const schemaStore = useSchemaStore()

	// Watch for changes in the active register or schema and refresh dashboard data
	watch([
		() => registerStore.registerItem?.id,
		() => schemaStore.schemaItem?.id,
	], () => {
		// Fetch registers to update sidebar tables, using current store state
		dashboardStore.fetchRegisters()
		// Fetch all chart data to update dashboard charts
		dashboardStore.fetchAllChartData()
		// Fetch all statistics to update audit trail sidebar
		dashboardStore.fetchAllStatistics()
	})
}
