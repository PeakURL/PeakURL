import { cn } from "@/shared/formatting";

import type { PluginPreviewSkeletonProps } from "../types";

function PluginPreviewSkeleton({ className = "" }: PluginPreviewSkeletonProps) {
	return <div className={cn("plugins-skeleton", className)} />;
}

export default PluginPreviewSkeleton;
