import { test, expect } from "@playwright/test";
import { configureStore } from "@reduxjs/toolkit";
import baseApi from "../../client/state/slices/api/base";
import { webhookApi } from "../../client/state/slices/api/webhook";
import type { WebhookSummary } from "../../client/state/slices/api/types";

function createTestStore() {
	return configureStore({
		reducer: {
			[baseApi.reducerPath]: baseApi.reducer,
		},
		middleware: (getDefaultMiddleware) =>
			getDefaultMiddleware().concat(baseApi.middleware),
	});
}

function createSampleWebhook(
	id = "wh_1",
	deliveryRetentionDays = 30
): WebhookSummary {
	return {
		id,
		label: "Slack Integration",
		url: "https://example.com/webhook",
		events: ["link.created"],
		secretHint: "whsec_...1234",
		verifySsl: true,
		deliveryRetentionDays,
		isActive: true,
		createdAt: "2026-09-01T00:00:00Z",
		updatedAt: "2026-09-01T00:00:00Z",
		health: {
			status: "healthy",
			total: 10,
			delivered: 10,
			failed: 0,
			successRate: 100,
		},
	};
}

function selectWebhooks(
	store: ReturnType<typeof createTestStore>
): WebhookSummary[] | undefined {
	return webhookApi.endpoints.getWebhooks.select()(store.getState()).data;
}

interface Deferred<T> {
	promise: Promise<T>;
	resolve: (value: T) => void;
	reject: (reason?: unknown) => void;
}

function createDeferred<T>(): Deferred<T> {
	let resolve!: (value: T) => void;
	let reject!: (reason?: unknown) => void;
	const promise = new Promise<T>((res, rej) => {
		resolve = res;
		reject = rej;
	});
	return { promise, resolve, reject };
}

function jsonResponse(data: unknown, status = 200): Response {
	return new Response(JSON.stringify(data), {
		status,
		headers: { "Content-Type": "application/json" },
	});
}

async function parseMockRequest(
	input: RequestInfo | URL,
	init?: RequestInit
): Promise<{
	url: string;
	method: string;
	body: Record<string, unknown> | undefined;
}> {
	let url: string;
	let method = "GET";
	let body: Record<string, unknown> | undefined;

	if (typeof input === "string") {
		url = input;
	} else if (input instanceof URL) {
		url = input.toString();
	} else {
		url = input.url;
		method = input.method;
	}

	if (init?.method) {
		method = init.method;
	}

	if (init?.body) {
		try {
			body =
				typeof init.body === "string"
					? JSON.parse(init.body)
					: (init.body as Record<string, unknown>);
		} catch {
			body = undefined;
		}
	} else if (typeof input !== "string" && !(input instanceof URL)) {
		try {
			const cloned = input.clone();
			const text = await cloned.text();
			if (text) {
				body = JSON.parse(text);
			}
		} catch {
			body = undefined;
		}
	}

	return { url, method: method.toUpperCase(), body };
}

test.describe("Frontend Webhook Retention Cache & Lifecycle Guards", () => {
	const originalFetch = globalThis.fetch;
	const OriginalRequest = globalThis.Request;
	const originalDateNow = Date.now;

	test.beforeAll(() => {
		globalThis.Request = class extends OriginalRequest {
			constructor(input: RequestInfo | URL, init?: RequestInit) {
				if (typeof input === "string" && input.startsWith("/")) {
					input = `http://localhost${input}`;
				}
				super(input, init);
			}
		};
	});

	test.afterAll(() => {
		globalThis.Request = OriginalRequest;
	});

	test.afterEach(() => {
		globalThis.fetch = originalFetch;
		Date.now = originalDateNow;
	});

	test("1. empty-cache first GET overlaps a successful save and resolves with the old value", async () => {
		const store = createTestStore();
		const wh = createSampleWebhook("wh_1", 30);

		const firstGetDeferred = createDeferred<Response>();
		let interceptedFirstGet = false;

		globalThis.fetch = async (input, init) => {
			const { url, method } = await parseMockRequest(input, init);

			if (
				method === "GET" &&
				url.includes("webhooks") &&
				!interceptedFirstGet
			) {
				interceptedFirstGet = true;
				return firstGetDeferred.promise;
			}

			if (method === "PUT" && url.includes("webhooks/wh_1")) {
				return jsonResponse({
					success: true,
					data: { ...wh, deliveryRetentionDays: 7 },
				});
			}

			return jsonResponse({ success: true, data: [] });
		};

		// 1. First getWebhooks starts on empty cache and is held in flight
		const firstGetPromise = store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);

		// Verify cache is still uninitialized before response resolves
		expect(selectWebhooks(store)).toBeUndefined();

		// 2. While first GET is in-flight, updateWebhook succeeds with retention 7
		const updateResult = await store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 7,
			})
		);
		expect(updateResult.data?.data?.deliveryRetentionDays).toBe(7);

		// 3. First GET resolves late with retention 30 and full webhook properties
		firstGetDeferred.resolve(
			jsonResponse({
				success: true,
				data: [
					{
						...wh,
						label: "Slack Integration Updated Label",
						deliveryRetentionDays: 30,
					},
				],
			})
		);
		await firstGetPromise;

		// 4. Cache must retain deliveryRetentionDays: 7 while preserving all other incoming properties
		const webhooks = selectWebhooks(store);
		expect(webhooks).toBeDefined();
		expect(webhooks?.length).toBe(1);
		expect(webhooks?.[0].deliveryRetentionDays).toBe(7);
		expect(webhooks?.[0].label).toBe("Slack Integration Updated Label");
		expect(webhooks?.[0].url).toBe(wh.url);
		expect(webhooks?.[0].events).toEqual(wh.events);
		expect(webhooks?.[0].secretHint).toBe(wh.secretHint);
		expect(webhooks?.[0].health).toEqual(wh.health);
	});

	test("2. existing-cache GET overlaps a successful save and resolves with the old value", async () => {
		const store = createTestStore();
		const wh = createSampleWebhook("wh_1", 30);

		// Step 1: Pre-populate cache with initial data (retention 30)
		globalThis.fetch = async () =>
			jsonResponse({ success: true, data: [wh] });
		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(30);

		// Step 2: Refetch starts and is held on the network
		const getDeferred = createDeferred<Response>();
		let interceptedGet = false;

		globalThis.fetch = async (input, init) => {
			const { url, method } = await parseMockRequest(input, init);

			if (
				method === "GET" &&
				url.includes("webhooks") &&
				!interceptedGet
			) {
				interceptedGet = true;
				return getDeferred.promise;
			}

			if (method === "PUT" && url.includes("webhooks/wh_1")) {
				return jsonResponse({
					success: true,
					data: { ...wh, deliveryRetentionDays: 7 },
				});
			}

			return jsonResponse({ success: true, data: [] });
		};

		const getPromise = store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);

		// Step 3: Save occurs and confirms retention 7
		const updateResult = await store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 7,
			})
		);
		expect(updateResult.data?.data?.deliveryRetentionDays).toBe(7);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(7);

		// Step 4: The held GET resolves late with retention 30 and incoming server updates
		getDeferred.resolve(
			jsonResponse({
				success: true,
				data: [
					{
						...wh,
						label: "Renamed Slack Hook",
						deliveryRetentionDays: 30,
					},
				],
			})
		);
		await getPromise;

		// Confirmed 7 is preserved; incoming label is adopted
		const webhooks = selectWebhooks(store);
		expect(webhooks?.[0].deliveryRetentionDays).toBe(7);
		expect(webhooks?.[0].label).toBe("Renamed Slack Hook");
	});

	test("3. identical Date.now values do not affect ordering", async () => {
		const store = createTestStore();
		const wh = createSampleWebhook("wh_1", 30);
		const fixedTimestamp = 1790000000000;

		Date.now = () => fixedTimestamp;

		globalThis.fetch = async () =>
			jsonResponse({ success: true, data: [wh] });
		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(30);

		const getDeferred = createDeferred<Response>();
		let interceptedGet = false;

		globalThis.fetch = async (input, init) => {
			const { url, method } = await parseMockRequest(input, init);

			if (
				method === "GET" &&
				url.includes("webhooks") &&
				!interceptedGet
			) {
				interceptedGet = true;
				return getDeferred.promise;
			}

			if (method === "PUT" && url.includes("webhooks/wh_1")) {
				return jsonResponse({
					success: true,
					data: { ...wh, deliveryRetentionDays: 7 },
				});
			}

			return jsonResponse({ success: true, data: [] });
		};

		const getPromise = store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);

		await store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 7,
			})
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(7);

		getDeferred.resolve(
			jsonResponse({
				success: true,
				data: [{ ...wh, deliveryRetentionDays: 30 }],
			})
		);
		await getPromise;

		// Generation sequence counter guarantees correct ordering despite identical clock timestamps
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(7);
	});

	test("4. fresh GETs adopt 90 and 0 without equality gates", async () => {
		const store = createTestStore();
		const wh = createSampleWebhook("wh_1", 30);
		let serverRetention = 30;

		globalThis.fetch = async (input, init) => {
			const { method } = await parseMockRequest(input, init);
			if (method === "PUT") {
				serverRetention = 7;
				return jsonResponse({
					success: true,
					data: { ...wh, deliveryRetentionDays: 7 },
				});
			}
			return jsonResponse({
				success: true,
				data: [{ ...wh, deliveryRetentionDays: serverRetention }],
			});
		};

		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(30);

		// Save confirms retention 7
		await store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 7,
			})
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(7);

		// Settle mutation effects
		await new Promise((resolve) => setTimeout(resolve, 0));

		// Fresh GET started after save: server now reports retention 90
		serverRetention = 90;
		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(90);

		// Subsequent fresh GET: server reports retention 0 (disabled)
		serverRetention = 0;
		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(0);
	});

	test("5. the mutation's invalidation-driven GET accepts fresh server data", async () => {
		const store = createTestStore();
		const wh = createSampleWebhook("wh_1", 30);

		// Initial cache established
		globalThis.fetch = async () =>
			jsonResponse({ success: true, data: [wh] });
		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(30);

		// When PUT is dispatched:
		// Server responds to PUT confirming 7.
		// When invalidation-driven GET fires automatically from providesTags,
		// server responds with differing fresh data (e.g., 90 and updated label).
		// This proves that the post-save invalidation GET is treated as fresh (reqSeq > savedAtSeq)
		// and accepts differing server data rather than forcing equality with the saved 7.
		let invalidationGetHandled = false;
		globalThis.fetch = async (input, init) => {
			const { method } = await parseMockRequest(input, init);
			if (method === "PUT") {
				return jsonResponse({
					success: true,
					data: { ...wh, deliveryRetentionDays: 7 },
				});
			}

			invalidationGetHandled = true;
			return jsonResponse({
				success: true,
				data: [
					{
						...wh,
						label: "Authoritative Server Label",
						deliveryRetentionDays: 90,
					},
				],
			});
		};

		await store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 7,
			})
		);

		// Allow RTK Query tag-invalidation re-fetch to settle
		await new Promise((resolve) => setTimeout(resolve, 10));

		expect(invalidationGetHandled).toBe(true);
		const webhooks = selectWebhooks(store);
		expect(webhooks?.[0].deliveryRetentionDays).toBe(90);
		expect(webhooks?.[0].label).toBe("Authoritative Server Label");
	});

	test("6. failed and aborted saves preserve confirmed state without installing a successful-save guard", async () => {
		const store = createTestStore();
		const wh = createSampleWebhook("wh_1", 30);

		// Initial cache established with retention 30
		globalThis.fetch = async () =>
			jsonResponse({ success: true, data: [wh] });
		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(30);

		// 1. Failed save (HTTP 500)
		globalThis.fetch = async (input, init) => {
			const { method } = await parseMockRequest(input, init);
			if (method === "PUT") {
				return jsonResponse(
					{ success: false, message: "Database error" },
					500
				);
			}
			return jsonResponse({ success: true, data: [wh] });
		};

		const failedResult = await store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 7,
			})
		);
		expect(failedResult.error).toBeDefined();
		// Confirmed cache state must remain 30
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(30);

		// 2. Aborted save
		const abortedPutDeferred = createDeferred<Response>();
		globalThis.fetch = async (input, init) => {
			const { method } = await parseMockRequest(input, init);
			if (method === "PUT") {
				return abortedPutDeferred.promise;
			}
			return jsonResponse({ success: true, data: [wh] });
		};

		const runningPut = store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 14,
			})
		);
		runningPut.abort();
		const abortedResult = await runningPut;
		expect(abortedResult.error).toBeDefined();
		// Confirmed cache state still 30
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(30);

		// 3. Verify neither failed nor aborted mutation installed a guard:
		// A fresh GET returning retention 90 must be accepted directly
		globalThis.fetch = async () =>
			jsonResponse({
				success: true,
				data: [{ ...wh, deliveryRetentionDays: 90 }],
			});

		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(90);
	});

	test("7. initial success, failed GET, and aborted GET settle with complete request cleanup", async () => {
		const store = createTestStore();
		const wh = createSampleWebhook("wh_1", 30);

		// 1. First GET success on empty cache
		globalThis.fetch = async () =>
			jsonResponse({ success: true, data: [wh] });
		const firstResult = await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);
		expect(firstResult.data?.length).toBe(1);

		// 2. Failed GET (500 Server Error)
		globalThis.fetch = async () =>
			new Response("Server Error", { status: 500 });
		const failedResult = await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);
		expect(failedResult.error).toBeDefined();

		// 3. Aborted GET
		const hangingDeferred = createDeferred<Response>();
		globalThis.fetch = async () => hangingDeferred.promise;
		const runningQuery = store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);
		runningQuery.abort();
		const abortResult = await runningQuery;
		expect(abortResult.error).toBeDefined();

		// 4. Verify in-flight requests metadata was fully cleaned up:
		// Save retention 7. Since inFlightRequests.size is 0, cleanUpCompletedGuards immediately prunes the guard.
		globalThis.fetch = async (input, init) => {
			const { method } = await parseMockRequest(input, init);
			if (method === "PUT") {
				return jsonResponse({
					success: true,
					data: { ...wh, deliveryRetentionDays: 7 },
				});
			}
			return jsonResponse({
				success: true,
				data: [{ ...wh, deliveryRetentionDays: 90 }],
			});
		};

		await store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 7,
			})
		);

		// Reset cache state: with complete request cleanup (inFlightRequests.size === 0),
		// reset cleanly clears obsolete guards.
		store.dispatch(baseApi.util.resetApiState());
		expect(selectWebhooks(store)).toBeUndefined();

		// Subsequent initial GET returns 90 without stale guard interference
		const nextResult = await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);
		expect(nextResult.data?.[0].deliveryRetentionDays).toBe(90);
	});

	test("8. two successive saves remain protected against an earlier GET", async () => {
		const store = createTestStore();
		const wh = createSampleWebhook("wh_1", 30);

		// Initial cache setup
		globalThis.fetch = async () =>
			jsonResponse({ success: true, data: [wh] });
		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(30);

		// GET 1 starts and is held on the network
		const get1Deferred = createDeferred<Response>();
		let hasInterceptedGet1 = false;

		globalThis.fetch = async (input, init) => {
			const { url, method, body } = await parseMockRequest(input, init);

			if (
				method === "GET" &&
				url.includes("webhooks") &&
				!hasInterceptedGet1
			) {
				hasInterceptedGet1 = true;
				return get1Deferred.promise;
			}

			if (method === "PUT") {
				const retention = (body?.deliveryRetentionDays as number) ?? 7;
				return jsonResponse({
					success: true,
					data: { ...wh, deliveryRetentionDays: retention },
				});
			}

			return jsonResponse({ success: true, data: [wh] });
		};

		const get1Promise = store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);

		// Save 1 confirms retention 7
		await store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 7,
			})
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(7);

		// Save 2 confirms retention 90
		await store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 90,
			})
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(90);

		// Now GET 1 resolves late with initial retention 30
		get1Deferred.resolve(
			jsonResponse({
				success: true,
				data: [{ ...wh, deliveryRetentionDays: 30 }],
			})
		);
		await get1Promise;

		// Confirmed value 90 must remain visible; neither 30 nor 7 can overwrite
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(90);
	});

	test("9. reset/removal while a guard or request is actually active, followed by a new cache entry, cannot inherit obsolete state", async () => {
		const store = createTestStore();
		const wh1 = createSampleWebhook("wh_1", 30);

		// Initial cache setup
		globalThis.fetch = async () =>
			jsonResponse({ success: true, data: [wh1] });
		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(30);

		// Part A: Reset while a request is active with an active guard
		const activeGetDeferred = createDeferred<Response>();
		let interceptedActiveGet = false;

		globalThis.fetch = async (input, init) => {
			const { url, method } = await parseMockRequest(input, init);
			if (
				method === "GET" &&
				url.includes("webhooks") &&
				!interceptedActiveGet
			) {
				interceptedActiveGet = true;
				return activeGetDeferred.promise;
			}
			if (method === "PUT") {
				return jsonResponse({
					success: true,
					data: { ...wh1, deliveryRetentionDays: 7 },
				});
			}
			return jsonResponse({ success: true, data: [wh1] });
		};

		const activeGetPromise = store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);

		// Save establishes active guard for retention 7 (kept alive by in-flight activeGet)
		await store.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 7,
			})
		);
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(7);

		// Reset while active request is still in-flight
		store.dispatch(baseApi.util.resetApiState());
		expect(selectWebhooks(store)).toBeUndefined();

		// Release the active GET that was started prior to reset
		activeGetDeferred.resolve(
			jsonResponse({
				success: true,
				data: [{ ...wh1, deliveryRetentionDays: 30 }],
			})
		);
		await activeGetPromise;

		// Settle any dangling microtasks
		await new Promise((resolve) => setTimeout(resolve, 0));

		// Fresh initial GET on the reset store: server reports retention 30
		globalThis.fetch = async () =>
			jsonResponse({
				success: true,
				data: [{ ...wh1, deliveryRetentionDays: 30 }],
			});

		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);

		// Must adopt fresh server retention 30; must not inherit obsolete retention 7 guard
		expect(selectWebhooks(store)?.[0].deliveryRetentionDays).toBe(30);

		// Part B: Removal of a webhook clears obsolete guard
		// Webhook 1 is saved with 7, but next GET omits wh_1 (deleted on server) and returns wh_2
		const wh2 = createSampleWebhook("wh_2", 30);
		globalThis.fetch = async () =>
			jsonResponse({ success: true, data: [wh2] });

		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);
		const webhooksAfterRemoval = selectWebhooks(store);
		expect(
			webhooksAfterRemoval?.find((w) => w.id === "wh_1")
		).toBeUndefined();
		expect(
			webhooksAfterRemoval?.find((w) => w.id === "wh_2")
		).toBeDefined();

		// When wh_1 is subsequently re-created on server with retention 30, it is not clamped to 7
		globalThis.fetch = async () =>
			jsonResponse({
				success: true,
				data: [{ ...wh1, deliveryRetentionDays: 30 }, wh2],
			});

		await store.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);
		expect(
			selectWebhooks(store)?.find((w) => w.id === "wh_1")
				?.deliveryRetentionDays
		).toBe(30);
	});

	test("10. separate store instances cannot share retention protection", async () => {
		const storeA = createTestStore();
		const wh = createSampleWebhook("wh_1", 30);

		// Establish cache in Store A
		globalThis.fetch = async () =>
			jsonResponse({ success: true, data: [wh] });
		await storeA.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);

		// In Store A: hold a GET in flight and perform a save of retention 7
		const storeAHeldDeferred = createDeferred<Response>();
		let interceptedStoreAGet = false;

		globalThis.fetch = async (input, init) => {
			const { url, method } = await parseMockRequest(input, init);
			if (
				method === "GET" &&
				url.includes("webhooks") &&
				!interceptedStoreAGet
			) {
				interceptedStoreAGet = true;
				return storeAHeldDeferred.promise;
			}
			if (method === "PUT" && url.includes("webhooks/wh_1")) {
				return jsonResponse({
					success: true,
					data: { ...wh, deliveryRetentionDays: 7 },
				});
			}
			return jsonResponse({ success: true, data: [wh] });
		};

		const storeAPromise = storeA.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined, {
				forceRefetch: true,
			})
		);

		await storeA.dispatch(
			webhookApi.endpoints.updateWebhook.initiate({
				id: "wh_1",
				deliveryRetentionDays: 7,
			})
		);
		expect(selectWebhooks(storeA)?.[0].deliveryRetentionDays).toBe(7);

		// At this exact moment, Store A has an in-flight GET AND an active guard for retention 7.
		// Create Store B and initiate a GET on it:
		const storeB = createTestStore();
		globalThis.fetch = async () =>
			jsonResponse({
				success: true,
				data: [{ ...wh, deliveryRetentionDays: 30 }],
			});

		await storeB.dispatch(
			webhookApi.endpoints.getWebhooks.initiate(undefined)
		);

		// Store B must NOT share Store A's retention protection or active guard
		expect(selectWebhooks(storeB)?.[0].deliveryRetentionDays).toBe(30);

		// Finish Store A's held GET (returns 30)
		storeAHeldDeferred.resolve(
			jsonResponse({
				success: true,
				data: [{ ...wh, deliveryRetentionDays: 30 }],
			})
		);
		await storeAPromise;

		// Store A remains protected at 7; Store B remains unaffected at 30
		expect(selectWebhooks(storeA)?.[0].deliveryRetentionDays).toBe(7);
		expect(selectWebhooks(storeB)?.[0].deliveryRetentionDays).toBe(30);
	});
});
