/**
 * AuthorMediaScript.js
 * ---------------------------------------------------------------------------
 * Syncs the Author/Speaker profile image from Airtable to the WordPress Media
 * Library and caches the resulting WP attachment ID + URL back in Airtable.
 *
 * How it works (identical muscle‑memory to SeriesMediaScript.js):
 *   1. Only runs for the triggering record (recordId provided by Automation).
 *   2. Skips if the record's `media_last_modified` timestamp is older than the
 *      cached `media_publish_timestamp` (nothing new to do).
 *   3. For the `profile_image` attachment:
 *        • If attachment is new OR WP ID empty OR cached external URL changed →
 *          download → upload to WP → write ID + URL back to Airtable.
 *        • If no attachment but WP fields exist → clear the WP meta fields.
 *   4. If any image actually changed, stamp `media_publish_timestamp` with NOW.
 *
 * Required Airtable fields on your **Authors** table:
 *   profile_image_attachment                (attachment)
 *   profile_image_wp_id          (number)
 *   profile_image_link           (single‑line URL)
 *   profile_image_external       (single‑line URL – caches original URL)
 *   media_last_modified          (last modified time)
 *   media_publish_timestamp      (date)
 *
 * Secrets:
 *   API-SYNC  → WP username:app_password  (same secret used everywhere else)
 *
 * ---------------------------------------------------------------------------
 */

/** === CONFIGURATION === */
const WP_MEDIA_ENDPOINT = "https://four12global.com/wp-json/wp/v2/media"; // change if staging
const TABLE_NAME        = "Authors";                                      // Airtable table name
const API_SECRET_NAME   = "API-SYNC";                                    // secret key name

// Image fields to process – easy to extend later
const IMAGE_FIELDS = [
  {
    airtableField: "profile_image_attachment",           // attachment column
    wpIdField: "profile_image_wp_id",        // number column
    wpLinkField: "profile_image_link",       // text/URL column
    externalCacheField: "profile_image_external" // text/URL column
  }
];

const LAST_MODIFIED_FIELD = "media_last_modified";
const PUBLISH_TS_FIELD    = "media_publish_timestamp";
/** --------------------------------------------------- */

(async () => {
  console.log("AuthorMediaScript.js: start");

  /** ---- Auth ---- */
  if (!input || !input.secret) {
    throw new Error("This script must run from an Airtable Automation context where `input.secret` is available.");
  }
  const basicAuth = await input.secret(API_SECRET_NAME);
  if (!basicAuth) throw new Error(`Missing required secret: ${API_SECRET_NAME}`);
  const AUTH_HEADER = "Basic " + Buffer.from(basicAuth).toString("base64");

  /** ---- Record Context ---- */
  const { recordId } = input.config();
  if (!recordId) throw new Error("Automation did not supply a recordId in input.config()");

  const table  = base.getTable(TABLE_NAME);
  const record = await table.selectRecordAsync(recordId);
  if (!record) throw new Error(`Record ${recordId} not found in table ${TABLE_NAME}`);

  // Timestamp skip check
  const lastMod   = record.getCellValue(LAST_MODIFIED_FIELD);
  const lastPush  = record.getCellValue(PUBLISH_TS_FIELD);
  if (lastMod && lastPush && new Date(lastMod) <= new Date(lastPush)) {
    console.log("No media changes detected – skipping heavy lift.");
    return;
  }

  /** ---- HELPERS ---- */
  async function downloadAttachment(attachment) {
    const response = await fetch(attachment.url);
    if (!response.ok) throw new Error(`Failed to download ${attachment.url}: ${response.status}`);
    return {
      blob: await response.blob(),
      filename: attachment.filename,
      contentType: response.headers.get("content-type") || "image/jpeg"
    };
  }

  async function uploadToWordPress({ blob, filename, contentType }) {
    const resp = await fetch(WP_MEDIA_ENDPOINT, {
      method: "POST",
      headers: {
        "Authorization": AUTH_HEADER,
        "Content-Disposition": `attachment; filename=\"${filename}\"`,
        "Content-Type": contentType
      },
      body: blob
    });
    if (!resp.ok) {
      const txt = await resp.text();
      throw new Error(`WP upload error: ${resp.status} – ${txt}`);
    }
    return await resp.json(); // expects { id, source_url }
  }

  /** ---- Main Processing ---- */
  let needsPublishStamp = false;
  const updates = {};

  for (const field of IMAGE_FIELDS) {
    const attachArr = record.getCellValue(field.airtableField) || [];
    const attachment = attachArr[0]; // only first image considered

    const currentWpId    = record.getCellValue(field.wpIdField);
    const cachedExtUrl   = record.getCellValueAsString(field.externalCacheField);

    // Determine state changes
    let action = "noop";
    if (attachment) {
      const urlChanged = cachedExtUrl !== attachment.url;
      const idMissing  = !currentWpId;
      if (urlChanged || idMissing) action = "upload";
    } else if (currentWpId || cachedExtUrl) {
      action = "clear";
    }

    switch (action) {
      case "upload": {
        console.log(`Uploading ${field.airtableField} → WP`);
        const imageData  = await downloadAttachment(attachment);
        const wpResponse = await uploadToWordPress(imageData);

        updates[field.wpIdField]         = wpResponse.id;
        updates[field.wpLinkField]       = wpResponse.source_url;
        updates[field.externalCacheField] = attachment.url;
        needsPublishStamp = true;
        break;
      }
      case "clear": {
        console.log(`Clearing WP fields for ${field.airtableField}`);
        updates[field.wpIdField]          = null;
        updates[field.wpLinkField]        = null;
        updates[field.externalCacheField] = null;
        needsPublishStamp = true;
        break;
      }
      default:
        // noop
    }
  }

  if (needsPublishStamp) {
    updates[PUBLISH_TS_FIELD] = new Date().toISOString();
  }

  if (Object.keys(updates).length) {
    await table.updateRecordAsync(record.id, updates);
    console.log("Record updated with:", updates);
  } else {
    console.log("Nothing to update.");
  }

  console.log("AuthorMediaScript.js: done");
})();
