import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';
import { API_CLIENT_BASE_URL } from '@/constants';

const baseApi = createApi({
	reducerPath: 'api',
	baseQuery: fetchBaseQuery({
		baseUrl: API_CLIENT_BASE_URL,
		credentials: 'include', // Include cookies for authentication
	}),

	tagTypes: [
		'AuthSession',
		'Urls',
		'Analytics',
		'Profile',
		'Users',
		'Webhooks',
		'Security',
		'Geoip',
		'Mail',
		'Updates',
	],
	endpoints: () => ({}),
});

export default baseApi;
