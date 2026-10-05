import { createApp } from 'vue'
import { createPinia } from 'pinia'
// Settings are saved from this page too, and a failed save raises a toast;
// see main.ts for why the toast styles need importing.
import '@nextcloud/dialogs/style.css'
import PersonalSettings from './views/PersonalSettings.vue'

createApp(PersonalSettings).use(createPinia()).mount('#doconext_finder-personal-settings')
