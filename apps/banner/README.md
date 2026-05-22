# @effect-cmp/banner

The embeddable consent banner script. Compiled to a tiny IIFE loader stub plus a hashed main bundle, both served from R2 behind `assets.effectcmp.com`.

## How clients embed it

```html
<script src="https://assets.effectcmp.com/loader.js"
        data-domain-id="<public-key>"
        async></script>
```

## Build outputs

- `dist/loader.js` — stable URL, fetches config + dynamically imports the hashed bundle
- `dist/cmp.[hash].js` — immutable, cached forever (`Cache-Control: public, max-age=31536000, immutable`)

## Hard requirements (must not regress)

- WCAG AA: focus trap, screen-reader copy, refusal as easy as acceptance
- p95 banner-paint < 50ms (RUM beacon baked in)
- Never throws uncaught — entry wrapped in try/catch
- CSP nonce support
- HMAC-verified config payload from KV
