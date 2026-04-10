import path from 'node:path';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

const devProxyTarget =
	process.env.VITE_DEV_PROXY_TARGET || 'http://127.0.0.1:8000';
const devPublicHost = process.env.VITE_DEV_PUBLIC_HOST || '';
const useHttpsProxy = 'true' === process.env.VITE_DEV_USE_HTTPS_PROXY;

// https://vite.dev/config/
export default defineConfig({
	base: './',
	plugins: [react({ include: /\.[jt]sx?$/ }), tailwindcss()],
	build: {
		outDir: 'build',
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
			'^/(?!dashboard(?:/|$)|login(?:/|$)|forgot-password(?:/|$)|reset-password(?:/|$)|api(?:/|$)|@vite(?:/|$)|@react-refresh(?:/|$)|ui(?:/|$)|src(?:/|$)|node_modules(?:/|$)|assets(?:/|$))[a-z0-9-]+(?:\\+)?/?$':
				{
					target: devProxyTarget,
					changeOrigin: true,
				},
		},
	},
});
