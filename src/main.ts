import { createApp } from 'vue'
import { createPinia } from 'pinia'
// The toast styles are not bundled with the functions that raise them: without
// this import showSuccess/showError render as unstyled text in the corner.
import '@nextcloud/dialogs/style.css'
// Global, and here rather than in a component: it styles the action menus
// NcActions teleports out to <body>, past every component's scope.
import './styles/action-icons.scss'
import App from './App.vue'

// Mounts into templates/index.php's #doconext_finder div.
createApp(App).use(createPinia()).mount('#doconext_finder')
