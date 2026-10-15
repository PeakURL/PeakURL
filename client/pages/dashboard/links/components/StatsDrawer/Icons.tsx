import { Home, Globe } from "lucide-react";

export const LocalIcon = ({ className }: { className?: string }) => {
	return <Home className={className} />;
};

export const UnknownLocationIcon = ({ className }: { className?: string }) => {
	return <Globe className={className} />;
};
