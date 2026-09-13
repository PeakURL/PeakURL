import { Maximize2, Minimize2, Minus, Plus, RotateCcw } from "lucide-react";

import { __ } from "@/i18n";

interface WorldMapControlsProps {
	/** Whether the zoom-in button can be used. */
	canZoomIn: boolean;

	/** Whether the zoom-out button can be used. */
	canZoomOut: boolean;

	/** Whether the map is currently displayed in full screen. */
	isFullscreen: boolean;

	/** Increase the map zoom level. */
	onZoomIn: () => void;

	/** Decrease the map zoom level. */
	onZoomOut: () => void;

	/** Reset the map to its default view. */
	onReset: () => void;

	/** Toggle browser full-screen mode for the map. */
	onFullscreenToggle: () => void;
}

/**
 * Render the map zoom, reset, and full-screen controls.
 */
const WorldMapControls = ({
	canZoomIn,
	canZoomOut,
	isFullscreen,
	onZoomIn,
	onZoomOut,
	onReset,
	onFullscreenToggle,
}: WorldMapControlsProps) => (
	<div className="world-map-controls">
		<button
			type="button"
			onClick={onZoomIn}
			disabled={!canZoomIn}
			className="world-map-control"
			title={__("Zoom in")}
		>
			<Plus className="world-map-control-icon" />
		</button>
		<button
			type="button"
			onClick={onZoomOut}
			disabled={!canZoomOut}
			className="world-map-control"
			title={__("Zoom out")}
		>
			<Minus className="world-map-control-icon" />
		</button>
		<button
			type="button"
			onClick={onReset}
			className="world-map-control"
			title={__("Reset view")}
		>
			<RotateCcw className="world-map-control-icon" />
		</button>
		<button
			type="button"
			onClick={onFullscreenToggle}
			className="world-map-control"
			title={isFullscreen ? __("Exit full screen") : __("Full screen")}
		>
			{isFullscreen ? (
				<Minimize2 className="world-map-control-icon" />
			) : (
				<Maximize2 className="world-map-control-icon" />
			)}
		</button>
	</div>
);

export default WorldMapControls;
