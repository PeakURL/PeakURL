import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router";

import "./index.css";
import { PEAKURL_BASENAME } from "./constants";
import AppRouter from "./router";
import { ClientProviders } from "./components/providers";
import { initializeI18n } from "./i18n";
import { addGeneratorTag } from "./utils";

async function PeakURL(): Promise<void> {
	addGeneratorTag();

	try {
		await initializeI18n();
	} finally {
		createRoot(document.getElementById("root")!).render(
			<StrictMode>
				<ClientProviders>
					<BrowserRouter basename={PEAKURL_BASENAME || undefined}>
						<AppRouter />
					</BrowserRouter>
				</ClientProviders>
			</StrictMode>
		);
	}
}

void PeakURL();
