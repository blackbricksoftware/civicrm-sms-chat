// CRM.api4 is CiviCRM's session-authenticated JS client (sets the required
// X-Requested-With header; checkPermissions can't be disabled client-side).
// It returns an array-like result; single-row actions are unwrapped here.
export function api4(entity, action, params = {}) {
  return window.CRM.api4(entity, action, params);
}

export async function getContext(contactId) {
  const r = await api4('SmsChat', 'getContext', { contactId });
  return r[0];
}

export async function getMessages(contactId, { sinceId = null, before = null, limit = 50 } = {}) {
  const params = { contactId, sinceId, limit };
  if (before) { params.beforeId = before.id; params.beforeAt = before.at; }
  const r = await api4('SmsChat', 'getMessages', params);
  return Array.from(r);
}

export async function sendMessage(contactId, providerId, text) {
  const r = await api4('SmsChat', 'send', { contactId, providerId, text });
  return r[0];
}

export function errorMessage(err) {
  if (!err) return 'Unknown error';
  if (typeof err === 'string') return err;
  return err.error_message || err.message || 'Request failed';
}
