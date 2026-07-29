import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import ReactDOMServer from 'react-dom/server';

/*
 * Built by `vite build --ssr` into bootstrap/ssr/ssr.js — the conventional
 * Laravel SSR bundle path, which is what `ssr_ready` detection looks for and
 * what `artisan inertia:start-ssr` runs.
 */
createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        resolve: (name) => {
            const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });

            return pages[`./Pages/${name}.jsx`];
        },
        setup: ({ App, props }) => <App {...props} />,
    }),
);
