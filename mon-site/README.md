# bkTool — Intégration Enable Banking (PHP / MySQL)

Petite application de démonstration pour synchroniser comptes et transactions via Enable Banking (sandbox).

Prérequis
- PHP 8.2 avec cURL
- MySQL 5.7+
- Hébergement HTTPS (LWS recommandé)

Structure
- mon-site/
  - config/ (non-public)
  - api/
  - cron/
  - public/
  - assets/
  - sql/

Secrets
- Les clés et mots de passe ne sont PAS dans le repo.
- Placer vos secrets dans `C:\perso\LWS\secrets\BKT.php` (retournant un tableau associatif).

Installation
1. Importer le schéma SQL: `mon-site/sql/schema.sql`
2. Configurer `C:\perso\LWS\secrets\BKT.php`
3. Déployer le dossier `mon-site/public` comme racine web
4. Lancer le cron `php ../cron/sync.php` (ou configurer une tâche planifiée)
