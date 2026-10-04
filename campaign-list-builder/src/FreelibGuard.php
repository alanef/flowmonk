<?php
/**
 * Freelib Guard
 *
 * Screens unauthenticated freelib (free_plugin_lib) opt-in submissions before a
 * subscriber is created. Freelib products have no HMAC secret and their plugin IDs
 * are public, so anyone can POST an arbitrary address. Every address accepted here
 * receives a double opt-in email plus dunning reminders, so this is the main
 * defence against list-bombing and spam complaints.
 *
 * Callers must give the same response for every outcome so a scripter learns nothing.
 */

class FreelibGuard
{
    // Max submissions (any outcome) from one source IP per 24 hours, across all freelib products.
    // Opt-ins arrive from the WordPress server's IP, so shared hosts share a budget; real
    // opt-ins are a handful per day in total, so collisions are unlikely.
    public const IP_DAILY_LIMIT = 5;

    // One accepted submission per email per product in this window; repeats are a no-op.
    public const EMAIL_WINDOW_DAYS = 30;

    // Local parts that are never a person's own inbox, or are WordPress/host defaults
    // rather than a chosen address. Deliberately excludes info@, contact@, hello@,
    // sales@, support@ and office@: on one-person sites those are often the owner's
    // only address.
    public const ROLE_LOCAL_PARTS = [
        'abuse', 'admin', 'administrator', 'bounce', 'bounces', 'devnull', 'donotreply',
        'do-not-reply', 'do_not_reply', 'hostmaster', 'mailer-daemon', 'mailerdaemon',
        'no-reply', 'no_reply', 'noreply', 'nobody', 'null', 'postmaster', 'root',
        'spam', 'webmaster', 'www', 'www-data',
    ];

    private SequenceDatabase $db;
    private array $disposableDomains;
    /** @var callable(string): ?array */
    private $mxLookup;

    /**
     * @param callable|null $mxLookup fn(string $domain): ?array — MX targets, [] for none,
     *                                null when DNS failed (treated as pass)
     */
    public function __construct(SequenceDatabase $db, ?array $disposableDomains = null, ?callable $mxLookup = null)
    {
        $this->db = $db;
        $this->disposableDomains = $disposableDomains ?? self::loadDisposableDomains();
        $this->mxLookup = $mxLookup ?? [self::class, 'lookupMx'];
    }

    /**
     * Decide whether a submission may proceed, and record it.
     *
     * @return string 'accepted' or the rejection reason
     */
    public function check(string $productId, string $email, string $ip, string $host): string
    {
        $email = strtolower(trim($email));
        $emailHash = hash('sha256', self::rateLimitKey($email));
        $ipHash = hash('sha256', $ip);

        $outcome = $this->evaluate($productId, $email, $emailHash, $ipHash);
        $this->db->recordFreelibSubmission($productId, $emailHash, $ipHash, $host, $outcome);

        return $outcome;
    }

    private function evaluate(string $productId, string $email, string $emailHash, string $ipHash): string
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));

        $ipSince = (clone $now)->modify('-1 day')->format('Y-m-d\TH:i:s\Z');
        if ($this->db->countFreelibSubmissionsByIp($ipHash, $ipSince) >= self::IP_DAILY_LIMIT) {
            return 'ip_limited';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            return 'invalid';
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $baseLocal = explode('+', $localPart, 2)[0];

        if (in_array($baseLocal, self::ROLE_LOCAL_PARTS, true)) {
            return 'role_address';
        }

        if ($this->isDisposable($domain)) {
            return 'disposable';
        }

        $emailSince = (clone $now)->modify('-' . self::EMAIL_WINDOW_DAYS . ' days')->format('Y-m-d\TH:i:s\Z');
        if ($this->db->hasAcceptedFreelibSubmission($productId, $emailHash, $emailSince)) {
            return 'duplicate';
        }

        // DNS last: it is the only slow check
        $mx = ($this->mxLookup)($domain);
        if ($mx !== null && (empty($mx) || $mx === ['.'] || $mx === [''])) {
            return 'no_mx';
        }

        return 'accepted';
    }

    /**
     * Key for the per-email limit: drops +tags everywhere and dots for Gmail, so
     * one inbox can't be targeted repeatedly through aliases.
     */
    public static function rateLimitKey(string $email): string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local, 2)[0];
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }
        return "$local@$domain";
    }

    /**
     * Matches the domain or any parent domain against the disposable list
     */
    private function isDisposable(string $domain): bool
    {
        $parts = explode('.', $domain);
        while (count($parts) >= 2) {
            if (isset($this->disposableDomains[implode('.', $parts)])) {
                return true;
            }
            array_shift($parts);
        }
        return false;
    }

    private static function loadDisposableDomains(): array
    {
        $file = __DIR__ . '/disposable-domains.txt';
        if (!is_readable($file)) {
            error_log("[FreelibGuard] Disposable domain list missing: $file");
            return [];
        }
        $domains = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && $line[0] !== '#') {
                $domains[$line] = true;
            }
        }
        return $domains;
    }

    /**
     * @return array|null MX targets ([] when the domain has none), or null on DNS failure
     */
    public static function lookupMx(string $domain): ?array
    {
        $records = @dns_get_record($domain . '.', DNS_MX);
        if ($records === false) {
            return null;
        }
        return array_map(fn($r) => rtrim($r['target'] ?? '', '.') ?: '.', $records);
    }
}
