import brand from "../../server/services/brand.json" with { type: "json" };

import { __ } from "@/i18n";

/** Canonical product tagline, shared with PHP public pages. */
export function getBrandTagline(): string {
	return __(brand.tagline);
}
