import {
	Activity,
	CalendarClock,
	Download,
	History,
	Info,
	Key,
	Link2,
	Mail,
	MapPin,
	PieChart,
	Plug,
	Settings,
	Shield,
	Upload,
	Users,
	Zap,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

/** Page identities shared by headings, navigation, and global search. */
export const dashboardPageIcons = {
	overview: PieChart,
	links: Link2,
	activity: History,
	about: Info,
	settings: Settings,
	"settings-general": Settings,
	"settings-security": Shield,
	"settings-api": Key,
	"settings-integrations": Plug,
	"settings-email": Mail,
	"settings-location": MapPin,
	"settings-updates": Download,
	"settings-performance": Zap,
	users: Users,
	plugins: Plug,
	"tools-import": Upload,
	"tools-export": Download,
	"tools-scheduled-jobs": CalendarClock,
	"tools-system-status": Activity,
} satisfies Record<string, LucideIcon>;

export type DashboardPageId = keyof typeof dashboardPageIcons;
