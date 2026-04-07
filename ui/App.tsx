import { BrowserRouter } from 'react-router-dom';

import { PEAKURL_BASENAME } from './constants';
import AppRouter from './router';
import { ClientProviders } from './components/providers';

function App() {
	return (
		<ClientProviders>
			<BrowserRouter basename={PEAKURL_BASENAME || undefined}>
				<AppRouter />
			</BrowserRouter>
		</ClientProviders>
	);
}

export default App;
