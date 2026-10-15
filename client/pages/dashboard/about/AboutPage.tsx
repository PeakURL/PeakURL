import { useSearchParams } from "react-router";
import { AboutFooter } from "./AboutFooter";
import { AboutHeader } from "./AboutHeader";
import { AboutTabs } from "./AboutTabs";
import { CompletionNotice } from "./CompletionNotice";
import { Contributors } from "./Contributors";
import { Overview } from "./Overview";
import { ReleaseNotes } from "./ReleaseNotes";
import { Resources } from "./Resources";
import type { AboutTabId } from "./types";

function AboutPage() {
	const [searchParams, setSearchParams] = useSearchParams();
	const requestedSource = searchParams.get("source");
	const source =
		requestedSource === "install" ||
		requestedSource === "update" ||
		requestedSource === "reinstall"
			? requestedSource
			: null;
	const requestedTab = searchParams.get("tab");
	// The URL keeps tab navigation and completion states correct across browser history.
	const activeTab =
		requestedTab === "overview" ||
		requestedTab === "releases" ||
		requestedTab === "contributors"
			? requestedTab
			: source === "update" || source === "reinstall"
				? "releases"
				: "overview";
	const selectTab = (tab: AboutTabId) => {
		const params = new URLSearchParams(searchParams);
		params.set("tab", tab);
		setSearchParams(params);
	};

	return (
		<div className="about-page">
			<AboutHeader />
			{source && <CompletionNotice source={source} />}
			<div className="about-columns">
				<div className="about-content">
					<AboutTabs activeTab={activeTab} onChange={selectTab} />
					<div
						key={activeTab}
						role="tabpanel"
						id="about-panel"
						aria-labelledby={`about-tab-${activeTab}`}
						tabIndex={0}
						className="about-panel"
					>
						{activeTab === "overview" && <Overview />}
						{activeTab === "releases" && <ReleaseNotes />}
						{activeTab === "contributors" && <Contributors />}
					</div>
				</div>
				<Resources />
			</div>
			<AboutFooter />
		</div>
	);
}

export default AboutPage;
