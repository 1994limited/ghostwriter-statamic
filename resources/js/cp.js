import Index from './pages/Index.vue';
import Voice from './pages/Voice.vue';
import Type from './pages/Type.vue';
import Plan from './pages/Plan.vue';
import Launcher from './components/Launcher.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('ghostwriter::Index', Index);
    Statamic.$inertia.register('ghostwriter::Voice', Voice);
    Statamic.$inertia.register('ghostwriter::Type', Type);
    Statamic.$inertia.register('ghostwriter::Plan', Plan);
    Statamic.$components.register('ghostwriter-launcher', Launcher);

    // Every publish form announces itself as it mounts. When it is the create
    // or edit form of an entry in a collection Ghostwriter writes for, the
    // launcher is pushed into it, holding the functions that set its values.
    Statamic.$events.$on('publish-container-created', (container) => {
        const config = Statamic.$config.get('ghostwriter');

        if (!config?.enabled || container.name !== 'base') return;

        const match = window.location.pathname.match(/\/collections\/([^/]+)\/entries\/([^/]+)/);

        if (!match || !config.collections.includes(match[1])) return;

        container.pushComponent('ghostwriter-launcher', {
            props: { collection: match[1], entry: match[2] === 'create' ? null : match[2], form: container, baseUrl: config.url },
        });
    });
});
