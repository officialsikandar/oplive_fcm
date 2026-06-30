# Deploy backend on Render (free) because Freehostia blocks Google API

## Why
Freehostia free plan returns **HTTP 0** — it blocks outbound HTTPS to `googleapis.com`.
Your form on oplive.in can stay there; only `send.php` needs external hosting.

## Steps

1. Create free account at https://render.com
2. New → **Web Service** → connect GitHub or upload this folder
3. Set:
   - **Environment**: Docker
   - **Root directory**: `render` (or copy Dockerfile + send.php into repo root)
4. Deploy → you get a URL like `https://fcm-sender-xxxx.onrender.com`

5. On oplive.in, edit `config.js`:
```js
window.FCM_API = 'https://fcm-sender-xxxx.onrender.com/send.php';
```

6. Upload updated `config.js` to https://oplive.in/fcm/

Done — form on oplive.in, API on Render.
