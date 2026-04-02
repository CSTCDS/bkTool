<?php
// Simple DAO for auto_category_rules
class AutoCategoryRule
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Return active rules ordered by priority asc, created_at asc
     * @return array<int, array>
     */
    public function fetchActiveRules(): array
    {
        $stmt = $this->pdo->query('SELECT id, pattern, is_regex, category_level, scope_account_id, priority, active FROM auto_category_rules WHERE active = 1 ORDER BY priority ASC, created_at ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Find matching rule for given description and account_id
     */
    public function findMatchingRule(string $description, ?string $accountId = null): ?array
    {
        $rules = $this->fetchActiveRules();
        foreach ($rules as $r) {
            // respect scope
            if (!empty($r['scope_account_id']) && $accountId !== null && (string)$r['scope_account_id'] !== (string)$accountId) {
                continue;
            }
            $pattern = trim((string)($r['pattern'] ?? ''));
            if ($pattern === '') continue;

            if (!empty($r['is_regex'])) {
                // safe preg match (rule.pattern expected to be a valid regex)
                try {
                    if (@preg_match($pattern, $description) === 1) {
                        return $r;
                    }
                } catch (Throwable $e) {
                    // invalid regex, skip
                    continue;
                }
            } else {
                // support SQL LIKE-style patterns when user included % or _
                if (strpos($pattern, '%') !== false || strpos($pattern, '_') !== false) {
                    // convert SQL LIKE to a safe regex:
                    // 1) split on wildcards, escape each literal part, then rejoin with regex equivalents
                    $parts = preg_split('/(%|_)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
                    $regexBody = '';
                    foreach ($parts as $part) {
                        if ($part === '%') { $regexBody .= '.*'; }
                        elseif ($part === '_') { $regexBody .= '.'; }
                        else { $regexBody .= preg_quote($part, '/'); }
                    }
                    $regex = '/^' . $regexBody . '$/i';
                    try {
                        if (@preg_match($regex, $description) === 1) {
                            return $r;
                        }
                    } catch (Throwable $e) {
                        continue;
                    }
                } else {
                    // simple substring match (case-insensitive)
                    if (stripos($description, $pattern) !== false) {
                        return $r;
                    }
                }
            }
        }
        return null;
    }
}
