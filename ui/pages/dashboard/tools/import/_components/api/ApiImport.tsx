import { Button } from '@/components/ui';
import { ExternalLink } from 'lucide-react';
import { API_SERVER_BASE_URL } from '@/constants';
import { __ } from '@/i18n';

const ApiImport = () => {
	const apiKey = 'YOUR_API_KEY';

	return (
		<div className="import-panel import-api-panel">
			<h2 className="import-panel-title">
				{__('API Import')}
			</h2>
			<p className="import-panel-copy">
				{__(
					'Use our API to programmatically import multiple URLs. Perfect for integrations and automated workflows.'
				)}
			</p>
			<div className="import-api-grid">
				<div className="import-api-column">
					<h3 className="import-section-title">
						{__('Example Request')}
					</h3>
					<pre className="import-api-code-block">
						<code>{`POST ${API_SERVER_BASE_URL}/urls/bulk
Authorization: Bearer ${apiKey}
Content-Type: application/json

{
  "urls": [
    {
      "destinationUrl": "https://example.com/page1",
      "alias": "page1",
      "title": "Marketing Page",
      "password": "optional-password",
      "expiresAt": "2025-12-31"
    },
    {
      "destinationUrl": "https://example.com/page2",
      "alias": "page2"
    }
  ]
}`}</code>
					</pre>
				</div>
				<div className="import-api-column">
					<h3 className="import-section-title">
						{__('Response')}
					</h3>
					<pre className="import-api-code-block">
						<code>{`{
  "success": true,
  "data": {
    "results": [
      {
        "destinationUrl": "https://example.com/page1",
        "shortCode": "AbCd1",
        "alias": "page1",
        ...
      },
      {
        "destinationUrl": "https://example.com/page2",
        "shortCode": "page2",
        "alias": "page2"
      }
    ],
    "errors": []
  }
}`}</code>
					</pre>
				</div>
			</div>
			<div className="import-api-actions">
				<a
					href="https://peakurl.org/docs/api/links"
					target="_blank"
					rel="noreferrer"
				>
					<Button size="sm">
						<ExternalLink className="import-api-button-icon" />
						{__('View API Documentation')}
					</Button>
				</a>
			</div>
		</div>
	);
};

export default ApiImport;
