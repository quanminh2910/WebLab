<?php

// The catalog, account pages and AJAX endpoints use the same browser policy.
if (PHP_SAPI !== 'cli') {
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js; "
        . "style-src 'self' https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css; "
        . "img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
}
