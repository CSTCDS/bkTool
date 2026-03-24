## Spécification — Règles d'affectation automatique de catégories

Objectif
- Permettre depuis l'écran `transactions.php` de proposer et d'appliquer automatiquement une catégorie à une opération en fonction de son libellé.

Principes
- Les règles sont évaluées côté serveur.
- Chaque règle contient un `pattern` (chaîne ou regex), un flag `is_regex`, une `category_id`, un scope optionnel `scope_account_id` (null = global), et une `priority` (plus faible = priorité haute).
- Matching : on trie les règles actives par `priority` asc puis par `created_at` asc ; première règle qui match s'applique.
- Matching non-regex : recherche `stripos(pattern, libelle) !== false` (case-insensitive substring).

UX (transactions.php)
- Pour chaque ligne : afficher une suggestion de catégorie (icône + nom) à droite de la description si une règle correspond.
- Proposer 3 actions : `Appliquer` (change catégorie), `Ignorer` (ne plus proposer pour cette transaction), `Créer règle` (ouvre modal prérempli).
- Modal "Créer règle" : prérempli avec le libellé (option pour convertir en regex), scope (compte courant / global), priorité, bouton `Créer et appliquer`.
- Bouton global "Appliquer la règle à l'historique" dans le modal avec aperçu (nombre de transactions affectées) et confirmation.

Sécurité & réversibilité
- Toutes les modifications de catégorie sont journalisées dans `transaction_changes_log` (tx_id, old_category_id, new_category_id, rule_id, user_id, created_at).
- UI propose un bouton `Annuler` pour l'opération la plus récente ou une action de rollback par lot via l'historique.

API proposée
- `POST /api/auto_category_rules` — créer règle
- `GET /api/auto_category_rules` — lister règles
- `PUT /api/auto_category_rules/:id` — modifier règle
- `DELETE /api/auto_category_rules/:id` — supprimer règle
- `GET /api/suggest_category?tx_id=NN` — suggestion pour une transaction
- `POST /api/apply_rule` — appliquer une règle à une transaction ou en batch

Tests
- Unit tests pour le moteur de matching : cas regex, substring, priority, scope.
- Tests d'intégration pour endpoints CRUD et `/suggest_category`.

Monitoring
- Dashboard d'erreurs/faux-positifs (nombre de refus après suggestion) pour affiner règles.

Notes d'implémentation
- Éviter exécution regex non-sécurisée sur des patterns contrôlés par l'utilisateur (limiter longueur, utiliser try/catch).
- Prévoir index sur `pattern` si matching substring utilisé via fulltext ou optimisation ultérieure.

Fin.
