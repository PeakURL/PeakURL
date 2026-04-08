import { Button } from '@/components/ui';
import { ExternalLink } from 'lucide-react';
import { API_SERVER_BASE_URL } from '@/constants';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';

const ApiImport = () => {
	const isRtl = isDocumentRtl();
	const apiKey = 'YOUR_API_KEY';

	return (
		<div className="bg-surface border border-stroke rounded-lg p-5">
			<h2 className="text-base font-semibold text-heading mb-3">
				{__('API Import')}
			</h2>
			<p className="text-sm text-text-muted mb-5">
				{__(
					'Use our API to programmatically import multiple URLs. Perfect for integrations and automated workflows.'
				)}
			</p>
			<div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
				<div>
					<h3 className="font-medium text-sm text-heading mb-3">
						{__('Example Request')}
					</h3>
					<pre className="ltr-literal-value overflow-x-auto rounded-lg border border-gray-700 bg-gray-900 p-4 text-xs text-gray-100">
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
				<div>
					<h3 className="font-medium text-sm text-heading mb-3">
						{__('Response')}
					</h3>
					<pre className="ltr-literal-value overflow-x-auto rounded-lg border border-gray-700 bg-gray-900 p-4 text-xs text-gray-100">
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
			<div className="mt-5">
				<a
					href="https://peakurl.org/docs/api/links"
					target="_blank"
					rel="noreferrer"
				>
					<Button size="sm">
						<ExternalLink
							className={`${isRtl ? 'ml-2' : 'mr-2'} h-4 w-4`}
						/>
						{__('View API Documentation')}
					</Button>
				</a>
			</div>
		</div>
	);
};

export default ApiImport;
