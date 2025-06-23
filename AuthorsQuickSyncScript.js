/**
 * AuthorQuickSyncScript.js – v1.1
 * -------------------------------------------------------------
 * Pushes an Author / Speaker record from Airtable to WordPress via the
 * Author‑Quick‑Sync REST endpoint.  Mirrors the structure of
 * SeriesQuickSyncScript.js so every Quick‑Sync behaves the same.
 *
 * CHANGELOG
 * 2025‑06‑23 • v1.1  Added verbose logging: payload preview, WP duration, 
 *                     and optional Airtable output fields.
 * -------------------------------------------------------------
 *
 * INPUT VARIABLES (Automation → “Run a script” step)
 *   • recordId    – Airtable Record ID to sync
 *   • apiBaseUrl  – e.g. "https://four12global.com"  (NO trailing slash)
 *
 * SECRETS (Automation → left‑hand “Secrets” panel)
 *   • API-SYNC    – **plain** `username:application‑password` string.
 *                   The script base64‑encodes it on the fly.
 *
 * The script writes the parsed WordPress response AND a truncated payload
 * preview into the Airtable output panel so downstream steps—or your eyes—
 * can see exactly what was sent.
 * -------------------------------------------------------------
 */

/***************
 * 0 · Config  *
 **************/
const WP_ROUTE_SUFFIX      = '/wp-json/four12/v1/author-sync';
const AIRTABLE_TABLE_NAME  = 'author-speaker';
const API_SECRET_NAME      = 'API-SYNC';

// Field map: Airtable field ➜ WP payload key
const FIELD_MAP = {
  author_title:        'name',
  author_slug:         'slug',
  author_description:  'as_description',
  profile_image_wp_id: 'profile_image',   // Attachment ID sent as number
  sku:                 'sku'              // Also promoted to top‑level
};

/*********************
 * Helper — logging  *
 *********************/
function log(msg) {
  const ts = new Date().toISOString();
  console.log(`[${ts}] ${msg}`);
}

function previewJson(obj, max = 4000) {
  return JSON.stringify(obj, null, 2).slice(0, max);
}

/********************
 * Main IIFE runner *
 *******************/
(async () => {
  try {
    /******************************
     * 1 · Inputs & Auth Headers *
     *****************************/
    const cfg = input.config();          // ← must call only ONCE
    const { recordId, apiBaseUrl } = cfg;
    if (!recordId)   throw new Error('Missing input «recordId»');
    if (!apiBaseUrl) throw new Error('Missing input «apiBaseUrl»');

    const credsPlain = await input.secret(API_SECRET_NAME);
    if (!credsPlain) throw new Error(`Secret «${API_SECRET_NAME}» not found.`);
    const basicAuthHeader = 'Basic ' + Buffer.from(credsPlain).toString('base64');

    /***********************
     * 2 · Fetch the record *
     ***********************/
    const table  = base.getTable(AIRTABLE_TABLE_NAME);
    const record = await table.selectRecordAsync(recordId);
    if (!record) throw new Error(`Record ${recordId} not found in “${AIRTABLE_TABLE_NAME}”.`);

    /*************************
     * 3 · Build WP payload  *
     *************************/
    const fields = {};

    for (const [airField, wpKey] of Object.entries(FIELD_MAP)) {
      let val = record.getCellValue(airField);
      if (val === undefined || val === null) continue;

      // Arrays (multiselects / linked records) → names array
      if (Array.isArray(val)) {
        val = val.map(v => (typeof v === 'object' && v !== null && 'name' in v) ? v.name : v);
      }
      // Attachment ID field is numeric already – leave as‑is
      // Everything else → string via Airtable helper
      else if (typeof val === 'object') {
        if ('id' in val) val = val.id; // attachment obj → id (number)
        else val = record.getCellValueAsString(airField);
      }
      else if (typeof val !== 'number') {
        val = record.getCellValue(airField);
      }

      fields[wpKey] = val;
    }

    // Minimal required fallbacks
    if (!fields.name) fields.name = record.getCellValueAsString('author_title') || record.name;
    if (!fields.slug) fields.slug = record.getCellValueAsString('author_slug');
    if (!fields.sku)  fields.sku  = record.getCellValueAsString('sku');

    if (!fields.sku) throw new Error('SKU is required but missing.');

    const payload = {
      airtableRecordId: record.id,
      sku: fields.sku,
      fields
    };

    // Verbose logging of payload (truncated)
    const payloadPreview = previewJson(payload);
    log('Payload preview (truncated):\n' + payloadPreview);
    output.set('payloadPreview', payloadPreview);

    /******************************
     * 4 · POST to WordPress API  *
     *****************************/
    const endpoint = apiBaseUrl.replace(/\/$/, '') + WP_ROUTE_SUFFIX;
    log(`Hitting WP endpoint → ${endpoint}`);

    const t0 = Date.now();
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': basicAuthHeader
      },
      body: JSON.stringify(payload)
    });
    const durationMs = Date.now() - t0;

    const bodyTxt = await res.text();

    if (!res.ok) {
      output.set('syncStatus', `HTTP_${res.status}`);
      output.set('wpBody', bodyTxt.slice(0, 800));
      throw new Error(`WP responded ${res.status} in ${durationMs} ms: ${bodyTxt.slice(0, 400)}`);
    }

    let bodyJson;
    try { bodyJson = JSON.parse(bodyTxt); }
    catch (e) {
      output.set('syncStatus', 'JSON_PARSE_ERR');
      throw new Error(`Cannot parse WP JSON: ${bodyTxt.slice(0,400)}`);
    }

    output.set('wpResponse', bodyJson);
    output.set('syncStatus', 'Success');
    output.set('wpDurationMs', durationMs);
    log(`✅ Author sync succeeded in ${durationMs} ms.`);

  } catch (err) {
    log(`❌ ${err.message}`);
    output.set('scriptError', err.message);
    throw err; // Fail the Airtable automation step for visibility
  }
})();