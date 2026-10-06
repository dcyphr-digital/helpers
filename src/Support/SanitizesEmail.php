<?php

namespace DcyphrDigital\Helpers\Support;

trait SanitizesEmail
{
    /**
     * The email without the whitespace around it: spaces, tabs, newlines, non-breaking spaces and byte order marks,
     * as they come with pasted or imported emails. MySQL ignores trailing spaces when it compares strings, but not
     * the others, so an email saved with them is not found again by an exact match.
     *
     * The letter case is kept, as MySQL compares emails without it. Null stays null; an email of only whitespace
     * becomes ''.
     */
    protected function sanitizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        return preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $email) ?? trim($email);
    }
}
