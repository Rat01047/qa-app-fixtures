import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });

        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        // Hydrate when the SSR server pre-rendered the markup, mount fresh
        // otherwise — so the same bundle works with SSR on or off.
        if (el.hasChildNodes()) {
            hydrateRoot(el, <App {...props} />);
        } else {
            createRoot(el).render(<App {...props} />);
        }
    },
});
