import { configureStore } from '@reduxjs/toolkit';
import baseApi from '@store/slices/api/base';

export const store = configureStore({
	reducer: {
		[baseApi.reducerPath]: baseApi.reducer,
	},
	middleware: (getDefault) => getDefault().concat(baseApi.middleware),
});

export const AppDispatch = store.dispatch;
