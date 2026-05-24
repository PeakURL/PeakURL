/**
 * Dashboard API module.
 *
 * Route helpers and shared DTOs live behind this barrel so callers can import
 * API contracts from one stable module path.
 */

/**
 * Public dashboard API exports.
 *
 * Import API routes, request helpers, and shared DTOs from this barrel so
 * callers do not depend on the internal file layout of the API module.
 */

export * from "./api";
export type * from "./types";
