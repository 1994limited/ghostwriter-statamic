import Index from './pages/Index.vue';
import Voice from './pages/Voice.vue';
import Type from './pages/Type.vue';
import Plan from './pages/Plan.vue';
import Setup from './pages/Setup.vue';
import Stock from './pages/Stock.vue';
import Revisit from './pages/Revisit.vue';
import Connections from './pages/Connections.vue';
import Widget from './components/Widget.vue';
import Launcher from './components/Launcher.vue';
import ImageDialog from './components/ImageDialog.vue';
import StockPanel from './components/StockPanel.vue';
import StockFieldtype from './components/StockFieldtype.vue';
import Finish from './components/Finish.vue';
import Suggest from './components/Suggest.vue';
import { extension as finishMarks } from './finish/bard.js';
import { extension as suggestMarks } from './suggest/bard.js';
import { previewIn, put } from './stock/store.js';
import ghost from './icon.js';
import { request } from './stock/request.js';

Statamic.booting(() => {
    Statamic.$inertia.register('ghostwriter::Index', Index);
    Statamic.$inertia.register('ghostwriter::Voice', Voice);
    Statamic.$inertia.register('ghostwriter::Type', Type);
    Statamic.$inertia.register('ghostwriter::Plan', Plan);
    Statamic.$inertia.register('ghostwriter::Setup', Setup);
    Statamic.$inertia.register('ghostwriter::Stock', Stock);
    Statamic.$inertia.register('ghostwriter::Revisit', Revisit);
    Statamic.$inertia.register('ghostwriter::Connections', Connections);
    Statamic.$components.register('ghostwriter-widget', Widget);
    Statamic.$components.register('ghostwriter-launcher', Launcher);
    Statamic.$components.register('ghostwriter-image-dialog', ImageDialog);
    Statamic.$components.register('ghostwriter-stock-panel', StockPanel);
    Statamic.$components.register('ghostwriter_stock-fieldtype', StockFieldtype);
    Statamic.$components.register('ghostwriter-finish', Finish);
    Statamic.$components.register('ghostwriter-suggest', Suggest);

    // Finish this page: Ghostwriter's markers inside every Bard field are
    // underlined, and the guide can select and replace them.
    Statamic.$bard.addExtension(finishMarks);

    // Suggest edits: each suggestion's words underlined in Bard, and changed
    // through the editor.
    Statamic.$bard.addExtension(suggestMarks);

    // A preview inserted from the image dialog shows on its field at once.
    Statamic.$events.$on('ghostwriter.stock', put);

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

        // Finish this page: the highlights and the guide; its count is on the launcher's menu.
        container.pushComponent('ghostwriter-finish', {
            props: { collection: match[1], entry, blueprint, form: container, baseUrl: config.url },
        });

        // Suggest edits, on an existing entry: the menu item's confirm, the
        // review and its guide.
        if (entry) {
            container.pushComponent('ghostwriter-suggest', {
                props: { collection: match[1], entry, blueprint, form: container, baseUrl: config.url },
            });
        }

        // The dialog behind the image button on every assets field of this form.
        container.pushComponent('ghostwriter-image-dialog', {
            props: { collection: match[1], blueprint, entry, form: container, baseUrl: config.url },
        });

        // The panel behind a field's "Preview · not licensed" badge.
        container.pushComponent('ghostwriter-stock-panel', { props: {} });
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

    // "Preview · not licensed": beside the image button on an assets field
    // that holds a stock preview not licensed yet. It shows the comp's
    // thumbnail (signed-in editors only) and opens the License step, or
    // Request licence for those who may not license. cp.css draws it as a
    // labelled badge.
    Statamic.$fieldActions.add('assets-fieldtype', {
        title: __('Preview · not licensed'),
        quick: true,
        visible: ({ value }) => !!Statamic.$config.get('ghostwriter')?.enabled && !!previewIn(value),
        icon: ({ value }) => {
            const preview = previewIn(value);
            const comp = preview?.comp_url ? `<image href="${preview.comp_url}" width="16" height="16" preserveAspectRatio="xMidYMid slice"></image>` : '<rect width="16" height="16" rx="2" fill="currentColor" opacity=".25"></rect>';

            return `<svg data-ghostwriter-stock-badge="" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">${comp}</svg>`;
        },
        run: ({ value }) => {
            const preview = previewIn(value);

            if (preview) Statamic.$events.$emit('ghostwriter.stock.open', { key: preview.asset });
        },
    });

    // "Disconnect" on a connected library's settings row.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest?.('[data-ghostwriter-disconnect]');

        if (!button) return;

        event.preventDefault();

        if (!window.confirm(__('Disconnect :library? Licensing from it stops until someone connects again.', { library: button.dataset.ghostwriterLibrary }))) return;

        button.disabled = true;

        try {
            const result = await request(button.dataset.ghostwriterDisconnect, { method: 'POST' });

            Statamic.$toast.success(result.message);
            window.location.reload();
        } catch (error) {
            Statamic.$toast.error(error.message);
            button.disabled = false;
        }
    });

    // OpenRouter's row in the AI provider section: Check connection
    // (the credit left) and Disconnect.
    document.addEventListener('click', async (event) => {
        const check = event.target.closest?.('[data-ghostwriter-provider-check]');
        const disconnect = event.target.closest?.('[data-ghostwriter-provider-disconnect]');

        if (!check && !disconnect) return;

        event.preventDefault();

        if (disconnect) {
            if (!window.confirm(__('Disconnect OpenRouter? Ghostwriter stops writing with it until someone connects again.'))) return;

            disconnect.disabled = true;

            try {
                const result = await request(disconnect.dataset.ghostwriterProviderDisconnect, { method: 'POST' });

                Statamic.$toast.success(result.message);
                window.location.reload();
            } catch (error) {
                Statamic.$toast.error(error.message);
                disconnect.disabled = false;
            }

            return;
        }

        const out = document.querySelector('[data-ghostwriter-provider-connection="openrouter"]');

        check.disabled = true;
        if (out) out.textContent = __('Checking…');

        try {
            const result = await request(check.dataset.ghostwriterProviderCheck, { method: 'POST' });

            if (out) {
                out.textContent = result.message;
                out.style.color = result.ok ? '#16a34a' : '#dc2626';
            }
        } catch (error) {
            if (out) {
                out.textContent = error.message;
                out.style.color = '#dc2626';
            }
        } finally {
            check.disabled = false;
        }
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
