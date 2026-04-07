import { configureStore } from '@reduxjs/toolkit';
import baseApi from '@store/slices/api/base';

/**
 * Shared Redux store used by the PeakURL dashboard UI.
 */
export const store = configureStore({
	reducer: {
		[baseApi.reducerPath]: baseApi.reducer,
	},
	middleware: (getDefaultMiddleware) =>
		getDefaultMiddleware().concat(baseApi.middleware),
});

/**
 * Concrete store instance type derived from the configured dashboard store.
 */
export type AppStore = typeof store;

/**
 * Root Redux state shape derived from the configured reducers.
 */
export type RootState = ReturnType<AppStore['getState']>;

/**
 * Dispatch type used by dashboard thunks and RTK Query actions.
 */
export type AppDispatch = AppStore['dispatch'];
