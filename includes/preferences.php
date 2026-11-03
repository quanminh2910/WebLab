<?php

// This preference is cosmetic only. It must never be used to authorize a user.
function themeAttribute(): string
{
    $theme = $_COOKIE['spa_theme'] ?? null;
    if (!is_string($theme) || !in_array($theme, ['dark', 'light'], true)) {
        return '';
    }

    return ' data-theme="' . $theme . '"';
}
