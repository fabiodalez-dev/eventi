import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// I font non passano da un CDN esterno (Bunny, Google): sono pacchetti
// @fontsource importati da resources/css/app.css e serviti dal nostro dominio.
// Un font remoto è un trasferimento di dati verso terzi a ogni visita.
export default defineConfig({
    plugins: [
        laravel({
            // La mappa e il calendario hanno un pacchetto ciascuno: MapLibre
            // e Alpine non devono pesare sulle pagine che non li usano.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/map.js',
                'resources/js/calendar.js',
                // Il tema del pannello: sta a parte perche' e' un altro
                // prodotto CSS — quello del sito non entra in /admin e
                // viceversa.
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
