<?php
/**
 * Shared security/SEO headers, required by every PHP entry point that
 * produces a response (the SPA shell at /index.php and every API endpoint
 * via /api/helpers.php). Pulling them into one file keeps the policy in a
 * single source of truth and avoids drift between routes.
 *
 * Idempotent — repeated require_once is a no-op, and the header() calls
 * here themselves overwrite same-named earlier headers so the order of
 * inclusion vs. other code doesn't matter.
 */

// Tell well-behaved crawlers to skip the whole site. Belt-and-braces with
// robots.txt + a <meta name="robots"> tag in index.php.
header('X-Robots-Tag: noindex, nofollow, noarchive');

// Block MIME sniffing — keeps misnamed content from being executed as
// something else (e.g. an "image" interpreted as JS by an old browser).
header('X-Content-Type-Options: nosniff');

// Clickjacking defense. CSP frame-ancestors below covers modern browsers,
// X-Frame-Options is kept for legacy.
header('X-Frame-Options: SAMEORIGIN');

// Don't leak the Referer to any other origin.
header('Referrer-Policy: no-referrer');

// Disable browser features the app doesn't use. Reduces blast radius if
// markup is ever injected.
header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=(), interest-cohort=()');

// Content Security Policy.
//  - script-src 'self' is strict: no inline <script>, no inline onclick=
//    attributes. h() in js/app.js attaches handlers via addEventListener.
//  - style-src needs 'unsafe-inline' because h() applies styles via
//    Object.assign(el.style, …) (DOM-API inline style), and a few small
//    style="…" attributes are used inline in app.js.
//  - frame-ancestors 'none' is the modern X-Frame-Options replacement.
//  - object-src 'none' kills <embed>/<object>/<applet> entirely.
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self'; "
    . "style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'; "
    . "object-src 'none'");

// HSTS only over HTTPS — keeps local HTTP dev usable. 1 year, includeSubDomains.
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
