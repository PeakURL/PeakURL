import { expect, test } from "@playwright/test";

import { loadGeographies } from "../../client/components/charts/WorldMap/loadGeographies";

const countries = {
	type: "Topology",
	arcs: [
		[
			[0, 0],
			[0, 1],
			[1, 1],
			[0, 0],
		],
	],
	objects: {
		countries: {
			type: "GeometryCollection",
			geometries: [{ type: "Polygon", id: "826", arcs: [[0]] }],
		},
	},
};

test("the pinned topology request is shared, validated and retried only after failure", async () => {
	const originalFetch = globalThis.fetch;
	const requests: Request[] = [];
	const replies = [
		new Response(null, { status: 503 }),
		Response.json({
			objects: {
				countries: { type: "GeometryCollection", geometries: [] },
			},
		}),
		Response.json(countries),
	];
	globalThis.fetch = async (resource, init) => {
		requests.push(new Request(resource, init));
		const reply = replies.shift();
		if (!reply) throw new Error("Unexpected atlas request");
		return reply;
	};
	try {
		const first = loadGeographies();
		expect(loadGeographies()).toBe(first);
		await expect(first).rejects.toThrow("Failed to load world map data");
		await expect(loadGeographies()).rejects.toThrow("missing countries");

		const retry = loadGeographies();
		expect(loadGeographies()).toBe(retry);
		const features = await retry;
		expect(features).toMatchObject([
			{ id: "826", geometry: { type: "Polygon" } },
		]);
		expect(await loadGeographies()).toBe(features);
		expect(requests).toHaveLength(3);
		for (const request of requests) {
			expect(request.url).toBe(
				"https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-50m.json"
			);
			expect(request.credentials).toBe("omit");
			expect(request.referrerPolicy).toBe("no-referrer");
			expect(request.cache).toBe("default");
		}
	} finally {
		globalThis.fetch = originalFetch;
	}
});
