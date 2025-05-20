/**
 * Syncs author-speaker record data from Airtable to WordPress via Quick-Sync plugin.
 *
 * Trigger: Record "Publish" or status change on table "author-speaker".
 *
 * @param {Object} input - Airtable script input object.
 * @param {Object} input.config - Contains runtime variables (expects recordId).
 * @param {Function} input.secret - Function to retrieve secrets (expects 'API-SYNC').
 * @param {Object} base - Airtable base object for table access.
 * @param {Object} output - Airtable script output object.
 */

try {
  // 1️⃣  Pull the one runtime variable we need (recordId)
  const { recordId } = input.config();

  // 2️⃣  Get the Basic‑Auth header from Secrets
  const API_CREDS = input.secret('API-SYNC'); // "api_sync:xxxx ..."
  const BASIC = Buffer.from(API_CREDS).toString('base64');

  // 3️⃣  Look up the record in the author‑speaker table
  const table = base.getTable('author-speaker');
  const record = await table.selectRecordAsync(recordId);

  if (!record) {
    throw new Error(`Record with ID ${recordId} not found.`);
  }

  // 4️⃣  Build the payload expected by the WP Quick‑Sync plugin
  const fields = {};
  const authorTitle = record.getCellValueAsString('author_title');
  if (authorTitle) fields.author_title = authorTitle;
  const authorDesc = record.getCellValueAsString('author_description_html');
  if (authorDesc) fields.author_description = authorDesc;
  const profileImage = record.getCellValue('profile_image_link')?.[0]?.url;
  if (profileImage) fields.profile_image_url = profileImage;
  const status = record.getCellValueAsString('status');
  if (status) fields.status = status;

  const payload = {
    recordId: record.id,
    sku: record.getCellValueAsString('sku'), // Add SKU as unique ID
    fields,
  };

  console.log('QuickSync payload:', JSON.stringify(payload));

  // 5️⃣  Fire the POST to WordPress
  const res = await fetch('https://four12global.com/wp-json/four12/v1/tax-sync', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Basic ${BASIC}`,
    },
    body: JSON.stringify(payload),
  });

  // 6️⃣  Expose the HTTP result in the run log
  output.set('wpStatus', `${res.status} ${res.statusText}`);

  let responseText = await res.text();
  try {
    const json = JSON.parse(responseText);
    if (json.error) {
      console.error('WordPress sync error:', json.error);
      output.set('wpError', json.error);
    } else {
      output.set('wpResult', json);
    }
  } catch (e) {
    // Not JSON, just log
    if (!res.ok) {
      console.error(`WordPress sync failed: ${res.status} ${res.statusText} - ${responseText}`);
      output.set('wpError', responseText);
    }
  }
} catch (err) {
  console.error('Sync script error:', err);
  output.set('wpStatus', 'ERROR');
  output.set('wpError', err.message || String(err));
}
