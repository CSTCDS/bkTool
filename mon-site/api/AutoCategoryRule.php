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
        $stmt = $this->pdo->query('SELECT id, pattern, is_regex, category_id, scope_account_id, priority, active FROM auto_category_rules WHERE active = 1 ORDER BY priority ASC, created_at ASC');
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

            $pattern = $r['pattern'];
            if ($r['is_regex']) {
                // safe preg match
                try {
                    if (@preg_match($pattern, $description) === 1) {
                        return $r;
                    }
                } catch (Throwable $e) {
                    // invalid regex, skip
                    continue;
                }
            } else {
                if (stripos($description, $pattern) !== false) {
                    return $r;
                }
            }
        }
        return null;
    }
}
