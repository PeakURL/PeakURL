// @ts-nocheck


function ComingSoonBadge({ className = '' }) {
	return (
		<span
			className={`inline-flex items-center rounded-full border border-amber-500/20 bg-amber-500/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-amber-700 dark:text-amber-300 ${className}`}
		>
			Coming Soon
		</span>
	);
}

export default ComingSoonBadge;
