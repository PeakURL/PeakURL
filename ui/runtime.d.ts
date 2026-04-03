export {};

declare global {
	interface Window {
		__PEAKURL_I18N__?: {
			locale_data?: {
				messages?: Record<
					string,
					string[] | Record<string, string>
				>;
			};
		};
		__PEAKURL_LOCALE__?: string;
		__PEAKURL_TEXT_DOMAIN__?: string;
	}
}
