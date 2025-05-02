import { defineConfig } from "vite";
import laravel, { refreshPaths } from "laravel-vite-plugin";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/js/app.js"],
            refresh: [
                ...refreshPaths,
                "app/Filament/**",
                "app/Forms/Components/**",
                "app/Livewire/**",
                "app/Infolists/Components/**",
                "app/Providers/Filament/**",
                "app/Tables/Columns/**",
            ],
        }),
    ],
    build: {
        chunkSizeWarningLimit: 1600,
        // rollupOptions: {
        //     output: {
        //         manualChunks: {
        //             qrcode: ['easyqrcodejs'],
        //             videojs: ['video.js']
        //         }
        //     }
        // }
    }
});
