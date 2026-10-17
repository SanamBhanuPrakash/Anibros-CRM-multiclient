const o=`| Answer | Meaning | What to do |
|---|---|---|
| 200 | Received (it is saved before we answer) | Nothing. Sending the same event again is safe. |
| 400 | We could not read the body | Fix the body (JSON object, or form fields). Do not retry unchanged. |
| 401 | The proof (signature / key) did not match | Check the secret and the signing steps. Do not retry unchanged. |
| 403 | This website is not on the allowed list | Ask us to add your site address. |
| 404 | Wrong address, or the connection was deleted | Check the address. |
| 429 | Too many requests | Wait the number of seconds in the \`Retry-After\` header, then retry. |
| 503 / 5xx | Paused, or we are briefly unavailable | Retry later (the \`Retry-After\` header says when). Nothing was saved. |`,i=`We read these fields (any spelling in the list works), and keep every other field as an answer on the lead:

- name (or first_name and last_name)
- phone (phone_number, mobile, whatsapp)
- email
- message, and any question/answer pairs you like
- campaign, and utm_source / utm_medium / utm_campaign / utm_term / utm_content, gclid, fbclid, page_url

A lead needs at least a phone number or an e-mail address. Names, numbers and text are trimmed and length-limited; bodies over 256 KB are refused.`;function h(e,t){return`### curl

\`\`\`bash
BODY='{"name":"Asha Verma","phone":"+919876543210","email":"asha@example.com","message":"Interested in the 3 BHK"}'
T=$(date +%s)
SECRET='${t}'
SIG=$(printf '%s.%s' "$T" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

curl -X POST '${e}' \\
  -H 'Content-Type: application/json' \\
  -H "X-Anibros-Signature: t=$T,v1=$SIG" \\
  -H 'X-Event-Id: your-own-unique-id-1' \\
  -d "$BODY"
\`\`\`

### Node.js

\`\`\`js
import crypto from 'node:crypto';

const body = JSON.stringify({ name: 'Asha Verma', phone: '+919876543210', email: 'asha@example.com', message: 'Interested in the 3 BHK' });
const t = Math.floor(Date.now() / 1000);
const v1 = crypto.createHmac('sha256', process.env.LEAD_SECRET).update(\`\${t}.\${body}\`).digest('hex');

const res = await fetch('${e}', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-Anibros-Signature': \`t=\${t},v1=\${v1}\`, 'X-Event-Id': 'your-own-unique-id-1' },
  body,
});
\`\`\`

### Python

\`\`\`python
import hashlib, hmac, json, os, time, requests

body = json.dumps({"name": "Asha Verma", "phone": "+919876543210", "email": "asha@example.com", "message": "Interested in the 3 BHK"})
t = str(int(time.time()))
v1 = hmac.new(os.environ["LEAD_SECRET"].encode(), f"{t}.{body}".encode(), hashlib.sha256).hexdigest()

requests.post("${e}", data=body, headers={
    "Content-Type": "application/json",
    "X-Anibros-Signature": f"t={t},v1={v1}",
    "X-Event-Id": "your-own-unique-id-1",
})
\`\`\``}function c(e,t,a){var r;const n=`# Sending ${a} leads to Anibros: ${e.name}

This is a private address that only accepts leads from you. Nothing is accepted without the proof described below.

**Address:** \`${e.url}\`
`,s=t?`**Signing secret:** \`${t}\`  (keep it on your server; never put it in a web page or an app)`:`**Signing secret:** ends in \`${e.secretHint??"????"}\`. Ask us for the full value (we can issue a new one at any time; the old one then stops working).`;switch(e.provider){case"generic":return[n,"## Easiest: chatbots and no-code tools (Tidio, Crisp, Tawk.to, Intercom, Zapier, Make, WPForms, Google Forms add-ons…)",'Any tool that can "send a webhook" or "call a URL" when someone leaves their details can send them here, no coding and no signing:',"",`- **URL:** \`${e.url}?key=${t??"YOUR_KEY"}\`  (or send the key as \`Authorization: Bearer <key>\` or the header \`X-Anibros-Key\` if the tool lets you set headers: that is the safer choice)`,"- **Method:** POST, **body:** JSON or form fields. Names are recognised however they are spelled (`name`, `your-name`, `visitor.email`, `Mobile No.` …).",'- Send what the person typed as `message` (any of `comments`, `enquiry`, `requirement` also work), and a chatbot conversation as `conversation: [{ "from": "visitor", "text": "…" }, { "from": "bot", "text": "…" }]`. Both appear as a note on the lead.',"- Treat the URL like a password: anyone who has it can add leads. Ask us to rotate it if it leaks.","","## For developers: signed requests","`POST` a JSON object to the address. Every request is signed so we know it is really yours.","",s,"","1. Take the exact raw body you are about to send.","2. Take the current time in whole seconds (`t`).",'3. Compute `HMAC-SHA256` of the text `t + "." + body` with the secret, as lowercase hex (`v1`).',"4. Send the header `X-Anibros-Signature: t=<t>,v1=<v1>`. Requests whose time is more than 5 minutes off are refused (so a stolen request cannot be replayed).","5. Send a header `X-Event-Id` with your own unique id for the lead. If you send the same id again (a retry), we process it once.","","## What we read",i,"","## Answers",o,"","## Examples",h(e.url,t??"YOUR_SECRET")].join(`
`);case"google":return[n,"## Connect Google Ads lead forms",s,"","1. Google Ads → **Assets** → your **Lead form** → **Lead delivery option** → **Webhook integration**.",`2. **Webhook URL:** \`${e.url}\``,"3. **Key:** the signing secret above.",'4. Press **Send test data**. A lead tagged "test" appears on our side within seconds.',"","Google puts the key inside each delivery; we compare it in constant time and refuse anything that does not match.","","## Answers",o].join(`
`);case"meta":return[n,"## Connect Facebook / Instagram Lead Ads","1. Meta for Developers → your app → **Webhooks** → choose **Page** → **Subscribe to this object**.",`2. **Callback URL:** \`${e.url}\``,`3. **Verify token:** \`${e.verifyToken??"(shown in the CRM)"}\``,"4. Subscribe the **leadgen** field, and subscribe your Facebook Page to the app (`POST /{page-id}/subscribed_apps?subscribed_fields=leadgen`).",`5. Page access token: ${e.hasAccessToken?"stored on our side.":"send us a long-lived Page access token with leads_retrieval, pages_show_list and pages_read_engagement, so we can read the answers people type."}`,"","Meta signs every delivery with your app secret; we verify that signature over the raw bytes and refuse anything unsigned or altered.","","## Answers",o].join(`
`);case"website":{const d=(r=e.config.allowedOrigins)!=null&&r.length?e.config.allowedOrigins.join(", "):"any site (ask us to restrict it to yours)";return[n,"## Put one line on your website",`Paste this just before \`</body>\` on every page that has a contact or enquiry form (in WordPress: a "header/footer scripts" plugin, in Wix/Webflow/Shopify: the custom code box). Only these sites may send leads: **${d}**.`,"","```html",`<script src="${e.url}/capture.js" async><\/script>`,"```","",'It finds every form on the page by itself, whatever the fields are called, and sends the answers (name, phone, e-mail, the message and anything else) when a visitor submits. It never changes how your form behaves, ignores login, payment and search forms, never reads passwords or card numbers, and drops bots. Add `data-anibros="ignore"` to a form you do not want sent.',"","### A chatbot of your own","If your chatbot is your own code, tell us what is said and hand over the lead once the visitor has left their details:","","```js","AnibrosCRM.say('visitor', 'I want a 3 BHK in Whitefield');   // call for every line of the chat","AnibrosCRM.say('bot', 'Great! What is your name and phone number?');","AnibrosCRM.lead({ name: 'Asha Verma', phone: '+919876543210', email: 'asha@example.com' });  // the chat is attached automatically","```","",'A chatbot from another company (Tidio, Crisp, Tawk.to, Intercom …) cannot run our script: use a "Chatbot or another tool" connection instead, which they can call as a webhook.',"","### Or write the form yourself (advanced)","","```html",'<form id="enquiry">','  <input name="name" placeholder="Your name" required>','  <input name="phone" type="tel" placeholder="Phone">','  <input name="email" type="email" placeholder="Email">','  <textarea name="message"></textarea>','  <input name="_hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px"> <!-- bot trap: leave empty -->',"  <button>Send</button>","</form>","<script>","document.getElementById('enquiry').addEventListener('submit', async (e) => {","  e.preventDefault();","  const body = Object.fromEntries(new FormData(e.target));",`  const r = await fetch('${e.url}', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });`,"  alert(r.ok ? 'Thank you, we will call you soon.' : 'Sorry, please try again.');","});","<\/script>","```","","## What we read",i,"","Submissions are limited per visitor, and anything filled into the hidden `_hp` field is treated as a bot and dropped.","","## Answers",o].join(`
`)}}}function l(e,t,a){return[`# API access for ${a}: ${e.name}`,"",`**Base address:** \`${t}/api/v1\``,`**API key:** \`${e.key}\`  (keep it on your server; anyone who has it can act as this key)`,`**Allowed actions:** ${e.scopes.length?e.scopes.join(", "):"none"}`,e.expiresAt?`**Expires:** ${new Date(e.expiresAt).toLocaleDateString()}`:"**Expires:** never (we can revoke it at any time)","","## Authentication","Send the key in the `Authorization` header of every request: `Authorization: Bearer <key>`. The key works only for this client and only for the actions listed above; anything else answers 403.","","## Send a lead","```bash",`curl -X POST '${t}/api/v1/leads' \\`,`  -H 'Authorization: Bearer ${e.key}' \\`,"  -H 'Content-Type: application/json' \\","  -H 'Idempotency-Key: your-own-unique-id-1' \\",`  -d '{"contact":{"firstName":"Asha","lastName":"Verma","phone":"+919876543210","email":"asha@example.com"},"source":"website","campaign":"Diwali offer","tags":["landing-page"]}'`,"```","","- `contact` needs a name and a phone number or e-mail. `source` is one of website, referral, portal, direct, meta_ads, google_ads, walk_in, other.","- Send an `Idempotency-Key` so a retry never creates a second lead.","- A person who already has an open lead answers **409** with the existing lead id: add to that lead instead of creating another.","","## Answers","| Answer | Meaning | What to do |","|---|---|---|","| 201 | Lead created | Save the returned `id`. |","| 400 | The body is not valid | Read the `errors` list in the answer and fix it. |","| 401 | Missing, wrong, revoked or expired key | Check the key. |","| 403 | The key may not do this | Ask us to widen its allowed actions. |","| 409 | Duplicate open lead | Use the lead id in the answer. |","| 429 / 5xx | Slow down / try again later | Retry after the `Retry-After` seconds, with the same `Idempotency-Key`. |","",`Full reference with every field: ${t}/api/docs`].join(`
`)}function m(e,t){const a=document.createElement("a");a.href=URL.createObjectURL(new Blob([t],{type:"text/markdown;charset=utf-8"})),a.download=e,document.body.appendChild(a),a.click(),a.remove(),setTimeout(()=>URL.revokeObjectURL(a.href),1e4)}function u(e,t,a){const n=t??"YOUR_SIGNING_SECRET";return`# Messages from ${a}: ${e.name}

We send a signed JSON message to **${e.url}** whenever one of these happens: ${e.events.join(", ")}.

${t?`Signing secret (keep it private, we cannot show it again): \`${t}\``:"Ask the person who set this up for the signing secret."}

## What arrives

A \`POST\` with a JSON body and these headers:

| Header | Meaning |
|---|---|
| \`X-Anibros-Event\` | What happened, e.g. \`lead.created\` |
| \`X-Anibros-Delivery\` | A unique id for this message. It is the SAME on every retry: use it to ignore duplicates. |
| \`X-Anibros-Signature\` | \`t=<unix seconds>,v1=<hex>\`, the proof it came from us |

\`\`\`json
{
  "id": "…delivery id…",
  "event": "lead.created",
  "occurredAt": "2026-10-15T09:30:00.000Z",
  "workspace": { "id": "…", "name": "${a}" },
  "change": {},
  "data": { "lead": { "id": "…", "name": "Meera Iyer", "phone": "+91…", "email": "…", "source": "meta_ads", "campaign": "…", "stage": "New enquiry", "status": "open", "ownerName": "Ravi Kumar", "tags": [], "customFields": {} } }
}
\`\`\`

## Check it is really us (always do this)

The signature is the hex HMAC-SHA256, keyed with the signing secret, of the text \`<t>.<raw request body>\`. Use the RAW body (before any JSON parsing), compare in constant time, and reject anything where \`t\` is more than 5 minutes old (that stops a recorded message being replayed).

### Node.js
\`\`\`js
import crypto from 'node:crypto';
export function verify(rawBody, header, secret) {
  const m = /^t=(\\d+),v1=([0-9a-f]{64})$/.exec(header ?? '');
  if (!m) return false;
  if (Math.abs(Date.now() / 1000 - Number(m[1])) > 300) return false;
  const expected = crypto.createHmac('sha256', secret).update(m[1] + '.' + rawBody).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(m[2]));
}
// verify(req.rawBody, req.headers['x-anibros-signature'], '${n}')
\`\`\`

### Python
\`\`\`python
import hmac, hashlib, re, time
def verify(raw_body: bytes, header: str, secret: str) -> bool:
    m = re.fullmatch(r"t=(\\d+),v1=([0-9a-f]{64})", header or "")
    if not m or abs(time.time() - int(m[1])) > 300:
        return False
    expected = hmac.new(secret.encode(), m[1].encode() + b"." + raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, m[2])
# verify(request.get_data(), request.headers.get("X-Anibros-Signature"), "${n}")
\`\`\`

### PHP
\`\`\`php
function verify(string $raw, string $header, string $secret): bool {
  if (!preg_match('/^t=(\\d+),v1=([0-9a-f]{64})$/', $header, $m)) return false;
  if (abs(time() - (int)$m[1]) > 300) return false;
  return hash_equals(hash_hmac('sha256', $m[1] . '.' . $raw, $secret), $m[2]);
}
\`\`\`

## What to answer

| Your answer | What we do |
|---|---|
| any 2xx | Delivered. |
| 410 | We switch the webhook off (you told us it is gone). |
| anything else, or no answer within 8 seconds | We retry with growing gaps for about a day, then mark it failed. After 30 failures in a row the webhook is switched off. |

Answer quickly (within a second or two) and do the real work afterwards. We never follow redirects: use the final address. Only public https addresses are accepted.
`}export{l as a,m as d,c as l,u as w};
//# sourceMappingURL=handoff-_dqh8J33.js.map
