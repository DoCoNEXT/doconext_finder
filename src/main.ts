import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'

// Mounts into templates/index.php's #doconext_finder div.
createApp(App).use(createPinia()).mount('#doconext_finder')
