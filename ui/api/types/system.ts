/**
 * System status values used by health checks.
 */
export type StatusKey = "ok" | "warning" | "error";

/**
 * Single system status check returned by diagnostics.
 */
export interface SystemCheck {
	id?: string | null;
	label?: string | null;
	description?: string | null;
	status?: StatusKey | string | null;
}

/**
 * Full system diagnostics payload returned by the status endpoint.
 */
export interface SystemStatusPayload {
	summary?: {
		overall?: StatusKey | string | null;
	};
	checks?: SystemCheck[];
	generatedAt?: string | null;
	site?: {
		version?: string | null;
		languageNativeName?: string | null;
		languageLabel?: string | null;
		locale?: string | null;
		environment?: string | null;
		url?: string | null;
		installType?: string | null;
		debugEnabled?: boolean;
	};
	storage?: {
		contentDirectory?: string | null;
		contentExists?: boolean;
		contentWritable?: boolean;
		contentDirectorySizeBytes?: number | string | null;
		languagesDirectory?: string | null;
		languagesDirectoryExists?: boolean;
		languagesDirectoryReadable?: boolean;
		languagesDirectorySizeBytes?: number | string | null;
		configPath?: string | null;
		configExists?: boolean;
		configSizeBytes?: number | string | null;
		debugLogPath?: string | null;
		debugLogExists?: boolean;
		debugLogReadable?: boolean;
		debugLogSizeBytes?: number | string | null;
		appDirectory?: string | null;
		appWritable?: boolean;
		appDirectorySizeBytes?: number | string | null;
		releaseRoot?: string | null;
		releaseRootSizeBytes?: number | string | null;
	};
	server?: {
		phpVersion?: string | null;
		phpSapi?: string | null;
		serverSoftware?: string | null;
		operatingSystem?: string | null;
		timezone?: string | null;
		memoryLimit?: string | null;
		maxExecutionTime?: number | string | null;
		uploadMaxFilesize?: string | null;
		postMaxSize?: string | null;
		extensions?: {
			intl?: boolean;
			curl?: boolean;
			zip?: boolean;
		};
	};
	database?: {
		serverType?: string | null;
		version?: string | null;
		host?: string | null;
		port?: number | string | null;
		name?: string | null;
		charset?: string | null;
		prefix?: string | null;
		schemaVersion?: number | string | null;
		requiredSchemaVersion?: number | string | null;
		schemaUpgradeRequired?: boolean;
		schemaIssuesCount?: number | string | null;
	};
	mail?: {
		driver?: string | null;
		transportReady?: boolean;
		fromEmail?: string | null;
		fromName?: string | null;
		smtpHost?: string | null;
		smtpPort?: string | null;
		smtpEncryption?: string | null;
		smtpAuth?: boolean;
		configurationLabel?: string | null;
		configurationPath?: string | null;
	};
	location?: {
		locationAnalyticsReady?: boolean;
		lastDownloadedAt?: string | null;
		databaseUpdatedAt?: string | null;
		databaseSizeBytes?: number | string | null;
		credentialsConfigured?: boolean;
		accountId?: string | null;
		databasePath?: string | null;
		databaseReadable?: boolean;
		downloadCommand?: string | null;
	};
	cache?: {
		enabled?: boolean;
		status?: string | null;
		activeDriver?: string | null;
		configuredDriver?: string | null;
		path?: string | null;
		writable?: boolean;
		directoryExists?: boolean;
		defaultTtl?: number | null;
		negativeTtl?: number | null;
		sizeBytes?: number | string | null;
		fileCount?: number | string | null;
		redis?: {
			configured?: boolean;
			host?: string | null;
			port?: number | null;
			available?: boolean;
			serverVersion?: string | null;
		};
		apcu?: {
			extensionLoaded?: boolean;
			enabled?: boolean;
			available?: boolean;
		};
		file?: {
			path?: string | null;
			exists?: boolean;
			writable?: boolean;
			available?: boolean;
			sizeBytes?: number | string | null;
			fileCount?: number | string | null;
		};
	};
	data?: {
		users?: number | string | null;
		links?: number | string | null;
		clicks?: number | string | null;
		sessions?: number | string | null;
		apiKeys?: number | string | null;
		webhooks?: number | string | null;
		auditEvents?: number | string | null;
		managedTables?: number | string | null;
	};
}

/**
 * Cache and performance status payload.
 */
export interface CacheStatusPayload {
	enabled?: boolean;
	status?: string | null;
	activeDriver?: string | null;
	configuredDriver?: string | null;
	path?: string | null;
	writable?: boolean;
	directoryExists?: boolean;
	defaultTtl?: number | null;
	negativeTtl?: number | null;
	sizeBytes?: number | string | null;
	fileCount?: number | string | null;
	redis?: {
		configured?: boolean;
		host?: string | null;
		port?: number | null;
		available?: boolean;
		serverVersion?: string | null;
	};
	apcu?: {
		extensionLoaded?: boolean;
		enabled?: boolean;
		available?: boolean;
	};
	file?: {
		path?: string | null;
		exists?: boolean;
		writable?: boolean;
		available?: boolean;
		sizeBytes?: number | string | null;
		fileCount?: number | string | null;
	};
}

/**
 * Payload sent to save cache and performance settings.
 */
export interface CacheConfigurationPayload {
	enabled?: boolean;
	driver?: string;
	defaultTtl?: number;
	negativeTtl?: number;
}

/**
 * Endpoint response returned by the cache status route.
 */
export interface CacheStatusResponse {
	data?: CacheStatusPayload;
}

/**
 * Endpoint response returned by the system status route.
 */
export interface SystemStatusResponse {
	data?: SystemStatusPayload;
}
