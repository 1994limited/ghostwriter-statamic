import Index from './pages/Index.vue';
import Voice from './pages/Voice.vue';
import Type from './pages/Type.vue';
import Plan from './pages/Plan.vue';
import Setup from './pages/Setup.vue';
import Widget from './components/Widget.vue';
import Launcher from './components/Launcher.vue';
import ImageDialog from './components/ImageDialog.vue';
import ghost from './icon.js';
import { request } from './stock/request.js';

Statamic.booting(() => {
    Statamic.$inertia.register('ghostwriter::Index', Index);
    Statamic.$inertia.register('ghostwriter::Voice', Voice);
    Statamic.$inertia.register('ghostwriter::Type', Type);
    Statamic.$inertia.register('ghostwriter::Plan', Plan);
    Statamic.$inertia.register('ghostwriter::Setup', Setup);
    Statamic.$components.register('ghostwriter-widget', Widget);
    Statamic.$components.register('ghostwriter-launcher', Launcher);
    Statamic.$components.register('ghostwriter-image-dialog', ImageDialog);

    // Every publish form announces itself as it mounts. When it is the create
    // or edit form of an entry in a collection Ghostwriter writes for, the
    // launcher is pushed into it, holding the functions that set its values.
    Statamic.$events.$on('publish-container-created', (container) => {
        const config = Statamic.$config.get('ghostwriter');

        if (!config?.enabled || container.name !== 'base') return;

        const match = window.location.pathname.match(/\/collections\/([^/]+)\/entries\/([^/]+)/);

        if (!match || !config.collections.includes(match[1])) return;

        const entry = match[2] === 'create' ? null : match[2];
        const blueprint = new URLSearchParams(window.location.search).get('blueprint');

        container.pushComponent('ghostwriter-launcher', {
            props: { collection: match[1], entry, form: container, baseUrl: config.url },
        });

        // The dialog behind the image button on every assets field of this form.
        container.pushComponent('ghostwriter-image-dialog', {
            props: { collection: match[1], blueprint, entry, form: container, baseUrl: config.url },
        });
    });

    // A Ghostwriter button beside each assets field's own controls, on the
    // forms above. It hands the field's context to the dialog. Statamic draws
    // quick actions as a 20px button with a 10px icon, easy to miss, so the
    // icon carries a marker that cp.css uses to draw it larger, with its name.
    Statamic.$fieldActions.add('assets-fieldtype', {
        title: __('Find a photo'),
        icon: ghost.replace('<svg ', '<svg data-ghostwriter-field-action="" '),
        quick: true,
        visible: ({ config }) => {
            const settings = Statamic.$config.get('ghostwriter');
            const match = window.location.pathname.match(/\/collections\/([^/]+)\/entries\/([^/]+)/);

            return !!settings?.enabled && !!config?.container && !!match && settings.collections.includes(match[1]);
        },
        run: ({ fieldPathPrefix, handle, value, config, meta, update, updateMeta }) => {
            Statamic.$events.$emit('ghostwriter.image', {
                path: fieldPathPrefix ? `${fieldPathPrefix}.${handle}` : handle,
                label: config.display || handle,
                value,
                meta,
                update,
                updateMeta,
            });
        },
    });

    // "Check connection" on the settings screen's Stock photos rows. The
    // rows are plain HTML in the blueprint, so the button is handled here.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest?.('[data-ghostwriter-check-connection]');

        if (!button) return;

        event.preventDefault();

        const id = button.dataset.ghostwriterCheckConnection;
        const out = document.querySelector(`[data-ghostwriter-connection="${id}"]`);
        const base = Statamic.$config.get('ghostwriter')?.url;

        if (!out || !base) return;

        button.disabled = true;
        out.textContent = __('Checking…');

        try {
            const result = await request(`${base}/stock/libraries/${encodeURIComponent(id)}/check`, { method: 'POST' });

            out.textContent = result.ok
                ? [__('Connected as :account.', { account: result.account || __('your account') }), ...result.products].join(' · ')
                : result.message;
            out.style.color = result.ok ? '#16a34a' : '#dc2626';
        } catch (error) {
            out.textContent = error.message;
            out.style.color = '#dc2626';
        } finally {
            button.disabled = false;
        }
    });
});
