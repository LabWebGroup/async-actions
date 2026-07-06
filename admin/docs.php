<?php

defined('ABSPATH') || exit;

?>

<style>
    .lat-docs { }
    .lat-docs-layout { display: flex; gap: 40px; align-items: flex-start; margin-top: 8px; }
    .lat-docs-sidebar { width: 210px; flex-shrink: 0; position: sticky; top: 32px; z-index: 0; }
    .lat-docs-sidebar .lat-toc { display: block; width: 100%; min-width: 0; box-sizing: border-box; margin-bottom: 0; }
    .lat-docs-main { flex: 1; min-width: 0; position: relative; z-index: 1; padding-left: 20px;}
    .lat-docs h2 { border-bottom: 1px solid #dcdcde; padding-bottom: 8px; margin-top: 2em; }
    .lat-docs h3 { margin-top: 1.5em; color: #1d2327; }
    .lat-docs pre {
        background: #1e1e1e;
        color: #d4d4d4;
        padding: 16px 20px;
        border-radius: 4px;
        overflow-x: auto;
        font-size: 13px;
        line-height: 1.6;
        margin: 12px 0;
    }
    .lat-docs code:not(pre code) {
        background: #f0f0f1;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 13px;
    }
    .lat-docs .lat-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        vertical-align: middle;
        margin-left: 6px;
    }
    .lat-badge-post  { background: #d63638; color: #fff; }
    .lat-badge-hook  { background: #2271b1; color: #fff; }
    .lat-badge-fn    { background: #00a32a; color: #fff; }
    .lat-docs .lat-notice {
        background: #fff;
        border-left: 4px solid #2271b1;
        padding: 10px 16px;
        margin: 16px 0;
        box-shadow: 0 1px 3px rgba(0,0,0,.1);
    }
    .lat-docs .lat-notice.lat-warn { border-left-color: #dba617; }
    .lat-docs table.lat-table { border-collapse: collapse; width: 100%; margin: 12px 0; }
    .lat-docs table.lat-table th,
    .lat-docs table.lat-table td { padding: 8px 12px; border: 1px solid #dcdcde; text-align: left; font-size: 13px; }
    .lat-docs table.lat-table th { background: #f6f7f7; font-weight: 600; }
    .lat-docs .lat-toc { background: #f6f7f7; padding: 16px 20px; border-radius: 4px; display: inline-block; min-width: 260px; margin-bottom: 24px; }
    .lat-docs .lat-toc ol { margin: 0; padding-left: 20px; }
    .lat-docs .lat-toc li { margin: 4px 0; font-size: 13px; }
    .lat-section-label {
        display: inline-block;
        background: #f0f6fc;
        border: 1px solid #c8d8eb;
        color: #2271b1;
        border-radius: 3px;
        font-size: 12px;
        padding: 2px 8px;
        margin-bottom: 8px;
    }

    /* ── Responsive: tablet (≤ 1024 px) ── */
    @media screen and (max-width: 1024px) {
        .lat-docs-layout { flex-direction: column; gap: 0; }
        .lat-docs-sidebar { width: 100%; position: static; margin-bottom: 20px; }
        .lat-docs-sidebar .lat-toc { display: block; width: 100%; box-sizing: border-box; min-width: 0; }
    }

    /* ── Responsive: mobile (≤ 782 px — WP admin breakpoint) ── */
    @media screen and (max-width: 782px) {
        .lat-docs h1 { font-size: 22px; }
        .lat-docs h2 { font-size: 17px; }
        .lat-docs h3 { font-size: 15px; }

        /* Tables: horizontal scroll */
        .lat-docs table.lat-table {
            display: block;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            white-space: nowrap;
        }
        .lat-docs table.lat-table th,
        .lat-docs table.lat-table td { white-space: normal; min-width: 80px; }

        /* Code blocks */
        .lat-docs pre { font-size: 12px; padding: 12px 14px; }

        /* TOC */
        .lat-docs .lat-toc { padding: 12px 14px; }
    }

    /* ── Responsive: small phones (≤ 480 px) ── */
    @media screen and (max-width: 480px) {
        .lat-docs h1 { font-size: 18px; }
        .lat-docs h2 { font-size: 15px; }
        .lat-docs pre { font-size: 11px; padding: 10px 12px; }
        .lat-docs code:not(pre code) { font-size: 12px; }

        /* Stack notice boxes */
        .lat-docs .lat-notice { padding: 8px 12px; font-size: 13px; }
    }
</style>

<div class="wrap lat-docs">

    <h1>Lab Async Actions &mdash; Documentation</h1>
    <p style="color:#646970; font-size:14px;">Version 1.0.0 &nbsp;&bull;&nbsp; Lightweight async task dispatcher for WordPress</p>

    <div class="lat-docs-layout">

    <aside class="lat-docs-sidebar">
    <!-- TABLE OF CONTENTS -->
    <div class="lat-toc">
        <strong style="display:block; margin-bottom:8px;">Contents</strong>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#how-it-works">How It Works</a></li>
            <li><a href="#direct-tasks">Direct Async Tasks</a></li>
            <li><a href="#queue-tasks">Queue-Based Tasks</a></li>
            <li><a href="#api-reference">API Reference</a></li>
            <li><a href="#db-schema">Database Schema</a></li>
            <li><a href="#security">Security</a></li>
        </ol>
    </div>
    </aside><!-- .lat-docs-sidebar -->

    <div class="lat-docs-main">

    <!-- OVERVIEW -->
    <h2 id="overview">1. Overview</h2>
    <p>
        <strong>Lab Async Actions</strong> gives you two ways to run code outside the current HTTP request in WordPress,
        without relying on third-party services or complex queuing infrastructure:
    </p>
    <ul>
        <li><strong>Direct Async</strong> &mdash; fire-and-forget a task via a non-blocking internal REST call. Runs immediately in a background request.</li>
        <li><strong>Queue</strong> &mdash; push jobs to a database queue and process them via a WP-Cron worker that runs every minute, with automatic retries.</li>
    </ul>

    <!-- HOW IT WORKS -->
    <h2 id="how-it-works">2. How It Works</h2>

    <h3>Direct Async</h3>
    <p>
        When you call <code>lab_async_dispatch()</code>, the plugin makes a non-blocking <code>wp_remote_post()</code>
        to the internal REST endpoint <code>POST /wp-json/async-task/v1/process</code>.
        The current request returns immediately; WordPress processes the task in a separate PHP process.
    </p>

    <h3>Queue</h3>
    <p>
        When you call <code>lab_async_queue_dispatch()</code>, a row is inserted into the
        <code><?php echo esc_html($GLOBALS['wpdb']->prefix); ?>lab_async_queue</code> table with <code>status = 'pending'</code>.
        A WP-Cron event (<code>lab_async_worker</code>) fires every minute, picks up the oldest pending job,
        executes it, and marks it <code>done</code>. Failed jobs are retried up to 3 times with a 30-second delay.
    </p>

    <!-- DIRECT TASKS -->
    <h2 id="direct-tasks">3. Direct Async Tasks</h2>
    <span class="lat-section-label">Runs immediately</span>

    <h3>Step 1 — Register a task callback</h3>
    <p>Hook into <code>lab_async_task_{task_name}</code> anywhere in your theme or plugin:</p>
    <pre><code>add_action( 'lab_async_task_send_email', function ( array $data ) {
    wp_mail(
        $data['email'],
        'Subject',
        'Message body'
    );
} );</code></pre>

    <h3>Step 2 — Dispatch the task</h3>
    <pre><code>lab_async_dispatch(
    'send_email',          // task name (must match the hook suffix)
    [
        'email' => 'john@example.com',
    ]
);</code></pre>

    <div class="lat-notice">
        <strong>Tip:</strong> The task name is sanitized with <code>sanitize_key()</code>, so use lowercase letters, numbers, and underscores.
    </div>

    <!-- QUEUE TASKS -->
    <h2 id="queue-tasks">4. Queue-Based Tasks</h2>
    <span class="lat-section-label">Runs via WP-Cron (every minute)</span>

    <h3>Step 1 — Register a queue task callback</h3>
    <p>Hook into <code>lab_async_queue_task_{task_name}</code>:</p>
    <pre><code>add_action( 'lab_async_queue_task_resize_image', function ( array $data ) {
    // heavy processing here
    generate_thumbnail( $data['attachment_id'] );
} );</code></pre>

    <h3>Step 2 — Push a job to the queue</h3>
    <pre><code>lab_async_queue_dispatch(
    'resize_image',
    [
        'attachment_id' => 42,
    ]
);</code></pre>

    <h3>Retry behaviour</h3>
    <table class="lat-table">
        <thead>
            <tr>
                <th>Attempt</th>
                <th>Outcome on failure</th>
                <th>Retry delay</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>1st</td><td>status → <code>pending</code></td><td>30 s</td></tr>
            <tr><td>2nd</td><td>status → <code>pending</code></td><td>30 s</td></tr>
            <tr><td>3rd</td><td>status → <code>pending</code></td><td>30 s</td></tr>
            <tr><td>4th (max)</td><td>status → <code>failed</code></td><td>&mdash;</td></tr>
        </tbody>
    </table>

    <!-- API REFERENCE -->
    <h2 id="api-reference">5. API Reference</h2>

    <h3><code>lab_async_dispatch( $task, $data )</code> <span class="lat-badge lat-badge-fn">function</span></h3>
    <table class="lat-table">
        <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
        <tr><td><code>$task</code></td><td>string</td><td>Task name. Triggers hook <code>lab_async_task_{task}</code>.</td></tr>
        <tr><td><code>$data</code></td><td>array</td><td>Arbitrary payload passed to the hook callback.</td></tr>
    </table>
    <p>Fires a non-blocking internal REST request. Returns <code>void</code>.</p>

    <h3><code>lab_async_queue_dispatch( $task, $data )</code> <span class="lat-badge lat-badge-fn">function</span></h3>
    <table class="lat-table">
        <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
        <tr><td><code>$task</code></td><td>string</td><td>Task name. Triggers hook <code>lab_async_queue_task_{task}</code>.</td></tr>
        <tr><td><code>$data</code></td><td>array</td><td>Arbitrary payload serialized as JSON in the queue row.</td></tr>
    </table>
    <p>Inserts a pending job into the database queue. Returns <code>void</code>.</p>

    <h3>REST Endpoints <span class="lat-badge lat-badge-post">POST</span></h3>
    <table class="lat-table">
        <tr><th>Endpoint</th><th>Description</th></tr>
        <tr><td><code>/wp-json/async-task/v1/process</code></td><td>Execute a single direct task.</td></tr>
        <tr><td><code>/wp-json/async-task/v1/process-queue</code></td><td>Process the next pending queue job.</td></tr>
    </table>
    <p>Both endpoints require the <code>X-Lab-Async-Secret</code> header. They are intended for internal use only.</p>

    <h3>Action Hooks <span class="lat-badge lat-badge-hook">hooks</span></h3>
    <table class="lat-table">
        <tr><th>Hook</th><th>When fired</th></tr>
        <tr><td><code>lab_async_task_{name}</code></td><td>During direct-async processing. Receives <code>$data</code> array.</td></tr>
        <tr><td><code>lab_async_queue_task_{name}</code></td><td>During queue processing. Receives <code>$data</code> array.</td></tr>
        <tr><td><code>lab_async_worker</code></td><td>WP-Cron event; fires every minute to process the queue.</td></tr>
    </table>

    <!-- DATABASE SCHEMA -->
    <h2 id="db-schema">6. Database Schema</h2>
    <p>Table: <code><?php echo esc_html($GLOBALS['wpdb']->prefix); ?>lab_async_queue</code> &mdash; created on plugin activation.</p>
    <table class="lat-table">
        <tr><th>Column</th><th>Type</th><th>Description</th></tr>
        <tr><td><code>id</code></td><td>BIGINT UNSIGNED</td><td>Auto-increment primary key.</td></tr>
        <tr><td><code>task</code></td><td>VARCHAR(191)</td><td>Sanitized task name.</td></tr>
        <tr><td><code>payload</code></td><td>LONGTEXT</td><td>JSON-encoded data array.</td></tr>
        <tr><td><code>status</code></td><td>VARCHAR(20)</td><td><code>pending</code> | <code>processing</code> | <code>done</code> | <code>failed</code></td></tr>
        <tr><td><code>attempts</code></td><td>INT</td><td>Number of execution attempts so far.</td></tr>
        <tr><td><code>created_at</code></td><td>DATETIME</td><td>When the job was pushed.</td></tr>
        <tr><td><code>available_at</code></td><td>DATETIME</td><td>Earliest time the job may be picked up (used for retry delays).</td></tr>
    </table>

    <!-- SECURITY -->
    <h2 id="security">7. Security</h2>
    <p>
        The REST endpoints are protected by a 64-character hex secret key (<code>LAB_ASYNC_SECRET</code>)
        generated on activation and stored in WordPress options. Every internal request passes this key
        in the <code>X-Lab-Async-Secret</code> header; the permission callback uses <code>hash_equals()</code>
        to prevent timing attacks.
    </p>
    <div class="lat-notice lat-warn">
        <strong>Note:</strong> The secret is stored in <code>wp_options</code>. Make sure your database is not publicly accessible and that your <code>wp-config.php</code> credentials are kept private.
    </div>

    </div><!-- .lat-docs-main -->
    </div><!-- .lat-docs-layout -->
</div>
