// @ts-nocheck
import { Provider } from 'react-redux';
import { store } from '@store';
import { ThemeProvider } from './ThemeProvider';

/**
 * ClientProviders Component
 * Wraps the application with Redux Provider and ThemeProvider.
 * @param {Object} props
 * @param {React.ReactNode} props.children - Application content
 */
function ClientProviders({ children }) {
	return (
		<Provider store={store}>
			<ThemeProvider>{children}</ThemeProvider>
		</Provider>
	);
}

export default ClientProviders;
