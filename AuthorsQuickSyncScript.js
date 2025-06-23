/**
 * Syncs author-speaker record data from Airtable to WordPress via Quick-Sync plugin.
 *
 * Trigger: Record "Publish" or status change on table "author-speaker".
 */

try {
  // 1️⃣ Pull the recordId from your automation input
  const { recordId } = input.config();

  // 2️⃣ Grab your Basic-Auth creds from Airtable Secrets
  const API_CREDS = input.secret('API-SYNC'); // e.g. "Four12API:abcd efgh …"
  const BASIC = Buffer.from(API_CREDS).toString('base64');

  // 3️⃣ Fetch the record
  const table = base.getTable('author-speaker');
  const record = await table.selectRecordAsync(recordId);
  if (!record) throw new Error(`Record ${recordId} not found.`);

  // 4️⃣ Build only the fields WP expects
  const syncFields = {};
  const authorTitle = record.getCellValueAsString('author_title');
  if (authorTitle) syncFields.author_title = authorTitle;

  const authorDesc = record.getCellValueAsString('author_description');
  if (authorDesc) syncFields.author_description = authorDesc;

  // profile_image_link is a plain URL field
  const profileImageLink = record.getCellValueAsString('profile_image_link');
  if (profileImageLink) syncFields.profile_image_link = profileImageLink;

  const statusValue = record.getCellValueAsString('status');
  if (statusValue) syncFields.status = statusValue;

  console.log(
'🔍 Raw Airtable value for profile_image_link:',
record.getCellValue('profile_image_link'),
record.getCellValueAsString('profile_image_link')
);
console.log(
'🔍 Fields so far:',
JSON.stringify(syncFields, null, 2)
);

  // 5️⃣ Assemble payload
  const payload = {
    recordId,
    sku:    record.getCellValueAsString('sku'),
    fields: syncFields,
  };

  // // 🖨️ Pretty-print for debugging
  // const pretty = JSON.stringify(payload, null, 2);
  // console.log('📬 Payload:\n' + pretty);
  // output.markdown(`\`\`\`json\n${pretty}\n\`\`\``);

  // 6️⃣ POST to WordPress
  let res;
  try {
    res = await fetch('https://four12global.com/wp-json/four12/v1/tax-sync', {
      method: 'POST',
      headers: {
        'Content-Type':  'application/json',
        'Authorization': `Basic ${BASIC}`,
      },
      body: JSON.stringify(payload),
    });
  } catch (fetchErr) {
    console.error('Fetch error:', fetchErr);
    output.set('wpStatus', 'ERROR');
    output.set('wpError', fetchErr.message);
    return;
  }

  // 7️⃣ Read and log raw response
  const bodyText = await res.text();
  console.log('Response body:', bodyText);

  // 8️⃣ Parse the JSON response
  let data;
  try {
    data = JSON.parse(bodyText);
  } catch {
    // If it wasn’t valid JSON and WP returned an error status
    if (!res.ok) {
      output.set('wpError', bodyText);
      console.error('Non-JSON error response:', bodyText);
    }
    return;
  }

  // 9️⃣ Safely write the WP response into your run outputs
  if (data.error) {
    const errStr = typeof data.error === 'string'
      ? data.error
      : JSON.stringify(data.error);
    output.set('wpError', errStr);
  } else {
    output.set('wpResult', JSON.stringify(data));
  }

  // 🔟 Safely set result or error
  if (data.error) {
    const errStr = typeof data.error === 'string'
      ? data.error
      : JSON.stringify(data.error);
    output.set('wpError', errStr);
  } else {
    output.set('wpResult', JSON.stringify(data));
  }

} catch (err) {
  console.error('Sync script error:', err);
  output.set('wpStatus', 'ERROR');
  output.set('wpError', err.message || String(err));
}