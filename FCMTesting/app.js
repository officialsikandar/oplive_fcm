const form = document.getElementById('form');
const result = document.getElementById('result');
const sendBtn = document.getElementById('send-btn');

document.getElementById('json-file').addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (file) document.getElementById('json').value = await file.text();
});

function parseData(text) {
  const data = {};
  text.split('\n').forEach((line) => {
    const i = line.indexOf('=');
    if (i > 0) data[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  });
  return data;
}

function showResult(ok, msg) {
  result.classList.remove('hidden', 'ok', 'err');
  result.classList.add(ok ? 'ok' : 'err');
  result.textContent = msg;
}

function cleanToken(raw) {
  return raw.replace(/\s+/g, '').trim();
}

function validateToken(token) {
  if (token.length < 100) {
    return 'Token looks too short. Copy the full FCM token from your app.';
  }
  if (!token.includes(':')) {
    return 'Token format looks wrong. It should contain a colon, e.g. abc123:APA91b...';
  }
  if (!token.includes('APA91')) {
    return 'Token may not be a valid FCM token. It should contain APA91...';
  }
  return '';
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  sendBtn.disabled = true;
  sendBtn.textContent = 'Sending...';
  result.classList.add('hidden');

  const token = cleanToken(document.getElementById('token').value);
  const tokenError = validateToken(token);
  if (tokenError) {
    showResult(false, tokenError);
    sendBtn.disabled = false;
    sendBtn.textContent = 'Send Notification';
    return;
  }

  const data = parseData(document.getElementById('data').value);
  const payload = {
    serviceAccountJson: document.getElementById('json').value.trim(),
    token,
    title: document.getElementById('title').value,
    body: document.getElementById('body').value,
    image: document.getElementById('image').value,
    data,
  };

  try {
    const res = await fetch(window.FCM_API || 'send.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const text = await res.text();
    let json;
    try {
      json = JSON.parse(text);
    } catch {
      showResult(false, 'Server error:\n' + text.slice(0, 500));
      return;
    }

    if (json.success) {
      showResult(true, 'Sent!\nMessage ID: ' + (json.messageId || 'OK'));
    } else {
      let msg = 'Error: ' + (json.error || 'Unknown error');
      if (json.error && json.error.includes('not a valid FCM registration token')) {
        msg += '\n\nYour sender works. The device token is wrong.\n'
          + '- Get a NEW token from the app (log FirebaseMessaging.getToken())\n'
          + '- Token must be from project: equigenix-app\n'
          + '- Reinstalling the app generates a new token\n'
          + '- Token length is usually 140+ characters';
      }
      if (json.step) msg += '\nStep: ' + json.step;
      if (json.http_code) msg += '\nHTTP: ' + json.http_code;
      if (json.details) msg += '\n\n' + JSON.stringify(json.details, null, 2);
      showResult(false, msg);
    }
  } catch (err) {
    showResult(false, 'Error: ' + err.message);
  } finally {
    sendBtn.disabled = false;
    sendBtn.textContent = 'Send Notification';
  }
});
