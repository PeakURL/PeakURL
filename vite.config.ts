import path from 'node:path';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

const devProxyTarget =
	process.env.VITE_DEV_PROXY_TARGET || 'http://127.0.0.1:8000';
const devPublicHost = process.env.VITE_DEV_PUBLIC_HOST || '';
const useHttpsProxy = 'true' === process.env.VITE_DEV_USE_HTTPS_PROXY;
const excludedProxyPaths = [
	'dashboard',
	'login',
	'forgot-password',
	'reset-password',
	'api',
	'@vite',
	'@react-refresh',
	'ui',
	'src',
	'node_modules',
	'assets',
];

const escapeRegexSegment = (value: string): string =>
	value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const fallbackProxyPattern = `^/(?!${excludedProxyPaths
	.map((segment) => `${escapeRegexSegment(segment)}(?:/|$)`)
	.join('|')})[a-z0-9-]+(?:\\+)?/?$`;

// https://vite.dev/config/
export default defineConfig({
	base: './',
	plugins: [react({ include: /\.[jt]sx?$/ }), tailwindcss()],
	build: {
		outDir: 'build',
		cssCodeSplit: false,
		chunkSizeWarningLimit: 1500,
		rollupOptions: {
			output: {
				entryFileNames: 'assets/app-[hash].js',
				chunkFileNames: 'assets/chunk-[hash].js',
				assetFileNames: (assetInfo) => {
					const assetName = assetInfo.name || '';
					const extension = path.extname(assetName);

					if ('.css' === extension) {
						return 'assets/style-[hash][extname]';
					}

					return 'assets/asset-[hash][extname]';
				},
			},
		},
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'ui'),
			'@constants': path.resolve(__dirname, 'ui/constants'),
			'@store': path.resolve(__dirname, 'ui/store'),
		},
	},
	server: {
		allowedHosts: devPublicHost ? [devPublicHost] : undefined,
		hmr:
			useHttpsProxy && devPublicHost
				? {
						host: devPublicHost,
						protocol: 'wss',
						clientPort: 443,
					}
				: undefined,
		watch: {
			ignored: [
				'**/build/**',
				'**/release/**',
				'**/app/vendor/**',
				'**/content/**',
			],
		},
		proxy: {
			'/api': {
				target: devProxyTarget,
				changeOrigin: true,
			},
			'/favicon.png': {
				target: devProxyTarget,
				changeOrigin: true,
			},
			'/favicon.ico': {
				target: devProxyTarget,
				changeOrigin: true,
			},
			'/apple-touch-icon.png': {
				target: devProxyTarget,
				changeOrigin: true,
			},
			'/site.webmanifest': {
				target: devProxyTarget,
				changeOrigin: true,
			},
			[fallbackProxyPattern]: {
				target: devProxyTarget,
				changeOrigin: true,
			},
		},
	},
});
