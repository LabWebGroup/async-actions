<?php
/**
 * Plugin Name: Async Actions
 * Plugin URI: https://labweb.digital/
 * Description: Lightweight background job queue and async task dispatcher for WordPress.
 * Version: 1.0.8
 * Author: Labweb
 * Author URI: https://labweb.digital/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

/*
Async Actions is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.

Async Actions is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Async Actions. If not, see <https://www.gnu.org/licenses/>.
*/


defined('ABSPATH') || exit;

/** 
 * Include the database setup file. 
*/
require_once __DIR__ . '/db.php';

/**
 * Create the database table on plugin activation.
 */
register_activation_hook(__FILE__, 'async_setup_db');

/**
 * Generate a secret key for internal requests.
 */
register_activation_hook(__FILE__, function () {

    if (!get_option('async_secret_key')) {
        add_option(
            'async_secret_key',
            bin2hex(random_bytes(32))
        );
    }

});

/**
 * Define the secret key constant.
 */
function async_secret(): string {
    return (string) get_option('async_secret_key');
}

/**
 * Register REST endpoint.
 */
add_action('rest_api_init', function () {

    register_rest_route('async-task/v1', '/process', [
        'methods'             => 'POST',
        'callback'            => 'async_process',
        'permission_callback' => 'async_permission',
    ]);

    register_rest_route('async-task/v1', '/process-queue', [
        'methods'             => 'POST',
        'callback'            => 'async_process_queue',
        'permission_callback' => 'async_permission',
    ]);

});

/**
 * Authenticate internal requests.
 */
function async_permission(WP_REST_Request $request): bool
{
    return hash_equals(
        async_secret(),
        (string) $request->get_header('X-Async-Secret')
    );
}

/**
 * Worker endpoint
 * Execute an async task.
 */
function async_process(WP_REST_Request $request)
{
    $task = sanitize_key($request->get_param('task'));
    $data = (array) $request->get_param('data');

    if (empty($task)) {
        return new WP_Error(
            'missing_task',
            'Task not specified.',
            ['status' => 400]
        );
    }

    $hook = "async_task_{$task}";

    if (!has_action($hook)) {
        return new WP_Error(
            'unknown_task',
            sprintf('No callback registered for "%s".', $hook),
            ['status' => 404]
        );
    }

    /**
     * Execute user callback.
     */
    try {
        do_action($hook, $data);
        
    } catch (Throwable $e) {
        return new WP_Error(
            'task_failed',
            sprintf('Task "%s" failed: %s', $task, $e->getMessage()),
            ['status' => 500]
        );
    }

    return new WP_REST_Response([
        'success' => true,
    ], 200);
}

/**
 * Process the async queue.
 */
function async_process_queue(): void
{
    global $wpdb;

    $table = $wpdb->prefix . 'async_queue';

    $batch_size = (int) apply_filters('async_batch_size', 10);
    $max_runtime = (float) apply_filters('async_max_runtime', 20);

    $start = microtime(true);

    $jobs = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT *
            FROM {$table}
            WHERE status = %s
              AND (available_at IS NULL OR available_at <= %s)
            ORDER BY id ASC
            LIMIT %d
            ",
            'pending',
            current_time('mysql'),
            $batch_size
        )
    );

    if (empty($jobs)) {
        return;
    }

    foreach ($jobs as $job) {

        // Stop before exceeding the configured runtime.
        if ((microtime(true) - $start) >= $max_runtime) {
            break;
        }

        $attempts = $job->attempts + 1;

        // Mark as processing.
        $wpdb->update(
            $table,
            [
                'status'   => 'processing',
                'attempts' => $attempts,
            ],
            [
                'id' => $job->id,
            ]
        );

        try {

            $data = json_decode($job->payload, true);

            do_action(
                "async_queue_task_{$job->task}",
                $data
            );

            $wpdb->update(
                $table,
                [
                    'status' => 'done',
                ],
                [
                    'id' => $job->id,
                ]
            );

        } catch (Throwable $e) {

            $wpdb->update(
                $table,
                [
                    'status'       => ($attempts >= 3) ? 'failed' : 'pending',
                    'attempts'     => $attempts,
                    'available_at' => gmdate('Y-m-d H:i:s', time() + 30),
                ],
                [
                    'id' => $job->id,
                ]
            );

            /**
             * Allow plugins to log or react to failed jobs.
             */
            do_action(
                'async_queue_job_failed',
                $job,
                $e
            );
        }
    }
}

/**
 * Dispatch an async task.
 */
function async_dispatch(string $task, array $data = []): void
{
    wp_remote_post(
        rest_url('async-task/v1/process'),
        [
            'blocking' => false,
            'timeout'  => 0.01,

            'headers' => [
                'X-Async-Secret' => async_secret(),
                'Content-Type'       => 'application/json',
                'Accept'             => 'application/json',
            ],

            'body' => wp_json_encode([
                'task' => $task,
                'data' => $data,
            ]),
        ]
    );
}

/**
 * Dispatch an async task to the queue.
 */
function async_queue_dispatch(string $task, array $data = []): void
{
    global $wpdb;

    $table = $wpdb->prefix . 'async_queue';

    $wpdb->insert($table, [
        'task'        => sanitize_key($task),
        'payload'     => wp_json_encode($data),
        'status'      => 'pending',
        'created_at'  => current_time('mysql'),
        'available_at'=> current_time('mysql'),
    ]);
}

/**
 * Add a custom cron schedule for every minute.
 * Must be a direct add_filter call — not wrapped in any hook.
 */
add_filter('cron_schedules', function ($schedules) {
    $schedules['minute'] = [
        'interval' => 30,
        'display'  => 'Every 30 seconds',
    ];
    return $schedules;
});

/**
 * Schedule the worker to run every minute.
 * Using init ensures the event is re-added automatically
 * if it was ever cleared (deactivation, cron cleanup, etc.).
 */
add_action('init', function () {
    if (!wp_next_scheduled('async_worker')) {
        wp_schedule_event(time(), 'minute', 'async_worker');
    }
});

add_action('async_worker', function () {
    if (!get_option('async_cron_paused', false)) {
        async_process_queue();
    }
});

/**
 * Unschedule the worker on plugin deactivation.
 */
register_deactivation_hook(__FILE__, function () {

    $timestamp = wp_next_scheduled('async_worker');

    if ($timestamp) {
        wp_unschedule_event($timestamp, 'async_worker');
    }

});

/**
 * Pause the worker cron job.
 */
add_filter('async_batch_size', function () {
    return get_option('async_cron_paused', false) ? 0 : (int) get_option('async_batch_size', 10);
});

add_filter('async_max_runtime', function () {
    return get_option('async_cron_paused', false) ? 0 : (int) get_option('async_max_runtime', 30);
});


// task example:
/*
add_action('async_task_send_email', function ($data) {
    wp_mail(
        $data['email'],
        'Hello',
        'Welcome!'
    );
});
*/

// queue task example:
/*
add_action('async_queue_task_send_email', function ($data) {
    wp_mail(
        $data['email'],
        'Hello',
        'Welcome!'
    );
});
*/

// dispatch example:
/*
async_dispatch(
    'send_email',
    [
        'email' => 'john@example.com',
    ]
);
*/

// queue dispatch example:
/*
async_queue_dispatch(
    'send_email',
    [
        'email' => 'john@example.com',
    ]
);
*/

add_action('admin_menu', function () {

    add_menu_page(
        'Async Actions',
        'Async Actions',
        'manage_options',
        'async-actions',
        'async_admin_page',
        'dashicons-admin-generic',
        80
    );

    add_submenu_page(
        'async-actions',
        'Documentation',
        'Documentation',
        'manage_options',
        'async-actions-docs',
        'async_docs_page'
    );

});

function async_admin_page()
{
    include __DIR__ . '/admin/index.php';
}

function async_docs_page()
{
    include __DIR__ . '/admin/docs.php';
}

/**
 * Include the test file for development purposes.
 * Uncomment the line below to enable it.
 */
// include __DIR__ . '/tests.php';
