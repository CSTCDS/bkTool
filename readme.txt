bkTool - Documentation

1) Objectif
- bkTool est une application PHP destinée à centraliser et afficher les comptes et opérations bancaires d'un utilisateur en récupérant les données via l'API Enable Banking.
- Le compte charles@sterchi.fr est ouvert chez enablebanking.com via l'application bkTool.

2) Architecture générale
- Langage : PHP (scripts indépendants, pas de composer requis).
- Emplacement clé : racine du projet (fichiers publics), code principal sous `mon-site/`.
- Principaux fichiers et rôles :
	- `mon-site/api/JwtHelper.php` : génération de JWT signés RS256 (clés RSA privées).
	- `mon-site/api/EnableBankingClient.php` : client central pour appeler l'API Enable Banking avec `Authorization: Bearer <JWT>`.
	- `choix.php` : page d'initiation du widget de sélection d'ASPSP et démarrage du flux d'authentification.
	- `choix_callback.php` : point de retour (redirect) après consentement, échange du `code` contre une `session` et stockage des comptes.
	- `sync.php` : routine de synchronisation qui, à partir d'une `session_id` stockée, récupère soldes et transactions et les enregistre en base.
	- `index.php` : interface d'administration / bouton "Synchroniser maintenant".

3) Intégration Enable Banking (flux et spécifications techniques)
- Authentification serveur → Enable Banking : JWT signé RS256. En-tête JWT `kid` = `enable_app_id` (UUID fourni par le panneau Enable Banking).
- Requêtes principales utilisées :
	- `POST /auth` : démarrer le flux d'auth (serveur) → renvoie URL d'authentification.
	- Redirection utilisateur vers le fournisseur (consentement).
	- Callback → `choix_callback.php` reçoit `code` et `state`.
	- `POST /sessions` (serveur) : échange `code` contre une `session` (session_id).
	- `GET /sessions/{id}` : lister comptes (UID) rattachés à la session.
	- `GET /accounts/{id}/balances` et `GET /accounts/{id}/transactions` : récupérer données financières.
- JWT : les claims usuels doivent être présents (`iss`, `aud`, `iat`, `exp`), signature RS256, durée maximale `exp - iat` ≤ 86400s. Le serveur génère un JWT par requête signée à l'aide de la clé privée RSA fournie.

4) Configuration requise
- Fichier `mon-site/config/database.php` contient les paramètres :
	- `enable_api_base` (https://api.enablebanking.com ou sandbox),
	- `enable_app_id` (UUID/kid),
	- `enable_private_key_path` (chemin absolu vers la clé privée RSA, hors webroot),
	- `enable_environment` (PRODUCTION|SANDBOX),
	- `enable_country` (code pays pour sélection ASPSP).
- Clé privée : doit être stockée hors webroot (ex : `secrets/private.key`) et ne doit jamais être committée dans Git.

5) Base de données (schéma simplifié)
- Table `accounts` : uid (clé API), nom, iban, type, dernier_solde, updated_at, etc. (utilisée en `REPLACE INTO accounts`).
- Table `transactions` : id (tx_id/UID), account_uid, date, montant, description, catégorie, inséré_date, etc. (utilise `INSERT IGNORE` pour éviter duplications).
- Table `settings` : stocke paires clé/valeur (notamment `eb_session_id`).

6) Processus de synchronisation
1. L'administrateur ouvre `choix.php`, sélectionne un ASPSP et lance le flux.
2. Le serveur appelle `POST /auth` et redirige l'utilisateur vers la banque pour consentement.
3. Après consentement, la banque redirige vers `choix_callback.php` avec `code` + `state`.
4. Le serveur échange le `code` via `POST /sessions` pour obtenir `session_id`, stocke `session_id` en `settings` et enregistre la liste des comptes retournés.
5. `sync.php` (manuel ou cron) lit `eb_session_id`, appelle `GET /sessions/{id}` pour lister les comptes puis pour chaque compte récupère `balances` et `transactions` et met à jour la base.

7) Déploiement et hébergement
- Hébergement : le site est hébergé chez LWS (serveur public accessible en HTTPS). LWS sert le site en production.
- Stockage intermédiaire : le dépôt `github.com` sert de stockage central / source-of-truth. Les modifications poussées sur GitHub sont transférées automatiquement vers LWS via le mécanisme de déploiement en place (script `push-github.ps1` / `deploy.ps1` ou pipeline CI). Le dépôt contient le code, les fichiers `readme.txt`, `privacy.txt`, `terms.txt` et les scripts de déploiement.
- Remarque de sécurité : ne poussez jamais de `private.key` dans GitHub. Si une clé privée existe dans le dépôt local, ajoutez-la à `.gitignore` et retirez-la de l'historique si nécessaire.

8) Sécurité et bonnes pratiques
- Toujours utiliser HTTPS pour les pages publiques et les callbacks.
- Restreindre l'accès aux fichiers de clés (permissions filesystem strictes).
- Dans le panneau Enable Banking, configurer :
	- Redirect URL : `https://<votre-domaine>/bkTool/choix_callback.php`
	- Allowed widget origins : `https://<votre-domaine>`
- Protéger les endpoints d'administration par authentification (non fournie par bkTool par défaut).

9) Dépannage rapide (erreurs fréquentes)
- "Authorization header is not provided" : vérifier que `enable_app_id` et `enable_private_key_path` sont configurés et que les requêtes contiennent `Authorization: Bearer <JWT>`.
- "JWT token type is not valid" : vérifier que le JWT est signé RS256 et que l'en-tête `kid` correspond à `enable_app_id`.
- Erreurs 422 "Wrong ASPSP name provided" : vérifier l'ASPSP envoyé au `POST /auth` et l'environnement (sandbox vs production).
- Erreurs TLS/CRL (schannel) : mise à jour/paramètres du client TLS sur l'hôte.

10) Commandes utiles
 - Tester la génération de JWT et l'appel `GET /application` : lancer un script PHP qui utilise `JwtHelper` puis `EnableBankingClient->request('GET','/application')`.
 - Déployer depuis le poste local vers LWS :
```powershell
git push origin main
.
# si vous utilisez le script fourni
powershell -ExecutionPolicy Bypass -File push-github.ps1
```

11) Contact et responsabilités
- Administrateur / contact technique : charles@sterchi.fr
- Le service bkTool fonctionne en se connectant au fournisseur Enable Banking ; l'utilisateur final reste responsable de ses identifiants bancaires et de l'autorisation accordée aux prestataires.

Fin de la documentation.
