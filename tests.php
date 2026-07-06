<?php

defined('ABSPATH') || exit;

/*
* Example of a task that takes a long time to complete. This task will be executed asynchronously in the background.
* Two ways to dispatch the task:
* 1. Using lab_async_dispatch() - this will execute the task immediately in the background
* 2. Using lab_async_queue_dispatch() - this will add the task to a queue
*/


// add_action('async_task_test', function ($data) {
//     sleep(15); // simulate a long-running task

//     $upload_dir = wp_upload_dir();
//     $log_file = $upload_dir['basedir'] . '/email_log.txt';

//     file_put_contents($log_file, "Email sent to: {$data['email']}\n", FILE_APPEND);
// }, 10, 1);


add_action('async_queue_task_test', function ($data) {

    sleep(15); // simulate a long-running task

    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/email_log.txt';

    file_put_contents(
        $log_file,
        "Email sent to: {$data['email']}\n",
        FILE_APPEND
    );

}, 10, 1);


add_action('template_redirect', function () {

    if (is_admin()) {
        return;
    }

    $path = trim(wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

    if ($path !== 'lightroom') {
        return;
    }

    // async_dispatch('test', [
    //     'email' => 'test@example.com',
    // ]);

    async_queue_dispatch('test', [
        'email' => 'test@example.com',
    ]);
});