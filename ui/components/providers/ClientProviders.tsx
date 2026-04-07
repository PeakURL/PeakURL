import { Provider } from 'react-redux';
import { store } from '@store';
import { NotificationProvider } from './NotificationProvider';
import { ThemeProvider } from './ThemeProvider';
import type { ClientProvidersProps } from './types';

/**
 * ClientProviders wraps the app with Redux, theme, and notification providers.
 *
 * @param props Component props
 * @param props.children Application content
 */
function ClientProviders({ children }: ClientProvidersProps) {
	return (
		<Provider store={store}>
			<ThemeProvider>
				<NotificationProvider>{children}</NotificationProvider>
			</ThemeProvider>
		</Provider>
	);
}

export default ClientProviders;
