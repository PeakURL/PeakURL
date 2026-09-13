/**
 * Shared dashboard API DTOs.
 *
 * The API slice imports from this module instead of page/component barrels, so
 * request and response contracts stay independent from presentation files.
 *
 * Naming note: `Payload` means a request body or nested data object; `Response`
 * means the envelope returned by an endpoint.
 */

export type * from "./analytics";
export type * from "./imports";
export type * from "./links";
export type * from "./notices";
export type * from "./settings";
export type * from "./system";
export type * from "./updates";
export type * from "./users";
export type * from "./webhooks";
