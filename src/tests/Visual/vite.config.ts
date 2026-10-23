import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
export default defineConfig({resolve:{alias:{'@':fileURLToPath(new URL('../../resources/js',import.meta.url))}},plugins:[vue(),tailwindcss()],server:{host:'127.0.0.1',port:5503,strictPort:true}});
