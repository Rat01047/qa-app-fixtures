import { Head } from '@inertiajs/react';

/*
 * The SSR marker below is the whole point of this page: when the SSR
 * service is running, `curl /inertia` returns this text inside the #app div.
 * With SSR off, #app is empty in the raw HTML and only fills in after
 * hydration — so a plain curl distinguishes the two without a browser.
 */
export default function Welcome({ marker, laravel, php }) {
    return (
        <>
            <Head title="Laravel QA — Inertia" />
            <main style={{ fontFamily: 'system-ui, sans-serif', maxWidth: 640, margin: '4rem auto', padding: '0 1rem' }}>
                <h1 style={{ color: '#ef4444' }}>Laravel QA — Inertia</h1>
                <p style={{ color: '#16a34a', fontWeight: 600 }}>
                    ✓ INERTIA_PAGE_RENDERED — Laravel
                </p>
                <p>
                    Laravel: <code>{laravel}</code> · PHP: <code>{php}</code> · Marker: <code>{marker}</code>
                </p>
                <p>
                    If this text appears in <em>view-source</em>, the Inertia SSR server rendered it.
                    If <code>#app</code> is empty in view-source but the page still shows, React hydrated it in the browser.
                </p>
            </main>
        </>
    );
}
