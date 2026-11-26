import type { GeoPermissibleObjects } from "d3-geo";
import { feature } from "topojson-client";
import type { GeometryCollection, Topology } from "topojson-specification";

const geographyUrl =
	"https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-50m.json";
const geographyTimeoutMs = 15_000;

let geographies: Promise<GeoPermissibleObjects[]> | undefined;

/** Share pending and successful requests. A failed request can be retried. */
export function loadGeographies() {
	geographies ??= fetch(geographyUrl, {
		credentials: "omit",
		referrerPolicy: "no-referrer",
		signal: AbortSignal.timeout(geographyTimeoutMs),
	})
		.then(async (response) => {
			if (!response.ok) throw new Error("Failed to load world map data");
			const topology: Topology<{ countries: GeometryCollection }> =
				await response.json();
			const countries = topology?.objects?.countries;
			if (
				topology?.type !== "Topology" ||
				countries?.type !== "GeometryCollection" ||
				!countries.geometries.length
			) {
				throw new Error("World map data is missing countries");
			}
			return feature(topology, countries).features;
		})
		.catch((error: unknown) => {
			geographies = undefined;
			throw error;
		});
	return geographies;
}
