<?php
require_once __DIR__ . '/config.php';

if (strtolower(PREFERRED_DATABASE_TYPE) !== 'sqlite' || !class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    require_once __DIR__ . '/json_db.php';
} else {
    require_once __DIR__ . '/sqlite_db.php';
}
