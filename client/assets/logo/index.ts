// Vite resolves the bundled asset; Node render tests can import the same module.
export const peakurlLogoUrl = new URL("./peakurl.svg", import.meta.url).href;
