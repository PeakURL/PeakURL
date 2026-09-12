interface DetailMetricProps {
	/** Display-ready metric value. */
	value: string;

	/** Visual treatment for the metric. */
	tone: "clicks" | "unique";
}

function DetailMetric({ value, tone }: DetailMetricProps) {
	return (
		<span className={`links-detail-metric links-detail-metric-${tone}`}>
			{value}
		</span>
	);
}

export default DetailMetric;
