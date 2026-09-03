import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PersonalSettings from './views/PersonalSettings.vue'

createApp(PersonalSettings).use(createPinia()).mount('#doconext_finder-personal-settings')
