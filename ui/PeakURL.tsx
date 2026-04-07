import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import './index.css';
import App from './App';
import { initializeI18n } from './i18n';

async function bootstrap(): Promise<void> {
	try {
		await initializeI18n();
	} finally {
		createRoot(document.getElementById('root')!).render(
			<StrictMode>
				<App />
			</StrictMode>
		);
	}
}

void bootstrap();
