<?php

defined('ABSPATH') || exit;


function lab_async_setup_db() {
    global $wpdb;

    $table = $wpdb->prefix . 'lab_async_queue';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        task VARCHAR(191) NOT NULL,
        payload LONGTEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        available_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY status (status),
        KEY task (task)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}