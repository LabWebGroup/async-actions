<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}

global $wpdb;
$table     = $wpdb->prefix . 'async_queue';
$nonce_key = 'async_queue_manage';
$page_url  = admin_url('admin.php?page=async-actions');

// ── Handle POST ────────────────────────────────────────────────────────────────
if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['async_action'])) {
    check_admin_referer($nonce_key);

    $act = sanitize_key($_POST['async_action'] ?? '');
    $msg = '';

    switch ($act) {

        case 'process_now':
            async_process_queue();
            $msg = 'Next pending job processed (if any was available).';
            break;

        case 'toggle_cron':
            $was_paused = (bool) get_option('async_cron_paused', false);
            update_option('async_cron_paused', !$was_paused);
            $msg = $was_paused
                ? 'Cron worker <strong>resumed</strong>.'
                : 'Cron worker <strong>paused</strong>.';
            break;

        case 'change_status':
            $id  = absint($_POST['job_id']     ?? 0);
            $new = sanitize_key($_POST['new_status'] ?? '');
            if ($id && in_array($new, ['pending', 'processing', 'done', 'failed'], true)) {
                $wpdb->update(
                    $table,
                    ['status' => $new, 'available_at' => current_time('mysql')],
                    ['id'     => $id],
                    ['%s',    '%s'],
                    ['%d']
                );
                $msg = "Job <strong>#{$id}</strong> status changed to <strong>{$new}</strong>.";
            }
            break;

        case 'delete':
            $id = absint($_POST['job_id'] ?? 0);
            if ($id) {
                $wpdb->delete($table, ['id' => $id], ['%d']);
                $msg = "Job <strong>#{$id}</strong> deleted.";
            }
            break;

        case 'bulk_delete':
            $ids = array_filter(array_map('absint', (array) ($_POST['job_ids'] ?? [])));
            if ($ids) {
                $n           = count($ids);
                $placeholders = implode(',', array_fill(0, $n, '%d'));
                $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->prepare("DELETE FROM `{$table}` WHERE id IN ({$placeholders})", ...$ids)
                );
                $msg = "<strong>{$n}</strong> job(s) deleted.";
            }
            break;

        case 'bulk_process':
            $ids = array_filter(array_map('absint', (array) ($_POST['job_ids'] ?? [])));
            $processed = 0;
            foreach ($ids as $id) {
                $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
                if (!$job) continue;

                $wpdb->update($table, [
                    'status'   => 'processing',
                    'attempts' => $job->attempts + 1,
                ], ['id' => $job->id], ['%s', '%d'], ['%d']);

                $data = json_decode($job->payload, true) ?? [];

                try {
                    do_action("async_queue_task_{$job->task}", $data);
                    $wpdb->update($table, ['status' => 'done'], ['id' => $job->id], ['%s'], ['%d']);
                    $processed++;
                } catch (Throwable $e) {
                    $wpdb->update($table, [
                        'status'       => ($job->attempts >= 3) ? 'failed' : 'pending',
                        'available_at' => gmdate('Y-m-d H:i:s', time() + 30),
                    ], ['id' => $job->id], ['%s', '%s'], ['%d']);
                }
            }
            $msg = "<strong>{$processed}</strong> of <strong>" . count($ids) . "</strong> job(s) processed.";
            break;

        case 'save_worker_settings':
            $batch_size  = max(1, (int) ($_POST['batch_size']  ?? 10));
            $max_runtime = max(1, (int) ($_POST['max_runtime'] ?? 30));
            update_option('async_batch_size',  $batch_size);
            update_option('async_max_runtime', $max_runtime);
            $msg = 'Worker settings saved.';
            break;

        case 'bulk_change_status':
            $ids = array_filter(array_map('absint', (array) ($_POST['job_ids'] ?? [])));
            $new = sanitize_key($_POST['bulk_new_status'] ?? '');
            if ($ids && in_array($new, ['pending', 'processing', 'done', 'failed'], true)) {
                $n   = count($ids);
                $ph  = implode(',', array_fill(0, $n, '%d'));
                $now = current_time('mysql');
                $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->prepare(
                        "UPDATE `{$table}` SET status = %s, available_at = %s WHERE id IN ({$ph})",
                        ...array_merge([$new, $now], array_values($ids))
                    )
                );
                $msg = "<strong>{$n}</strong> job(s) changed to <strong>{$new}</strong>.";
            }
            break;
    }

    if ($msg) {
        set_transient('lat_notice_' . get_current_user_id(), $msg, 60);
    }

    $qs_parts = [];
    if (!empty($_GET['status']))   $qs_parts['status']   = sanitize_key($_GET['status']);
    if (!empty($_GET['per_page'])) $qs_parts['per_page'] = (int) $_GET['per_page'];
    if (!empty($_GET['paged']))    $qs_parts['paged']    = (int) $_GET['paged'];
    $redirect = esc_url(add_query_arg($qs_parts, $page_url));
    echo '<script>window.location.replace(' . wp_json_encode($redirect) . ');</script>';
    exit;
}

// ── Flash notice ────────────────────────────────────────────────────────────────
$notice = get_transient('lat_notice_' . get_current_user_id());
if ($notice) {
    delete_transient('lat_notice_' . get_current_user_id());
}

// ── Job counts ──────────────────────────────────────────────────────────────────
$raw_counts = $wpdb->get_results(
    "SELECT status, COUNT(*) AS n FROM `{$table}` GROUP BY status",
    ARRAY_A
);
$counts = array_column($raw_counts, 'n', 'status');
$total  = (int) array_sum($counts);
$cnt    = fn(string $s): int => (int) ($counts[$s] ?? 0);

// ── Status filter ───────────────────────────────────────────────────────────────
$filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
if ($filter && !in_array($filter, ['pending', 'processing', 'done', 'failed'], true)) {
    $filter = '';
}

// ── Pagination ──────────────────────────────────────────────────────────────────
$allowed_per_page = [10, 20, 50, 100];
$raw_per_page     = (int) ($_GET['per_page'] ?? 20);
$per_page         = in_array($raw_per_page, $allowed_per_page, true) ? $raw_per_page : 20;
$current_page     = max(1, (int) ($_GET['paged'] ?? 1));

$filtered_total   = $filter
    ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE status = %s", $filter))
    : $total;
$total_pages  = max(1, (int) ceil($filtered_total / $per_page));
$current_page = min($current_page, $total_pages);
$offset       = ($current_page - 1) * $per_page;

$paginate_url = function (int $p) use ($page_url, $filter, $per_page): string {
    $args = ['per_page' => $per_page, 'paged' => $p];
    if ($filter) $args['status'] = $filter;
    return esc_url(add_query_arg($args, $page_url));
};

$pagination_nav = function () use ($current_page, $total_pages, $filtered_total, $paginate_url): void {
    if ($filtered_total === 0) return;
    $cls = $total_pages <= 1 ? ' one-page' : '';
    echo "<div class=\"tablenav-pages" . esc_html($cls) . "\">";
    $n = number_format($filtered_total);
    $s = $filtered_total === 1 ? 'item' : 'items';
    echo "<span class=\"displaying-num\">" . esc_html($n) . " " . esc_html($s) . "</span>";
    if ($total_pages > 1) {
        echo '<span class="pagination-links">';
        echo $current_page > 1
            ? '<a class="first-page button" href="' . esc_url($paginate_url(1)) . '">«</a>'
            : '<span class="first-page button disabled" aria-hidden="true">«</span>';
        echo $current_page > 1
            ? '<a class="prev-page button" href="' . esc_url($paginate_url($current_page - 1)) . '">‹</a>'
            : '<span class="prev-page button disabled" aria-hidden="true">‹</span>';
        echo '<span class="paging-input">' . esc_html($current_page) . ' / <span class="total-pages">' . esc_html($total_pages) . '</span></span>';
        echo $current_page < $total_pages
            ? '<a class="next-page button" href="' . esc_url($paginate_url($current_page + 1)) . '">›</a>'
            : '<span class="next-page button disabled" aria-hidden="true">›</span>';
        echo $current_page < $total_pages
            ? '<a class="last-page button" href="' . esc_url($paginate_url($total_pages)) . '">»</a>'
            : '<span class="last-page button disabled" aria-hidden="true">»</span>';
        echo '</span>';
    }
    echo '</div>';
};

$perpage_form = function () use ($per_page, $filter, $page_url): void {
    $base_args = $filter ? ['status' => $filter] : [];
    $base_url  = esc_attr(add_query_arg($base_args, $page_url));
    echo '<label class="screen-reader-text" for="lat-per-page-sel">Items per page</label>';
    echo '<select id="lat-per-page-sel" class="lat-perpage-sel" data-baseurl="' . esc_html($base_url) . '" aria-label="Items per page"';
    echo ' onchange="window.location.href=this.dataset.baseurl+\'&per_page=\'+this.value">';
    foreach ([10, 20, 50, 100] as $pp) {
        echo '<option value="' . esc_attr($pp) . '"' . ($per_page === $pp ? ' selected' : '') . '>' . esc_html($pp) . ' per page</option>';
    }
    echo '</select>';
};

// ── Fetch jobs ──────────────────────────────────────────────────────────────────
$jobs = $filter
    ? $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM `{$table}` WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $filter, $per_page, $offset)
    )
    : $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset)
    );

// ── Cron info ───────────────────────────────────────────────────────────────────
$cron_paused  = (bool) get_option('async_cron_paused', false);
$opt_batch    = (int)  get_option('async_batch_size',  10);
$opt_runtime  = (int)  get_option('async_max_runtime', 20);
$next_run    = wp_next_scheduled('async_worker');
$secret      = get_option('async_secret_key', '');
$proc_url    = rest_url('async-task/v1/process-queue');
$curl_cmd    = '* * * * * curl -s -o /dev/null -X POST "' . esc_url_raw($proc_url) . '" -H "X-Lab-Async-Secret: ' . esc_html($secret) . '"';

$status_meta = [
    'pending'    => ['label' => 'Pending',    'color' => '#ea610c', 'bg' => '#fef3c7'],
    'processing' => ['label' => 'Processing', 'color' => '#1641ce', 'bg' => '#dbeafe'],
    'done'       => ['label' => 'Done',       'color' => '#1ca450', 'bg' => '#dcfce7'],
    'failed'     => ['label' => 'Failed',     'color' => '#c51010', 'bg' => '#fee2e2'],
];
?>
<style>
    /* ── Layout ── */
    .lat-qm .wp-header-end { margin-bottom: 16px; }

    /* ── Stats cards ── */
    .lat-stats { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
    .lat-stat-card {
        flex: 1;
        background: #fff;
        border: 1px solid #dcdcde;
        border-radius: 6px;
        padding: 24px 28px;
        min-width: 110px;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,.04);
    }
    .lat-stat-card .lat-stat-num { font-size: 42px; font-weight: 700; line-height: 1; }
    .lat-stat-card .lat-stat-lbl { font-size: 12px; color: #646970; margin-top: 8px; text-transform: uppercase; letter-spacing: .6px; }

    /* ── Status badge ── */
    .lat-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }

    /* ── Table ── */
    .lat-qm .column-cb   { width: 50px; }
    .lat-qm .column-id   { width: 55px; }
    .lat-qm .column-task { width: 300px; }
    .lat-qm .column-status   { width: 200px; }
    .lat-qm .column-attempts { width: 75px; text-align: center; }
    .lat-qm .column-created  { width: 150px; }
    .lat-qm .column-available{ width: 150px; }
    .lat-qm .column-actions  { width: 80px; }
    .lat-qm td code { font-size: 12px; }
    .lat-qm td.column-payload { font-size: 12px; color: #50575e; word-break: break-all; }

    /* ── Inline status form ── */
    .lat-status-wrap { display: flex; align-items: center; gap: 6px; }
    .lat-status-wrap select { max-width: 115px; }
    .lat-status-wrap .button { padding: 0 8px; height: 24px; line-height: 22px; font-size: 11px; }

    /* ── Delete button ── */
    .lat-btn-delete { color: #d63638 !important; text-decoration: none; font-size: 12px; cursor: pointer; background: none; border: none; padding: 0; }
    .lat-btn-delete:hover { color: #8a1f1f !important; }

    /* ── Cron section ── */
    .lat-cron-box {
        background: #fff;
        border: 1px solid #dcdcde;
        border-radius: 6px;
        padding: 24px 28px;
        margin-top: 32px;
    }
    .lat-cron-box > h2 { margin-top: 0; padding-top: 0; font-size: 16px; border-bottom: 1px solid #f0f0f1; padding-bottom: 14px; margin-bottom: 20px; }
    .lat-cron-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .lat-cron-panel { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 20px 22px; }
    .lat-cron-panel-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #646970; margin: 0 0 16px; }
    .lat-cron-next { margin-top: 18px; padding-top: 16px; border-top: 1px solid #dcdcde; }
    .lat-cron-next-label { font-size: 12px; font-weight: 600; color: #1d2327; margin: 0 0 6px; }
    .lat-cron-box textarea { font-family: monospace; font-size: 12px; background: #1e1e1e; color: #d4d4d4; border-color: #1e1e1e; resize: vertical; width: 100%; box-sizing: border-box; }
    .lat-cron-status { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 14px; }
    .lat-cron-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
    .lat-cron-dot.active { background: #00a32a; }
    .lat-cron-dot.paused { background: #dba617; }

    /* ── Tablenav ── */
    .lat-qm .tablenav { height: auto; margin: 6px 0 8px; }
    .lat-qm .tablenav::after { content: ''; display: table; clear: both; }
    .tablenav .bulkactions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .lat-bulk-sep { display: inline-block; width: 1px; height: 20px; background: #dcdcde; margin: 0 4px; align-self: center; }
    .lat-qm .subsubsub { margin: 6px 0 8px; }

    /* ── Per-page & pagination ── */
    .lat-perpage-sel { height: 28px; font-size: 13px; float: right; margin: 2px 8px 0 0; }
    .tablenav-pages { float: right; display: inline-flex; align-items: center; gap: 4px; margin-top: 3px; }
    .tablenav-pages .displaying-num { font-size: 13px; color: #646970; margin-right: 6px; }
    .tablenav-pages .pagination-links { display: inline-flex; align-items: center; gap: 2px; }
    .tablenav-pages .button {
        min-width: 28px; height: 28px; line-height: 26px;
        padding: 0 6px; text-align: center; font-size: 14px;
    }
    .tablenav-pages .button.disabled { color: #a7aaad !important; border-color: #dcdcde !important; pointer-events: none; box-shadow: none; }
    .tablenav-pages .paging-input { font-size: 13px; padding: 0 8px; white-space: nowrap; }
    .tablenav-pages.one-page .pagination-links { display: none; }

    /* ── Table scroll wrapper ── */
    .lat-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    /* Prevent table-layout:fixed from squishing columns — scroll instead */
    .lat-qm .wp-list-table { min-width: 1060px; }

    @media screen and (max-width: 1500px) {
        .lat-qm .column-task { width: 100px; }
    }

    /* ── Always-visible scroll bar at ≤ 1360 px ── */
    @media screen and (max-width: 1360px) {
        .lat-table-wrap { overflow-x: scroll; }
        .lat-qm .column-task { width: 70px; }
    }

    /* ── Responsive: tablet (≤ 1024 px) ── */
    @media screen and (max-width: 1024px) {
        .lat-qm .column-available { display: none; }
        .lat-qm tfoot { display: none; }
        /* available column hidden → reduce min-width */
        .lat-qm .wp-list-table { min-width: 900px; }
    }

    /* ── Responsive: mobile (≤ 782 px — WP admin breakpoint) ── */
    @media screen and (max-width: 782px) {
        /* Stats cards: two per row */
        .lat-stat-card { flex: 1 1 calc(50% - 8px); min-width: 0; }

        /* Filter tabs: wrap as pill links */
        .lat-qm .subsubsub {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 0;
            padding: 0;
            margin: 8px 0;
        }
        .lat-qm .subsubsub li { line-height: 1.8; }

        /* Hide lower-priority columns */
        .lat-qm .column-attempts,
        .lat-qm .column-available,
        .lat-qm .column-created { display: none; }

        /* Status wrap: stack on narrow cells */
        .lat-status-wrap { flex-direction: column; align-items: flex-start; gap: 4px; }
        .lat-status-wrap select { max-width: 100%; width: 100%; }

        /* attempts + available + created hidden → reduce min-width */
        .lat-qm .wp-list-table { min-width: 560px; }

        /* Cron box: single column on mobile */
        .lat-cron-box { padding: 14px 16px; }
        .lat-cron-grid { grid-template-columns: 1fr; }

        /* Tablenav: switch from float-based to flex column */
        .lat-qm .tablenav.top,
        .lat-qm .tablenav.bottom {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .lat-qm .tablenav .alignleft { float: none; }
        .lat-perpage-sel { float: none; margin: 0; width: auto; }
        .tablenav-pages {
            float: none;
            justify-content: flex-start;
            flex-wrap: wrap;
            margin-top: 0;
        }
        .lat-qm .tablenav br.clear { display: none; }
        .lat-qm .column-status   { width: 100px; }
    }

    /* ── Responsive: small phones (≤ 480 px) ── */
    @media screen and (max-width: 480px) {
        .lat-qm h1.wp-heading-inline { font-size: 18px; }

        /* Stats: full width */
        .lat-stat-card { flex: 1 1 100%; padding: 10px 14px; }
        .lat-stat-card .lat-stat-num { font-size: 22px; }

        /* Also hide payload on tiny screens */
        .lat-qm .column-payload { display: none; }

        /* payload also hidden → reduce min-width further */
        .lat-qm .wp-list-table { min-width: 460px; }

        /* Prevent task column overflowing */
        .lat-qm .column-task { max-width: 80px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    }
</style>

<div class="wrap lat-qm">

    <h1 class="wp-heading-inline">Queue Manager</h1>

    <!-- Process Now -->
    <form method="POST" style="display:inline; margin-left:8px;">
        <?php wp_nonce_field($nonce_key); ?>
        <input type="hidden" name="async_action" value="process_now">
        <button type="submit" class="page-title-action" style="font-size:13px; padding:3px 12px;">
            &#9654;&nbsp; Process Next Job
        </button>
    </form>

    <hr class="wp-header-end">

    <?php if ($notice): ?>
    <div class="notice notice-success is-dismissible"><p><?php echo wp_kses($notice, ['strong' => []]); ?></p></div>
    <?php endif; ?>

    <!-- ── Stats cards ─────────────────────────────────────────────────────── -->
    <div class="lat-stats">
        <div class="lat-stat-card">
            <div class="lat-stat-num"><?php echo esc_html($total); ?></div>
            <div class="lat-stat-lbl">Total</div>
        </div>
        <?php foreach ($status_meta as $s => $meta): ?>
        <div class="lat-stat-card">
            <div class="lat-stat-num" style="color:<?php echo esc_attr($meta['color']); ?>"><?php echo esc_html($cnt($s)); ?></div>
            <div class="lat-stat-lbl"><?php echo esc_html($meta['label']); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Filter tabs ──────────────────────────────────────────────────────── -->
    <ul class="subsubsub">
        <li>
            <a href="<?php echo esc_url(add_query_arg(['per_page' => $per_page], $page_url)); ?>"
               class="<?php echo $filter === '' ? 'current' : ''; ?>">
                All <span class="count">(<?php echo esc_html($total); ?>)</span>
            </a>
        </li>
        <?php foreach ($status_meta as $s => $meta): ?>
        <li> |
            <a href="<?php echo esc_url(add_query_arg(['status' => $s, 'per_page' => $per_page], $page_url)); ?>"
               class="<?php echo $filter === $s ? 'current' : ''; ?>">
                <?php echo esc_html($meta['label']); ?>
                <span class="count">(<?php echo esc_html($cnt($s)); ?>)</span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- ── Jobs table ───────────────────────────────────────────────────────── -->
    <form method="POST" id="lat-queue-form">
        <?php wp_nonce_field($nonce_key); ?>
        <!-- Filled by JS for individual row actions -->
        <input type="hidden" name="async_action" id="lat-form-action" value="">
        <input type="hidden" name="job_id"           id="lat-form-job-id" value="">
        <input type="hidden" name="new_status"       id="lat-form-new-status" value="">
        <input type="hidden" name="bulk_new_status"  id="lat-bulk-status-val" value="">

        <!-- Top bulk bar -->
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <button type="submit"
                        name="async_action"
                        value="bulk_delete"
                        class="button"
                        id="lat-bulk-delete-btn"
                        onclick="return latConfirmBulk()">
                    Delete Selected
                </button>

                <span class="lat-bulk-sep"></span>

                <button type="submit"
                        name="async_action"
                        value="bulk_process"
                        class="button"
                        onclick="return latConfirmBulkProcess()">
                    &#9654;&nbsp; Process Selected
                </button>

                <span class="lat-bulk-sep"></span>

                <select class="lat-bulk-status-sel" aria-label="Change selected status to">
                    <option value="">&#8212; Change status to &#8212;</option>
                    <?php foreach ($status_meta as $sv => $sm): ?>
                    <option value="<?php echo esc_attr($sv); ?>"><?php echo esc_html($sm['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"
                        name="async_action"
                        value="bulk_change_status"
                        class="button"
                        onclick="return latConfirmBulkStatus()">
                    Apply
                </button>
                
            </div>
            <?php $perpage_form(); ?>
            <?php $pagination_nav(); ?>
            <br class="clear">
        </div>

        <div class="lat-table-wrap">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-cb check-column">
                        <input type="checkbox" id="lat-check-all">
                    </th>
                    <th scope="col" class="manage-column column-id">ID</th>
                    <th scope="col" class="manage-column column-task">Task</th>
                    <th scope="col" class="manage-column column-payload">Payload</th>
                    <th scope="col" class="manage-column column-status">Status</th>
                    <th scope="col" class="manage-column column-attempts">Tries</th>
                    <th scope="col" class="manage-column column-created">Created At</th>
                    <th scope="col" class="manage-column column-available">Available At</th>
                    <th scope="col" class="manage-column column-actions">Actions</th>
                </tr>
            </thead>
            <tbody id="the-list">
            <?php if (empty($jobs)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:24px; color:#646970;">
                        No jobs found<?php echo $filter ? ' with status <strong>' . esc_html($filter) . '</strong>' : ''; ?>.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($jobs as $job):
                    $smeta = $status_meta[$job->status] ?? ['label' => ucfirst($job->status), 'color' => '#50575e', 'bg' => '#f6f7f7'];
                    $payload_short = mb_strimwidth($job->payload, 0, 80, '…');
                ?>
                <tr>
                    <th scope="row" class="check-column">
                        <input type="checkbox" name="job_ids[]" value="<?php echo (int) $job->id; ?>">
                    </th>
                    <td class="column-id"><?php echo (int) $job->id; ?></td>
                    <td class="column-task"><code><?php echo esc_html($job->task); ?></code></td>
                    <td class="column-payload"><?php echo esc_html($payload_short); ?></td>
                    <td class="column-status">
                        <div class="lat-status-wrap">
                            <select id="lat-sel-<?php echo (int) $job->id; ?>" aria-label="Status">
                                <?php foreach ($status_meta as $sv => $sm): ?>
                                <option value="<?php echo esc_attr($sv); ?>"
                                    <?php selected($job->status, $sv); ?>>
                                    <?php echo esc_html($sm['label']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button"
                                    class="button"
                                    onclick="latChangeStatus(<?php echo (int) $job->id; ?>)">
                                Save
                            </button>
                        </div>
                        <span class="lat-badge"
                              style="color:<?php echo esc_attr($smeta['color']); ?>; background:<?php echo esc_attr($smeta['bg']); ?>; margin-top:4px; display:inline-block;">
                            <?php echo esc_html($smeta['label']); ?>
                        </span>
                    </td>
                    <td class="column-attempts" style="text-align:center;"><?php echo (int) $job->attempts; ?></td>
                    <td class="column-created"><?php echo esc_html($job->created_at); ?></td>
                    <td class="column-available"><?php echo $job->available_at ? esc_html($job->available_at) : '<em style="color:#646970">—</em>'; ?></td>
                    <td class="column-actions">
                        <button type="button"
                                class="lat-btn-delete"
                                onclick="latDeleteJob(<?php echo (int) $job->id; ?>)">
                            Delete
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th class="manage-column column-cb check-column">
                        <input type="checkbox" id="lat-check-all-foot">
                    </th>
                    <th>ID</th><th>Task</th><th>Payload</th>
                    <th>Status</th><th>Tries</th><th>Created At</th><th>Available At</th><th>Actions</th>
                </tr>
            </tfoot>
        </table>
        </div><!-- .lat-table-wrap -->

        <!-- Bottom bulk bar -->
        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">

                <button type="submit"
                        name="async_action"
                        value="bulk_delete"
                        class="button"
                        onclick="return latConfirmBulk()">
                    Delete Selected
                </button>

                <span class="lat-bulk-sep"></span>

                <button type="submit"
                        name="async_action"
                        value="bulk_process"
                        class="button"
                        onclick="return latConfirmBulkProcess()">
                    &#9654;&nbsp; Process Selected
                </button>

                <span class="lat-bulk-sep"></span>

                <select class="lat-bulk-status-sel" aria-label="Change selected status to">
                    <option value="">&#8212; Change status to &#8212;</option>
                    <?php foreach ($status_meta as $sv => $sm): ?>
                    <option value="<?php echo esc_attr($sv); ?>"><?php echo esc_html($sm['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"
                        name="async_action"
                        value="bulk_change_status"
                        class="button"
                        onclick="return latConfirmBulkStatus()">
                    Apply
                </button>
                
            </div>
            <?php $pagination_nav(); ?>
            <br class="clear">
        </div>
    </form>

    <!-- ── Cron Settings ─────────────────────────────────────────────────────── -->
    <div class="lat-cron-box">
        <h2>Cron &amp; Worker Settings</h2>

        <div class="lat-cron-grid">

            <!-- Left panel: worker control + next run -->
            <div class="lat-cron-panel">
                <p class="lat-cron-panel-title">Worker Status</p>

                <div class="lat-cron-status">
                    <span class="lat-cron-dot <?php echo $cron_paused ? 'paused' : 'active'; ?>"></span>
                    <strong><?php echo $cron_paused ? 'Paused' : 'Active'; ?></strong>
                </div>

                <form method="POST">
                    <?php wp_nonce_field($nonce_key); ?>
                    <input type="hidden" name="async_action" value="toggle_cron">
                    <?php if ($cron_paused): ?>
                    <button type="submit" class="button button-primary">&#9654;&nbsp; Resume Worker</button>
                    <p class="description" style="margin-top:6px;">The queue worker is paused. Jobs accumulate but are not processed automatically.</p>
                    <?php else: ?>
                    <button type="submit" class="button">&#9646;&#9646;&nbsp; Pause Worker</button>
                    <p class="description" style="margin-top:6px;">Pause to temporarily stop automatic queue processing without unscheduling the cron event.</p>
                    <?php endif; ?>
                </form>

                <div class="lat-cron-next">
                    <p class="lat-cron-next-label">Next scheduled run</p>
                    <?php if ($next_run): ?>
                        <code><?php echo esc_html(wp_date('Y-m-d H:i:s', $next_run)); ?></code>
                        <span style="color:#646970; font-size:12px; margin-left:4px;">(server time)</span>
                    <?php else: ?>
                        <em style="color:#d63638;">Not scheduled &mdash; WP-Cron may be disabled.</em>
                    <?php endif; ?>
                </div>
            </div><!-- .lat-cron-panel -->

            <!-- Right panel: manual Linux cron -->
            <div class="lat-cron-panel">
                <p class="lat-cron-panel-title">Manual Linux Cron</p>
                <textarea readonly
                          rows="3"
                          class="code"
                          onclick="this.select()"
                          style="cursor:text;"
                ><?php echo esc_textarea($curl_cmd); ?></textarea>
                <p class="description" style="margin-top:10px;">
                    Add this line to your server's <code>crontab</code> (via <code>crontab -e</code>) to trigger the
                    queue worker every minute without relying on WP-Cron.<br>
                    Useful when <code>DISABLE_WP_CRON</code> is set to <code>true</code> in <code>wp-config.php</code>.
                </p>
            </div><!-- .lat-cron-panel -->

        </div><!-- .lat-cron-grid -->

        <!-- Worker parameters -->
        <br>
        <div class="lat-cron-panel">
            <div style="margin-top:20px; padding-top:0px; border-top:0px solid #dcdcde;">
                <p class="lat-cron-panel-title" style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:#646970; margin:0 0 16px;">Worker Parameters</p>
                <form method="POST" style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-end;">
                    <?php wp_nonce_field($nonce_key); ?>
                    <input type="hidden" name="async_action" value="save_worker_settings">
                    <label style="display:flex; flex-direction:column; gap:4px; font-size:13px; font-weight:600;">
                        Batch Size
                        <input type="number" name="batch_size" value="<?php echo esc_attr($opt_batch); ?>"
                            min="1" max="500" style="width:100px;">
                        <span class="description" style="font-weight:400;">Jobs processed per run.</span>
                    </label>
                    <label style="display:flex; flex-direction:column; gap:4px; font-size:13px; font-weight:600;">
                        Max Runtime (seconds)
                        <input type="number" name="max_runtime" value="<?php echo esc_attr($opt_runtime); ?>"
                            min="1" max="300" style="width:100px;">
                        <span class="description" style="font-weight:400;">Stop processing after this many seconds.</span>
                    </label>
                    <button type="submit" class="button button-primary">Save Settings</button>
                </form>
            </div>
        </div><!-- .lat-cron-panel -->

    </div><!-- .lat-cron-box -->

</div><!-- .wrap.lat-qm -->

<script>
(function () {
    'use strict';

    var form       = document.getElementById('lat-queue-form');
    var actInput   = document.getElementById('lat-form-action');
    var jobInput   = document.getElementById('lat-form-job-id');
    var statInput  = document.getElementById('lat-form-new-status');

    /* Select-all checkboxes */
    ['lat-check-all', 'lat-check-all-foot'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', function () {
            document.querySelectorAll('input[name="job_ids[]"]').forEach(function (cb) {
                cb.checked = el.checked;
            });
        });
    });

    /* Keep both select-all in sync */
    document.querySelectorAll('input[name="job_ids[]"]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var all   = document.querySelectorAll('input[name="job_ids[]"]').length;
            var chkd  = document.querySelectorAll('input[name="job_ids[]"]:checked').length;
            ['lat-check-all', 'lat-check-all-foot'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.checked = (all === chkd);
            });
        });
    });

    /* Change individual status */
    window.latChangeStatus = function (id) {
        var sel = document.getElementById('lat-sel-' + id);
        if (!sel) return;
        actInput.value  = 'change_status';
        jobInput.value  = id;
        statInput.value = sel.value;
        form.submit();
    };

    /* Delete individual job */
    window.latDeleteJob = function (id) {
        if (!window.confirm('Delete job #' + id + '? This cannot be undone.')) return;
        actInput.value = 'delete';
        jobInput.value = id;
        form.submit();
    };

    /* Confirm bulk delete */
    window.latConfirmBulk = function () {
        var checked = document.querySelectorAll('input[name="job_ids[]"]:checked');
        if (checked.length === 0) {
            alert('Please select at least one job.');
            return false;
        }
        return window.confirm('Delete ' + checked.length + ' job(s)? This cannot be undone.');
    };

    /* Confirm bulk process */
    window.latConfirmBulkProcess = function () {
        var checked = document.querySelectorAll('input[name="job_ids[]"]:checked');
        if (checked.length === 0) {
            alert('Please select at least one job.');
            return false;
        }
        return window.confirm('Process ' + checked.length + ' job(s) immediately?');
    };

    /* Confirm bulk status change */
    window.latConfirmBulkStatus = function () {
        var checked = document.querySelectorAll('input[name="job_ids[]"]:checked');
        if (checked.length === 0) {
            alert('Please select at least one job.');
            return false;
        }
        var status = document.getElementById('lat-bulk-status-val').value;
        if (!status) {
            alert('Please choose a target status from the dropdown.');
            return false;
        }
        return window.confirm('Change ' + checked.length + ' job(s) to "' + status + '"?');
    };

    /* Sync all status selects → hidden input */
    document.querySelectorAll('.lat-bulk-status-sel').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var val = sel.value;
            document.querySelectorAll('.lat-bulk-status-sel').forEach(function (s) { s.value = val; });
            document.getElementById('lat-bulk-status-val').value = val;
        });
    });
}());
</script>
