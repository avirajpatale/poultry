<?php

declare(strict_types=1);

/** Enforces auth + permission for a matched route. */
class AuthMiddleware
{
    /**
     * @param string|null $permission permission code required, or
     *                                'public' for open routes, null = any logged-in user
     */
    public static function handle(?string $permission): void
    {
        if ($permission === 'public') {
            return;
        }
        Auth::check();
        if ($permission !== null && !Auth::can($permission)) {
            throw new ApiException('Forbidden: missing permission ' . $permission, 403);
        }
    }
}
