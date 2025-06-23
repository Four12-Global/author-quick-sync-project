/**
 * Author Quick Sync – Airtable Automation Script
 *
 * WHAT IT DOES
 * 1. Runs inside an Airtable automation when the Admin clicks the ✨Sync Author✨ button.
 * 2. Collects the record’s data, squashes it into a neat JSON payload, and fires it at the
 *    WordPress Author‑Sync REST endpoint.
 * 3. Logs the WordPress response back into the triggering record for visibility.
 *
 * Assumptions:
 * – This script is attached to a "Run a script" action in an Airtable automation.
 * – Input variables supplied by the automation are:
 *     • recordId          – the Airtable Record ID to sync
 *     • apiBaseUrl        – e.g. "https://four12global.com" (NO trailing slash)
 *     • basicAuthHeader   – pre‑encoded "Basic base64(user:appPassword)"
 *
 * Tweak/extend as required but keep the overall contract identical to SeriesQuickSyncScript.js.
 */

/*******************************
 * 0 · Helper Utilities        *
 ******************************/
const log = (...msg) => console.log('[AuthorQuickSync]', ...msg);

/**
 * Fetch wrapper that logs request + response and throws on non‑2xx status codes.
 */
async function wpFetch(url, options = {}) {
  const safeOpts = {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...options.headers,
    },
    ...options,
  };

  const start = Date.now();
  const res   = await fetch(url, safeOpts);
  const ms    = Date.now() - start;
  const text  = await res.text();

  log(`${safeOpts.method} → ${url} (${res.status}) in ${ms} ms`);
  log('Response body (truncated 2 KB):', text.slice(0, 2048));

  if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  return JSON.parse(text);
}

/*******************************
 * 1 · Pull Automation Inputs  *
 ******************************/
// prettier‑ignore
const {
  recordId,
  apiBaseUrl,
  basicAuthHeader,
} = input.config();

if (!recordId) throw new Error('Missing input.recordId');
if (!apiBaseUrl) throw new Error('Missing input.apiBaseUrl');
if (!basicAuthHeader) throw new Error('Missing input.basicAuthHeader');

/*******************************
 * 2 · Grab the Record         *
 ******************************/
const table = base.getTable('Author / Speaker'); // adjust if your table name differs
const record = await table.selectRecordAsync(recordId);
if (!record) throw new Error(`Record not found: ${recordId}`);

/*******************************
 * 3 · Build Payload           *
 ******************************/
function val(field) {
  return record.getCellValue(field);
}

function firstAttachmentId(field) {
  const attachmentArr = val(field);
  return Array.isArray(attachmentArr) && attachmentArr.length > 0
    ? attachmentArr[0].id // Airtable attachment ID string
    : null;
}

// Derive or fallback values
const authorSlug = val('author_slug') ||
  (val('author_title') || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');

const payload = {
  airtableRecordId: record.id,
  sku:              val('sku'),
  fields: {
    name:            val('author_title'),
    slug:            authorSlug,
    description:     val('author_description') || '',
    as_description:  val('as_description') || '',
    news_description: val('news_description') || '',
    profile_image:   val('profile_image_wp_id') || null,
    sku:             val('sku'),
  },
};

log('Outgoing payload →', JSON.stringify(payload, null, 2));

/*******************************
 * 4 · Fire at WordPress       *
 ******************************/
const endpoint = `${apiBaseUrl.replace(/\/$/, '')}/wp-json/four12/v1/author-sync`;

let wpResponse;
try {
  wpResponse = await wpFetch(endpoint, {
    body: JSON.stringify(payload),
    headers: { Authorization: basicAuthHeader },
  });
  log('Sync SUCCESS →', wpResponse);
} catch (err) {
  log('Sync FAILED →', `${err.message}`);
  await table.updateRecordAsync(record, {
    Sync_Status: '❌ Failed',
    Sync_Response: `${err.message}`.slice(0, 10000),
  });
  throw err; // Let Airtable mark the run failed for visibility
}

/*******************************
 * 5 · Persist WP Response     *
 ******************************/
await table.updateRecordAsync(record, {
  Sync_Status:   '✅ Synced',
  Sync_Response: JSON.stringify(wpResponse).slice(0, 10000),
  term_id:       wpResponse?.data?.term_id || null,
});

log('Author Quick Sync complete ✅');
