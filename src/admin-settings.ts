import { createApp } from 'vue'
// The shared look on the settings page too: the accent-coloured spinners, and
// the room beside the settings navigation's toggle (the mount element in the
// template carries both classes).
import '@doconext/nc-ui/styles'
import AdminSettings from './views/AdminSettings.vue'

createApp(AdminSettings).mount('#doconext_finder-admin-settings')
