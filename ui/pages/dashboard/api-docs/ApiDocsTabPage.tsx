// @ts-nocheck
'use client';
import { useState } from 'react';
import { Content } from './_components';
import { useParams } from 'react-router-dom';
import { useGetUserProfileQuery } from '@/store/slices/api/user';

import { API_SERVER_BASE_URL } from '@constants';

function ApiDocsTabPage() {
	const params = useParams();
	const activeSection = params.tab;
	const [selectedLanguage, setSelectedLanguage] = useState('curl');

	const { data: userData } = useGetUserProfileQuery();
	// Get the first available API key or fallback
	const apiKey = userData?.data?.apiKey || 'YOUR_API_KEY';

	const languages = ['curl', 'javascript', 'python', 'php'];

	const baseUrl =
		typeof window !== 'undefined'
			? window.location.origin
			: 'http://localhost:3000';

	const apiUrl =
		process.env.NODE_ENV === 'production'
			? API_SERVER_BASE_URL
			: `${baseUrl}/v1`;

	const codeExamples = {
		authentication: {
			curl: `# Authentication is done via API Key in the Authorization header
curl -X GET "${apiUrl}/users/me" \\
  -H "Authorization: Bearer ${apiKey}"`,
			javascript: `const response = await fetch('${apiUrl}/users/me', {
  headers: {
    'Authorization': 'Bearer ${apiKey}'
  }
});`,
			python: `import requests

headers = {
    'Authorization': 'Bearer ${apiKey}'
}

response = requests.get('${apiUrl}/users/me', headers=headers)`,
			php: `<?php
$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => '${apiUrl}/users/me',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => array(
    'Authorization: Bearer ${apiKey}'
  ),
));

$response = curl_exec($curl);
curl_close($curl);
?>`,
		},
		links: {
			create: {
				curl: `# Create a short link
curl -X POST "${apiUrl}/urls" \\
  -H "Authorization: Bearer ${apiKey}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "destinationUrl": "https://example.com/very-long-url",
    "alias": "my-link",
    "password": "optional-password",
    "expiresAt": "2024-12-31T23:59:59Z"
  }'`,
				javascript: `// Create a short link
const linkData = {
  destinationUrl: 'https://example.com/very-long-url',
  alias: 'my-link',
  password: 'optional-password',
  expiresAt: '2024-12-31T23:59:59Z'
};

const response = await fetch('${apiUrl}/urls', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ${apiKey}',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify(linkData)
});

const result = await response.json();`,
				python: `import requests
import json

link_data = {
    'destinationUrl': 'https://example.com/very-long-url',
    'alias': 'my-link',
    'password': 'optional-password',
    'expiresAt': '2024-12-31T23:59:59Z'
}

response = requests.post(
    '${apiUrl}/urls',
    headers={'Authorization': 'Bearer ${apiKey}'},
    json=link_data
)

result = response.json()`,
				php: `<?php
$data = json_encode([
    'destinationUrl' => 'https://example.com/very-long-url',
    'alias' => 'my-link',
    'password' => 'optional-password',
    'expiresAt' => '2024-12-31T23:59:59Z'
]);

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => '${apiUrl}/urls',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ${apiKey}',
        'Content-Type: application/json'
    ],
]);

$response = curl_exec($curl);
curl_close($curl);
?>`,
			},
			list: {
				curl: `# List all links
curl -X GET "${apiUrl}/urls?page=1&limit=10" \\
  -H "Authorization: Bearer ${apiKey}"`,
				javascript: `const response = await fetch('${apiUrl}/urls?page=1&limit=10', {
  headers: {
    'Authorization': 'Bearer ${apiKey}'
  }
});
const data = await response.json();`,
				python: `import requests
response = requests.get(
    '${apiUrl}/urls',
    params={'page': 1, 'limit': 10},
    headers={'Authorization': 'Bearer ${apiKey}'}
)`,
				php: `// GET request example`,
			},
			get: {
				curl: `# Get a single link
curl -X GET "${apiUrl}/urls/LINK_ID" \\
  -H "Authorization: Bearer ${apiKey}"`,
				javascript: `const response = await fetch('${apiUrl}/urls/LINK_ID', {
  headers: {
    'Authorization': 'Bearer ${apiKey}'
  }
});`,
				python: `import requests
response = requests.get(
    '${apiUrl}/urls/LINK_ID',
    headers={'Authorization': 'Bearer ${apiKey}'}
)`,
				php: `// GET request example`,
			},
			update: {
				curl: `# Update a link
curl -X PUT "${apiUrl}/urls/LINK_ID" \\
  -H "Authorization: Bearer ${apiKey}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "title": "New Title",
    "status": "inactive"
  }'`,
				javascript: `const updateData = {
  title: 'New Title',
  status: 'inactive'
};

const response = await fetch('${apiUrl}/urls/LINK_ID', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer ${apiKey}',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify(updateData)
});`,
				python: `import requests
data = {'title': 'New Title', 'status': 'inactive'}
response = requests.put(
    '${apiUrl}/urls/LINK_ID',
    headers={'Authorization': 'Bearer ${apiKey}'},
    json=data
)`,
				php: `// PUT request example`,
			},
			delete: {
				curl: `# Delete a link
curl -X DELETE "${apiUrl}/urls/LINK_ID" \\
  -H "Authorization: Bearer ${apiKey}"`,
				javascript: `const response = await fetch('${apiUrl}/urls/LINK_ID', {
  method: 'DELETE',
  headers: {
    'Authorization': 'Bearer ${apiKey}'
  }
});`,
				python: `import requests
response = requests.delete(
    '${apiUrl}/urls/LINK_ID',
    headers={'Authorization': 'Bearer ${apiKey}'}
)`,
				php: `// DELETE request example`,
			},
		},
		analytics: {
			curl: `# Get stats for a link
curl -X GET "${apiUrl}/analytics/url/LINK_ID/stats" \\
  -H "Authorization: Bearer ${apiKey}"`,
			javascript: `const response = await fetch('${apiUrl}/analytics/url/LINK_ID/stats', {
  headers: {
    'Authorization': 'Bearer ${apiKey}'
  }
});
const stats = await response.json();`,
			python: `import requests
response = requests.get(
    '${apiUrl}/analytics/url/LINK_ID/stats',
    headers={'Authorization': 'Bearer ${apiKey}'}
)`,
			php: `// Use curl as above with GET request`,
		},
		'qr-codes': {
			curl: `# Get a QR code for a link
curl -X GET "${apiUrl}/urls/LINK_ID/qr" \\
  -H "Authorization: Bearer ${apiKey}"`,
			javascript: `const response = await fetch('${apiUrl}/urls/LINK_ID/qr', {
  headers: {
    'Authorization': 'Bearer ${apiKey}'
  }
});
const qrCode = await response.json();`,
			python: `import requests
response = requests.get(
    '${apiUrl}/urls/LINK_ID/qr',
    headers={'Authorization': 'Bearer ${apiKey}'}
)`,
			php: `// Use curl as above with GET request`,
		},
		webhooks: {
			curl: `# Create a webhook
curl -X POST "${apiUrl}/webhooks" \\
  -H "Authorization: Bearer ${apiKey}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "url": "https://your-server.com/webhook",
    "events": ["link.created", "link.clicked"]
  }'`,
			javascript: `// Create a webhook
const webhookData = {
  url: 'https://your-server.com/webhook',
  events: ['link.created', 'link.clicked']
};

const response = await fetch('${apiUrl}/webhooks', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ${apiKey}',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify(webhookData)
});`,
			python: `import requests
webhook_data = {
    'url': 'https://your-server.com/webhook',
    'events': ['link.created', 'link.clicked']
}

response = requests.post(
    '${apiUrl}/webhooks',
    headers={'Authorization': 'Bearer ${apiKey}'},
    json=webhook_data
)`,
			php: `// Use curl as above with POST request`,
		},
	};

	return (
		<div className="lg:col-span-3">
			<Content
				activeSection={activeSection}
				languages={languages}
				selectedLanguage={selectedLanguage}
				setSelectedLanguage={setSelectedLanguage}
				codeExamples={codeExamples}
			/>
		</div>
	);
}

export default ApiDocsTabPage;
