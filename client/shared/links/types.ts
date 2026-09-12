/**
 * Minimal domain object occasionally embedded in link payloads.
 */
export interface LinkDomainRecord {
	/** Canonical domain string when present. */
	domain?: string | null;

	/** Fallback domain label used by some payloads. */
	name?: string | null;
}

/**
 * Minimal link shape required to construct a public short URL.
 */
export interface ShortUrlLinkLike {
	/** Canonical short URL returned directly by the API. */
	shortUrl?: string | null;

	/**
	 * Embedded custom-domain payload returned in varying API shapes.
	 *
	 * Some dashboard flows still surface this field as `unknown`, so utility
	 * helpers narrow it at runtime instead of forcing every caller to cast.
	 */
	domain?: unknown;

	/** Custom alias selected for the short link. */
	alias?: string | null;

	/** Generated short code used when no alias exists. */
	shortCode?: string | null;
}
