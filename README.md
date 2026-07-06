# async-actions
Lightweight background job queue and async task dispatcher for WordPress.

## How to Use

There are two ways to run tasks in the background:

1. **`lab_async_dispatch()`** — fires the task immediately in a non-blocking HTTP request (fire-and-forget).
2. **`lab_async_queue_dispatch()`** — adds the task to a persistent database queue, processed by WP-Cron every 30 seconds (supports retries).

---

### Immediate Dispatch

Register a handler using the `lab_async_task_{name}` hook, then dispatch it anywhere.

```php
// Register the task handler
add_action('lab_async_task_send_email', function ($data) {
    wp_mail($data['email'], 'Hello', 'Welcome!');
});

// Dispatch the task (non-blocking, runs immediately in the background)
lab_async_dispatch('send_email', [
    'email' => 'john@example.com',
]);
```

---

### Queue Dispatch

Register a handler using the `lab_async_queue_task_{name}` hook. Jobs are processed in batches by WP-Cron and automatically retried (up to 3 attempts) on failure.

```php
// Register the queue task handler
add_action('lab_async_queue_task_send_email', function ($data) {
    wp_mail($data['email'], 'Hello', 'Welcome!');
});

// Add the task to the queue
lab_async_queue_dispatch('send_email', [
    'email' => 'john@example.com',
]);
```

---

### Handling Failed Jobs

Use the `lab_async_queue_job_failed` action to log or react when a queued job fails after all retry attempts.

```php
add_action('lab_async_queue_job_failed', function ($job, $exception) {
    error_log("Job {$job->task} failed: " . $exception->getMessage());
}, 10, 2);
```

---

### Filters

| Filter | Default | Description |
|---|---|---|
| `lab_async_batch_size` | `10` | Number of queue jobs processed per cron run. |
| `lab_async_max_runtime` | `30` | Max seconds the queue worker runs per cron cycle. |
