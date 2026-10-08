<?php

namespace DcyphrDigital\Helpers\Support;

trait SanitizesEmail
{
    /**
     * The email as it is stored and matched: without the invisible characters around it, and in lower case.
     *
     * Removed around it: spaces, tabs, newlines, non-breaking spaces, zero-width spaces and joiners, word joiners and
     * byte order marks, as they come with pasted or imported emails. MySQL ignores trailing spaces when it compares
     * strings, but not the others, so an email saved with them is not found again by an exact match; and as they are
     * invisible, such an email looks right everywhere it is shown.
     *
     * In lower case, so two spellings of one email are one email, also where it is compared outside MySQL (e.g. as an
     * array key). Characters inside the email are kept. Null stays null; an email of only whitespace becomes ''.
     */
    protected function sanitizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $trimmed = preg_replace('/^[\s\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+|[\s\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+$/u', '', $email)
            ?? trim($email);

        return mb_strtolower($trimmed);
    }
}
