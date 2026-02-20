import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { installRouterGuards, router } from './router';
import { usePersonalizationStore } from './stores/personalization';
import './assets/main.css';

const app = createApp(App);
const pinia = createPinia();

installRouterGuards(pinia);

app.use(pinia);
app.use(router);

const personalization = usePersonalizationStore(pinia);
personalization.loadFromStorage();

app.mount('#app');
