<?php
/**
 * Email addresses on Slate accounts.
 *
 * The Cage is why the column exists: an address is how a pickup notice, a
 * due-tomorrow warning or an overdue chase reaches anyone, and a username
 * cannot receive one. Signing in to The Cage with an account that has no
 * address is refused rather than guessed at.
 *
 * Collecting it at signup is what stops that being a chore — otherwise every
 * new person needs an admin to run an UPDATE before they can use the gear
 * room, and nobody remembers.
 *
 * ------------------------------------------------------ what this is NOT
 *
 * This does not verify the address. Nothing is sent to it, so what lands in
 * the database is whatever was typed. That is a deliberate limit rather than
 * an oversight: mail to @life.church is held in quarantine on the way in, so
 * a verification link would simply never arrive and would lock people out of
 * signing up at all.
 *
 * The consequence worth understanding: someone could type a colleague's
 * address and receive their gear notifications. The invite code is what keeps
 * that small, by limiting who reaches this page at all. **Do not remove the
 * invite code while the address is unverified** — together they are
 * reasonable; neither is on its own.
 *
 * When mail to @life.church is deliverable, swap this for a verification link
 * and the invite code can go.
 *
 * The column and its unique index are created by fm_migrate_columns() in
 * db.php, which runs on the first request after a deploy.
 */

declare(strict_types=1);

/** Domains an address may be on. Empty means any, matching emailAllowed()
    in The Cage — one rule, stated in two places because the two apps can't
    share code. */
function fm_email_domains(): array
{
    $raw = (string) (fm_config()['allowed_email_domains'] ?? 'life.church');
    return array_values(array_filter(array_map(
        static fn($d) => strtolower(trim($d, " \t@")),
        explode(',', $raw)
    )));
}

/**
 * Is this an address we'll accept?
 *
 * @return string '' when fine, otherwise the reason, ready to show.
 */
function fm_email_problem(string $email): string
{
    $email = trim($email);
    if ($email === '') {
        return 'Enter your email address.';
    }
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'That does not look like an email address.';
    }

    $domains = fm_email_domains();
    if (!$domains) {
        return '';
    }
    $lower = strtolower($email);
    foreach ($domains as $d) {
        if (str_ends_with($lower, '@' . $d)) {
            return '';
        }
    }
    return 'Use your ' . implode(' or ', array_map(static fn($d) => '@' . $d, $domains)) . ' address.';
}

/**
 * Is this address already on someone else's account?
 *
 * Asked before creating an account rather than after, so a duplicate fails
 * cleanly instead of leaving a half-made account with no way to reach its
 * owner. The unique index is still what actually decides — two people
 * submitting the same address at the same instant is settled there, not here.
 *
 * @param string $exceptUsername Ignore this account's own row, for the case
 *   where someone is editing their own address rather than signing up.
 */
function fm_email_taken(string $email, string $exceptUsername = ''): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }
    $stmt = fm_db()->prepare(
        'SELECT 1 FROM users WHERE lower(email) = ? AND username_ci <> ? LIMIT 1'
    );
    $stmt->execute([$email, strtolower(trim($exceptUsername))]);
    return (bool) $stmt->fetch();
}

/**
 * Store an address against an account.
 *
 * Lowercased, because the unique index is on lower(email) and The Cage
 * lowercases before matching — Jordan.West@ and jordan.west@ are one mailbox.
 *
 * @return string '' on success, otherwise the reason.
 */
function fm_set_email(string $username, string $email): string
{
    $problem = fm_email_problem($email);
    if ($problem !== '') {
        return $problem;
    }

    try {
        $stmt = fm_db()->prepare('UPDATE users SET email = ? WHERE username_ci = ?');
        $stmt->execute([strtolower(trim($email)), strtolower(trim($username))]);
    } catch (PDOException $e) {
        // 23505 = unique_violation: somebody already has this address. Worth
        // its own message — "already in use" is actionable, a 500 is not.
        if ($e->getCode() === '23505') {
            return 'That email address is already on another account.';
        }
        throw $e;
    }
    return '';
}
