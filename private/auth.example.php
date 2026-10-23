<?php
declare(strict_types=1);
/**
 * Replace with an adapter to YOUR server-side authentication/authorization.
 * Called on connect AND callback, after the secure session is started.
 * MUST check an active login and the current user's permission to manage the
 * selected company using authoritative server-side membership records.
 * Never trust a company_id/principal/role supplied by GET, POST, headers or cookies.
 * Provision companies.id from your trusted back office, never from OAuth input.
 * If your app uses another session name/storage, integrate it in bootstrap.php.
 * Return null when unauthenticated or unauthorized; do not redirect here.
 * Success contract: ['principal_id' => 'stable-user-id', 'company_id' => 123].
 * IDs come from your existing system; the adapter is NOT a demo login.
 */
return static function (): ?array {
    return null; // Fail closed until the existing Digital4All login is integrated.
};
