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

    // 2. Créer les tables métier si elles n'existent pas (idempotent)
    $pdo->exec('CREATE TABLE IF NOT EXISTS accounts (
        id VARCHAR(191) PRIMARY KEY,
        name VARCHAR(255),
        balance DECIMAL(20,4) DEFAULT 0,
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
        // Version 1 : ajout colonne status sur transactions (si base existante avant ce système)
        1 => function (PDO $pdo) {
            // Vérifier si la colonne existe déjà
            $cols = $pdo->query('SHOW COLUMNS FROM transactions LIKE \'status\'')->fetchAll();
            if (empty($cols)) {
                $pdo->exec('ALTER TABLE transactions ADD COLUMN status VARCHAR(20) DEFAULT \'booked\' AFTER booking_date');
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
