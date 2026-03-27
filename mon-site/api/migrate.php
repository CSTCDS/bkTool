<?php
// migrate.php — Vérification / création des tables + migrations versionnées
// Appelé automatiquement après la connexion PDO dans db.php

function bkt_migrate(PDO $pdo): void
{
    // 1. Créer la table "base" si elle n'existe pas (stocke la version du schéma)
    $pdo->exec('CREATE TABLE IF NOT EXISTS base (
        id INT PRIMARY KEY DEFAULT 1,
        version INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )');

    // Initialiser la ligne unique si absente
    $row = $pdo->query('SELECT version FROM base WHERE id = 1')->fetch();
    if (!$row) {
        $pdo->exec('INSERT INTO base (id, version) VALUES (1, 0)');
        $currentVersion = 0;
    } else {
        $currentVersion = (int)$row['version'];
    }

    // Debug output: show current schema version so we know migrations run
    $debugMsg = 'bkt_migrate: currentVersion=' . $currentVersion;
    // Always log migration debug to error log only (avoid echoing into responses)
    error_log($debugMsg);

    // 2. Créer les tables métier si elles n'existent pas (idempotent)
    $pdo->exec('CREATE TABLE IF NOT EXISTS accounts (
        id VARCHAR(191) PRIMARY KEY,
        name VARCHAR(255),
        balance DECIMAL(20,4) DEFAULT 0,
        color VARCHAR(64) DEFAULT NULL,
        currency VARCHAR(8),
        raw JSON,
        updated_at TIMESTAMP NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS transactions (
        id VARCHAR(191) PRIMARY KEY,
        account_id VARCHAR(191),
        amount DECIMAL(20,4),
        currency VARCHAR(8),
        description TEXT,
        booking_date DATE,
        status VARCHAR(20) DEFAULT \'booked\',
        raw JSON,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (account_id)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(191) PRIMARY KEY,
        `value` TEXT
    )');

    // 3. Migrations versionnées — ajouter les nouvelles migrations ici
    //    Chaque migration incrémente la version de 1
    $migrations = [
        // Version 1 : ajout colonne status sur transactions
        1 => function (PDO $pdo) {
            $cols = $pdo->query('SHOW COLUMNS FROM transactions LIKE \'status\'')->fetchAll();
            if (empty($cols)) {
                $pdo->exec('ALTER TABLE transactions ADD COLUMN status VARCHAR(20) DEFAULT \'booked\' AFTER booking_date');
            }
        },

        // Version 2 : table categories (classement hiérarchique à 2 niveaux, 4 critères)
        2 => function (PDO $pdo) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                criterion TINYINT NOT NULL COMMENT \'1-4\',
                parent_id INT DEFAULT NULL,
                label VARCHAR(255) NOT NULL,
                sort_order INT DEFAULT 0,
                FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE,
                INDEX (criterion),
                INDEX (parent_id)
            )');
        },

        // Version 3 : ajout 4 colonnes critères sur transactions
        3 => function (PDO $pdo) {
            $cols = $pdo->query('SHOW COLUMNS FROM transactions LIKE \'cat1_id\'')->fetchAll();
            if (empty($cols)) {
                $pdo->exec('ALTER TABLE transactions
                    ADD COLUMN cat1_id INT DEFAULT NULL,
                    ADD COLUMN cat2_id INT DEFAULT NULL,
                    ADD COLUMN cat3_id INT DEFAULT NULL,
                    ADD COLUMN cat4_id INT DEFAULT NULL');
            }
        },

        // Version 4 : noms par défaut des 4 critères dans settings
        4 => function (PDO $pdo) {
            $defaults = [
                'criterion_1_name' => 'Périodicité',
                'criterion_2_name' => 'Fonction',
                'criterion_3_name' => 'Dossier',
                'criterion_4_name' => 'Critère 4',
            ];
            $stmt = $pdo->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (:k, :v)');
            foreach ($defaults as $k => $v) {
                $stmt->execute([':k' => $k, ':v' => $v]);
            }
        },
        // Version 5 : add color column to accounts for chart/UX
        5 => function (PDO $pdo) {
            $cols = $pdo->query('SHOW COLUMNS FROM accounts LIKE \'color\'')->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE accounts ADD COLUMN color VARCHAR(64) DEFAULT NULL AFTER balance");
            }
        },
        // Version 6 : add NumImport to transactions (import batch identifier)
        6 => function (PDO $pdo) {
            $cols = $pdo->query('SHOW COLUMNS FROM transactions LIKE \'NumImport\'')->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN NumImport INT DEFAULT 0 AFTER currency");
            }
        },
        // Version 7 : add alert_threshold to accounts for low-balance alerts
        7 => function (PDO $pdo) {
            $cols = $pdo->query('SHOW COLUMNS FROM accounts LIKE \'alert_threshold\'')->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE accounts ADD COLUMN alert_threshold DECIMAL(20,2) DEFAULT NULL AFTER balance");
            }
        },
        // Version 8 : add numero_affichage smallint to accounts for display ordering
        8 => function (PDO $pdo) {
            $cols = $pdo->query('SHOW COLUMNS FROM accounts LIKE \'numero_affichage\'')->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE accounts ADD COLUMN numero_affichage SMALLINT DEFAULT NULL AFTER alert_threshold");
            }
        },
        // Version 9 : add auto_category_rules and transaction_changes_log tables
        9 => function (PDO $pdo) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS auto_category_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pattern TEXT NOT NULL,
                is_regex TINYINT(1) NOT NULL DEFAULT 0,
                category_level TINYINT DEFAULT NULL,
                scope_account_id VARCHAR(191) DEFAULT NULL,
                priority INT NOT NULL DEFAULT 100,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (priority)
            )');

            $pdo->exec('CREATE TABLE IF NOT EXISTS transaction_changes_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tx_id VARCHAR(191) NOT NULL,
                old_category_id INT DEFAULT NULL,
                new_category_id INT DEFAULT NULL,
                rule_id INT DEFAULT NULL,
                user_id VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (tx_id),
                INDEX (rule_id)
            )');
        },
        // Version 10 : ajouter champs valeur_a_affecter (int) et category_level (tinyint)
        10 => function (PDO $pdo) {
            // ensure auto_category_rules exists (v9 may not have been applied on older installs)
            $pdo->exec('CREATE TABLE IF NOT EXISTS auto_category_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pattern TEXT NOT NULL,
                is_regex TINYINT(1) NOT NULL DEFAULT 0,
                category_level TINYINT DEFAULT NULL,
                scope_account_id VARCHAR(191) DEFAULT NULL,
                priority INT NOT NULL DEFAULT 100,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (priority)
            )');

            // add colonne valeur_a_affecter si absente
            $cols = $pdo->query('SHOW COLUMNS FROM auto_category_rules LIKE "valeur_a_affecter"')->fetchAll();
            if (empty($cols)) {
                $pdo->exec('ALTER TABLE auto_category_rules ADD COLUMN valeur_a_affecter INT DEFAULT NULL');
            }
            // add colonne category_level si absente
            $cols2 = $pdo->query('SHOW COLUMNS FROM auto_category_rules LIKE "category_level"')->fetchAll();
            if (empty($cols2)) {
                $pdo->exec('ALTER TABLE auto_category_rules ADD COLUMN category_level TINYINT DEFAULT NULL');
            }
        },
        // Version 11 : backfill category_level using categories.criterion for existing rules
        11 => function (PDO $pdo) {
            // ensure the table and column exist
            try {
                $pdo->exec('CREATE TABLE IF NOT EXISTS auto_category_rules (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    pattern TEXT NOT NULL,
                    is_regex TINYINT(1) NOT NULL DEFAULT 0,
                    category_level TINYINT DEFAULT NULL,
                    scope_account_id VARCHAR(191) DEFAULT NULL,
                    priority INT NOT NULL DEFAULT 100,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    created_by VARCHAR(100) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )');
            } catch (Throwable $e) { /* ignore */ }

            $cols = $pdo->query('SHOW COLUMNS FROM auto_category_rules LIKE "category_level"')->fetchAll();
            if (empty($cols)) {
                try {
                        $pdo->exec('ALTER TABLE auto_category_rules ADD COLUMN category_level TINYINT DEFAULT NULL');
                } catch (Throwable $e) { /* ignore if not possible */ }
            }

            // backfill: set category_level = categories.criterion where missing
            try {
                $pdo->exec('UPDATE auto_category_rules ar JOIN categories c ON ar.category_id = c.id SET ar.category_level = c.criterion WHERE (ar.category_level IS NULL OR ar.category_level = 0) AND ar.category_id IS NOT NULL');
            } catch (Throwable $e) { /* ignore errors during backfill */ }
        },
        // Version 12 : make scope_account_id a VARCHAR(191) to allow non-numeric scopes
        12 => function (PDO $pdo) {
            $cols = $pdo->query('SHOW COLUMNS FROM auto_category_rules LIKE "scope_account_id"')->fetchAll();
            if (empty($cols)) {
                // add as varchar if column missing
                $pdo->exec('ALTER TABLE auto_category_rules ADD COLUMN scope_account_id VARCHAR(191) DEFAULT NULL AFTER category_id');
            } else {
                // ensure type is varchar(191)
                try {
                    $pdo->exec('ALTER TABLE auto_category_rules MODIFY scope_account_id VARCHAR(191) DEFAULT NULL');
                } catch (Throwable $e) { /* ignore if cannot modify */ }
            }
        },
        // Version 13 : drop duplicated column category_id from auto_category_rules
        13 => function (PDO $pdo) {
            // Version 13: drop duplicated column category_id from auto_category_rules
            $cols = $pdo->query('SHOW COLUMNS FROM auto_category_rules LIKE "category_id"')->fetchAll();
            if (!empty($cols)) {
                // If the column exists, drop it (no try/catch so errors surface)
                $pdo->exec('ALTER TABLE auto_category_rules DROP COLUMN category_id');
            }
        },
        // Version 14 : add account_type to accounts (card / current)
        14 => function (PDO $pdo) {
            $cols = $pdo->query('SHOW COLUMNS FROM accounts LIKE "account_type"')->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE accounts ADD COLUMN account_type VARCHAR(20) DEFAULT NULL AFTER raw");
            }
        },
        // Version 15 : add accounting_date to transactions (date when transaction is accounted/collected)
        15 => function (PDO $pdo) {
            $cols = $pdo->query('SHOW COLUMNS FROM transactions LIKE "accounting_date"')->fetchAll();
            if (empty($cols)) {
                try {
                    $pdo->exec("ALTER TABLE transactions ADD COLUMN accounting_date DATE DEFAULT NULL AFTER booking_date");
                } catch (Throwable $e) {
                    // ignore if cannot add
                }
            }
        },
        // Version 16 : add NextNumber integer to base for manual transaction ids
        16 => function (PDO $pdo) {
            $cols = $pdo->query('SHOW COLUMNS FROM base LIKE "NextNumber"')->fetchAll();
            if (empty($cols)) {
                try {
                    $pdo->exec('ALTER TABLE base ADD COLUMN NextNumber INT DEFAULT 0 AFTER version');
                    // initialize NextNumber to 0 if null
                    $pdo->exec('UPDATE base SET NextNumber = COALESCE(NextNumber, 0) WHERE id = 1');
                } catch (Throwable $e) {
                    // ignore if cannot add
                }
            }
        },
        // Version 17 : create log table for cron and error tracing
        17 => function (PDO $pdo) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                log_date DATE NOT NULL,
                log_time TIME NOT NULL,
                code_programme VARCHAR(50) NOT NULL,
                libelle VARCHAR(200) DEFAULT NULL,
                payload TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (code_programme),
                INDEX (log_date)
            )');
        },
        // Version 18 : add reference_date to accounts (date used to compare card transactions)
        18 => function (PDO $pdo) {
            $cols = $pdo->query("SHOW COLUMNS FROM accounts LIKE 'reference_date'")->fetchAll();
            if (empty($cols)) {
                try {
                    $pdo->exec("ALTER TABLE accounts ADD COLUMN reference_date DATE DEFAULT NULL AFTER updated_at");
                } catch (Throwable $e) { /* ignore if cannot add */ }
            }
        },
    ];

    // Exécuter les migrations non encore appliquées
    foreach ($migrations as $targetVersion => $migrationFn) {
        if ($currentVersion < $targetVersion) {
            $migrationFn($pdo);
            $pdo->exec('UPDATE base SET version = ' . (int)$targetVersion . ' WHERE id = 1');
            $currentVersion = $targetVersion;
        }
    }
}
