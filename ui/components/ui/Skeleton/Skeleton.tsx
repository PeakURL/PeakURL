import { cn } from "@/utils";

function Skeleton({
	className,
	...props
}: React.HTMLAttributes<HTMLDivElement>) {
	return <div className={cn("skeleton", className)} {...props} />;
}

export { Skeleton };
