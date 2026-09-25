interface ConnectionError {
  title: string;
  message: string;
  steps: string[];
  help?: 'scope' | 'disabled-api';
}

export function explainGa4ConnectionError(message: string): ConnectionError {
  const lower = message.toLowerCase();

  // Check specific Google reasons before the generic permission-denied response.
  if (/access_token_scope_insufficient|insufficient.*scope|scope.*insufficient/.test(lower)) {
    return {
      title: 'Google has not granted all the permissions TrackFlow needs',
      message: 'Automatic Key Event setup needs permission to edit your Google Analytics settings.',
      steps: ['Ask the person who connected Google Analytics to renew the authorization with the analytics.edit permission, then try Save & Connect again.'],
      help: 'scope',
    };
  }

  if (/service_disabled|accessnotconfigured|has not been used|api.*(?:disabled|not enabled)/.test(lower)) {
    return {
      title: 'A Google service needed for setup is turned off',
      message: 'Google Analytics Admin API must be enabled before TrackFlow can automatically set up Key Events.',
      steps: ['Ask the person who manages your Google Cloud project to enable Google Analytics Admin API in the project used for this connection.'],
      help: 'disabled-api',
    };
  }

  if (/ga4 admin api error \[403\]|caller does not have permission|permission_denied/.test(lower)) {
    return {
      title: 'Google denied access to the selected Analytics property',
      message: 'TrackFlow could not check Key Events for this property. The Google account used for the connection may not have access, or the Property ID may be incorrect. Google did not specify which one.',
      steps: [
        'Open Google Analytics with the same Google account that was used to authorize this connection.',
        'Select the property for your store. In Admin → Property details, check that its Property ID matches the number entered below.',
        'Ask the property administrator to check your account in Admin → Property access management and grant the Editor role for automatic setup.',
        'If the connection was authorized with a different Google account, ask the person who set it up to generate a new OAuth Refresh Token using the account that has access. Then try Save & Connect again.',
      ],
    };
  }

  if (/invalid_grant|expired or revoked|token.*expired|\[401\]/.test(lower)) {
    return {
      title: 'Google authorization is no longer valid',
      message: 'Google could not accept the saved authorization. It may have expired or been revoked.',
      steps: ['Ask the person who connected Google Analytics to authorize it again and replace the OAuth Refresh Token, then try Save & Connect again.'],
    };
  }

  if (/invalid_client|unauthorized_client|oauth token exchange failed/.test(lower)) {
    return {
      title: 'Google could not authorize this connection',
      message: 'The Google connection details could not be verified.',
      steps: ['Ask the person who set up the connection to check that OAuth Client ID, Client Secret and Refresh Token belong to the same setup, then try again.'],
    };
  }

  if (lower.includes('required together with property id')) {
    return {
      title: 'Automatic Key Event setup needs more connection details',
      message: 'You entered a Property ID, but some Google authorization fields are missing.',
      steps: ['Fill in OAuth Client ID, Client Secret and Refresh Token with help from the person who manages your Google connection.'],
    };
  }

  if (/\[429\]|\[5\d\d\]/.test(lower)) {
    return {
      title: 'Google is temporarily unable to complete the connection',
      message: 'Google returned a temporary service error or request limit.',
      steps: ['Wait a few minutes and try Save & Connect again. If this continues, contact support.'],
    };
  }

  return {
    title: 'We could not complete the Google Analytics connection',
    message: 'The connection check failed. The response does not identify a cause we can confirm.',
    steps: ['Check the connection details and try again. If this continues, contact support for help.'],
  };
}
