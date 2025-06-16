/* eslint-disable no-console */
// The store script handles app wide variables (or state), for the use of these variables and there governing concepts read the design.md
import pinia from '../pinia.js'
import { useNavigationStore } from './modules/navigation.js'
import { useSearchStore } from './modules/search.ts'
import { useRegisterStore } from './modules/register.js'
import { useSourceStore } from './modules/source.js'
import { useSchemaStore } from './modules/schema.js'
import { useObjectStore } from './modules/object.js'
import { useConfigurationStore } from './modules/configuration.js'
import { useDashboardStore } from './modules/dashboard.js'
import { useAuditTrailStore } from './modules/auditTrail.js'
import { useDeletedStore } from './modules/deleted.js'

const navigationStore = useNavigationStore(pinia)
const searchStore = useSearchStore(pinia)
const registerStore = useRegisterStore(pinia)
const sourceStore = useSourceStore(pinia)
const schemaStore = useSchemaStore(pinia)
const objectStore = useObjectStore(pinia)
const configurationStore = useConfigurationStore(pinia)
const dashboardStore = useDashboardStore(pinia)
const auditTrailStore = useAuditTrailStore(pinia)
const deletedStore = useDeletedStore(pinia)

export {
	// generic
	navigationStore,
	searchStore,
	registerStore,
	sourceStore,
	schemaStore,
	objectStore,
	configurationStore,
	dashboardStore,
	auditTrailStore,
	deletedStore,
}
