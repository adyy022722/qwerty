<?php
session_start();

if (!isset($_SESSION['tasks'])) $_SESSION['tasks'] = [];
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

const PRIORITIES = ['low' => 1, 'medium' => 2, 'high' => 3];

function csrf(): string {
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function cleanDate(string $d): string {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
}

function addTask(array $in, array &$tasks): bool {
    $text = trim($in['task'] ?? '');
    if ($text === '') return false;
    $priority = $in['priority'] ?? 'medium';
    $tasks[] = [
        'id' => uniqid('task_', true),
        'text' => $text,
        'subject' => trim($in['subject'] ?? ''),
        'priority' => isset(PRIORITIES[$priority]) ? $priority : 'medium',
        'due' => cleanDate($in['due'] ?? ''),
        'completed' => false,
        'created' => time()
    ];
    return true;
}

function updateTask(string $id, array $in, array &$tasks): bool {
    $text = trim($in['task'] ?? '');
    if ($text === '') return false;
    $priority = $in['priority'] ?? 'medium';
    foreach ($tasks as &$task) {
        if ($task['id'] === $id) {
            $task['text'] = $text;
            $task['subject'] = trim($in['subject'] ?? '');
            $task['priority'] = isset(PRIORITIES[$priority]) ? $priority : 'medium';
            $task['due'] = cleanDate($in['due'] ?? '');
            return true;
        }
    }
    return false;
}

function toggleTask(string $id, array &$tasks): void {
    foreach ($tasks as &$task) {
        if ($task['id'] === $id) {
            $task['completed'] = !$task['completed'];
            return;
        }
    }
}

function deleteTask(string $id, array &$tasks): void {
    foreach ($tasks as $index => $task) {
        if ($task['id'] === $id) {
            array_splice($tasks, $index, 1);
            return;
        }
    }
}

function findTask(string $id, array $tasks): ?array {
    foreach ($tasks as $task) {
        if ($task['id'] === $id) return $task;
    }
    return null;
}

function isOverdue(array $t): bool {
    return !$t['completed'] && $t['due'] !== '' && $t['due'] < date('Y-m-d');
}

function link_to(array $over = []): string {
    $q = array_merge(['filter' => $_GET['filter'] ?? 'all', 'q' => $_GET['q'] ?? '', 'sort' => $_GET['sort'] ?? 'newest'], $over);
    return 'index.php?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== 'all' && $v !== 'newest'));
}

$editingTask = null;
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $action = $_POST['action'] ?? 'add';
    $tasks = &$_SESSION['tasks'];

    switch ($action) {
        case 'add':
            if (!addTask($_POST, $tasks)) $_SESSION['error'] = 'Task cannot be empty.';
            break;
        case 'update':
            if (!updateTask($_POST['id'] ?? '', $_POST, $tasks)) $_SESSION['error'] = 'Task cannot be empty.';
            break;
        case 'toggle':
            toggleTask($_POST['id'] ?? '', $tasks);
            break;
        case 'delete':
            deleteTask($_POST['id'] ?? '', $tasks);
            break;
        case 'complete_all':
            foreach ($tasks as &$t) $t['completed'] = true;
            unset($t);
            break;
        case 'clear_completed':
            $tasks = array_values(array_filter($tasks, fn($t) => !$t['completed']));
            break;
    }

    // Keep the current filter, search and sort after any action
    parse_str($_SERVER['QUERY_STRING'] ?? '', $qs);
    unset($qs['edit']);
    header('Location: index.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

if (isset($_GET['edit'])) {
    $editingTask = findTask($_GET['edit'], $_SESSION['tasks']);
}

$all = $_SESSION['tasks'];
$total = count($all);
$done = count(array_filter($all, fn($t) => $t['completed']));
$pending = $total - $done;
$overdue = count(array_filter($all, 'isOverdue'));
$progress = $total > 0 ? round(($done / $total) * 100) : 0;

// Filter, search, sort
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'newest';

$tasks = array_values(array_filter($all, function ($t) use ($filter, $search) {
    if ($filter === 'pending' && $t['completed']) return false;
    if ($filter === 'done' && !$t['completed']) return false;
    if ($filter === 'overdue' && !isOverdue($t)) return false;
    if ($search !== '' && stripos($t['text'] . ' ' . $t['subject'], $search) === false) return false;
    return true;
}));

usort($tasks, function ($a, $b) use ($sort) {
    if ($sort === 'due') {
        $x = $a['due'] ?: '9999-12-31';
        $y = $b['due'] ?: '9999-12-31';
        return $x <=> $y;
    }
    if ($sort === 'priority') return PRIORITIES[$b['priority']] <=> PRIORITIES[$a['priority']];
    if ($sort === 'az') return strcasecmp($a['text'], $b['text']);
    return $b['created'] <=> $a['created'];
});

$v = $editingTask ?? ['text' => '', 'subject' => '', 'priority' => 'medium', 'due' => ''];
$formQuery = $_SERVER['QUERY_STRING'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Dashboard</title>

    <style>
        :root {
            --primary: #1B2A4A;
            --secondary: #4A5670;
            --paper: #F4EFE3;
            --card: #FFFDF8;
            --border: #C9BFA5;
            --red: #A13D2C;
            --green: #5B7B54;
            --amber: #B7791F;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 30px 20px;
            background: var(--paper);
            color: var(--primary);
            font-family: Arial, sans-serif;
        }

        .dashboard { max-width: 900px; margin: auto; }
        header { margin-bottom: 30px; }
        .kicker { color: var(--red); font-size: 13px; font-weight: bold; margin-bottom: 5px; }
        h1 { margin: 0; font-size: 34px; }
        .subtitle { color: var(--secondary); margin-top: 8px; }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 8px rgba(0,0,0,0.05);
        }

        .stat-card h3 { margin: 0; font-size: 14px; color: var(--secondary); }
        .stat-number { font-size: 30px; font-weight: bold; margin-top: 8px; }
        .stat-card.warn .stat-number { color: var(--red); }

        .progress-section {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .progress-header { display: flex; justify-content: space-between; margin-bottom: 10px; font-weight: bold; }
        .progress-bar { height: 12px; background: #E2DDCF; border-radius: 20px; overflow: hidden; }
        .progress-fill { height: 100%; width: <?= $progress ?>%; background: var(--green); border-radius: 20px; }

        .task-container {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 25px;
        }

        .task-container h2 { margin-top: 0; }

        .task-form {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 25px;
        }

        .task-form .wide { grid-column: 1 / -1; }
        .form-actions { grid-column: 1 / -1; display: flex; gap: 10px; }

        input[type=text], input[type=date], input[type=search], select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 15px;
            background: white;
            color: var(--primary);
        }

        button, .btn-cancel {
            border: none;
            border-radius: 6px;
            padding: 12px 18px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-cancel { background: #ddd; color: var(--primary); }
        .btn-light { background: #E9E3D2; color: var(--primary); padding: 8px 12px; font-size: 13px; }

        .toolbar { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
        .toolbar input[type=search] { flex: 1; min-width: 160px; padding: 10px; }
        .toolbar select { width: auto; padding: 10px; }

        .tabs { display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
        .tab {
            padding: 6px 14px;
            border: 1px solid var(--border);
            border-radius: 20px;
            font-size: 13px;
            color: var(--secondary);
            text-decoration: none;
        }
        .tab.active { background: var(--primary); border-color: var(--primary); color: white; }

        .task-list { list-style: none; padding: 0; margin: 0; }

        .task-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 0;
            border-bottom: 1px solid var(--border);
        }

        .task-row:last-child { border-bottom: none; }
        .toggle-form, .delete-form, .bulk-form { margin: 0; }

        .check-btn {
            width: 25px;
            height: 25px;
            padding: 0;
            border: 2px solid var(--secondary);
            border-radius: 50%;
            background: white;
            color: white;
        }

        .completed .check-btn { background: var(--green); border-color: var(--green); }

        .task-main { flex: 1; }
        .completed .task-text { text-decoration: line-through; color: var(--secondary); }

        .meta { display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap; font-size: 12px; }
        .badge { padding: 2px 8px; border-radius: 10px; background: #E9E3D2; color: var(--secondary); }
        .badge.high { background: #F6E2DC; color: var(--red); }
        .badge.medium { background: #F7EBCF; color: var(--amber); }
        .badge.low { background: #E1EBDD; color: var(--green); }
        .due { color: var(--secondary); padding: 2px 0; }
        .due.late { color: var(--red); font-weight: bold; }

        .actions { display: flex; gap: 10px; align-items: center; }
        .edit { color: var(--secondary); text-decoration: none; font-size: 13px; }
        .delete { background: none; color: var(--red); padding: 0; font-size: 13px; }

        .bulk { display: flex; gap: 10px; margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border); flex-wrap: wrap; }

        .empty { text-align: center; padding: 25px; color: var(--secondary); }
        .error { background: #F6E2DC; color: var(--red); padding: 10px; border-radius: 6px; margin-bottom: 15px; }

        @media (max-width: 600px) {
            .stats { grid-template-columns: 1fr 1fr; }
            .task-form { grid-template-columns: 1fr; }
            .task-row { flex-wrap: wrap; }
            .actions { width: 100%; margin-left: 37px; }
        }
    </style>
</head>
<body>

<div class="dashboard">

    <header>
        <p class="kicker">SEMESTER PLANNER</p>
        <h1>Student Task Dashboard</h1>
        <p class="subtitle">Manage your school tasks and track your progress.</p>
    </header>

    <section class="stats">
        <div class="stat-card"><h3>Total Tasks</h3><div class="stat-number"><?= $total ?></div></div>
        <div class="stat-card"><h3>Completed</h3><div class="stat-number"><?= $done ?></div></div>
        <div class="stat-card"><h3>Pending</h3><div class="stat-number"><?= $pending ?></div></div>
        <div class="stat-card <?= $overdue ? 'warn' : '' ?>"><h3>Overdue</h3><div class="stat-number"><?= $overdue ?></div></div>
    </section>

    <section class="progress-section">
        <div class="progress-header">
            <span>Overall Progress</span>
            <span><?= $progress ?>%</span>
        </div>
        <div class="progress-bar"><div class="progress-fill"></div></div>
    </section>

    <section class="task-container">
        <h2><?= $editingTask ? 'Edit Task' : 'My Tasks' ?></h2>

        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST" class="task-form">
            <?= csrf() ?>
            <input type="hidden" name="action" value="<?= $editingTask ? 'update' : 'add' ?>">
            <?php if ($editingTask): ?>
                <input type="hidden" name="id" value="<?= htmlspecialchars($editingTask['id']) ?>">
            <?php endif; ?>

            <input class="wide" type="text" name="task" value="<?= htmlspecialchars($v['text']) ?>" placeholder="What do you need to get done?" required>
            <input type="text" name="subject" value="<?= htmlspecialchars($v['subject']) ?>" placeholder="Subject (e.g. Math)" maxlength="30">
            <select name="priority" aria-label="Priority">
                <?php foreach (array_keys(PRIORITIES) as $p): ?>
                    <option value="<?= $p ?>" <?= $v['priority'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?> priority</option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="due" value="<?= htmlspecialchars($v['due']) ?>" aria-label="Due date">

            <div class="form-actions">
                <button type="submit" class="btn-primary"><?= $editingTask ? 'Save' : 'Add Task' ?></button>
                <?php if ($editingTask): ?><a href="<?= link_to() ?>" class="btn-cancel">Cancel</a><?php endif; ?>
            </div>
        </form>

        <div class="tabs">
            <?php foreach (['all' => 'All', 'pending' => 'Pending', 'done' => 'Completed', 'overdue' => 'Overdue'] as $key => $label): ?>
                <a class="tab <?= $filter === $key ? 'active' : '' ?>" href="<?= link_to(['filter' => $key]) ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <form method="GET" class="toolbar">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search tasks or subjects">
            <select name="sort" aria-label="Sort tasks" onchange="this.form.submit()">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                <option value="due" <?= $sort === 'due' ? 'selected' : '' ?>>Due date</option>
                <option value="priority" <?= $sort === 'priority' ? 'selected' : '' ?>>Priority</option>
                <option value="az" <?= $sort === 'az' ? 'selected' : '' ?>>A to Z</option>
            </select>
            <button type="submit" class="btn-light">Search</button>
        </form>

        <ul class="task-list">
            <?php if (empty($tasks)): ?>
                <li class="empty"><?= $total ? 'No tasks match this view.' : 'No tasks yet. Add your first task above.' ?></li>
            <?php endif; ?>

            <?php foreach ($tasks as $task): ?>
                <li class="task-row <?= $task['completed'] ? 'completed' : '' ?>">

                    <form method="POST" class="toggle-form">
                        <?= csrf() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($task['id']) ?>">
                        <button type="submit" class="check-btn" aria-label="Toggle complete"><?= $task['completed'] ? '✓' : '' ?></button>
                    </form>

                    <div class="task-main">
                        <span class="task-text"><?= htmlspecialchars($task['text']) ?></span>
                        <div class="meta">
                            <span class="badge <?= $task['priority'] ?>"><?= ucfirst($task['priority']) ?></span>
                            <?php if ($task['subject'] !== ''): ?>
                                <span class="badge"><?= htmlspecialchars($task['subject']) ?></span>
                            <?php endif; ?>
                            <?php if ($task['due'] !== ''): ?>
                                <span class="due <?= isOverdue($task) ? 'late' : '' ?>">
                                    <?= isOverdue($task) ? 'Overdue: ' : ($task['due'] === date('Y-m-d') ? 'Due today: ' : 'Due: ') ?>
                                    <?= date('M j, Y', strtotime($task['due'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="actions">
                        <a class="edit" href="<?= link_to(['edit' => $task['id']]) ?>">Edit</a>

                        <form method="POST" class="delete-form" onsubmit="return confirm('Delete this task?');">
                            <?= csrf() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($task['id']) ?>">
                            <button type="submit" class="delete">Delete</button>
                        </form>
                    </div>

                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($total): ?>
            <div class="bulk">
                <?php if ($pending): ?>
                    <form method="POST" class="bulk-form">
                        <?= csrf() ?>
                        <input type="hidden" name="action" value="complete_all">
                        <button type="submit" class="btn-light">Mark all as done</button>
                    </form>
                <?php endif; ?>
                <?php if ($done): ?>
                    <form method="POST" class="bulk-form" onsubmit="return confirm('Remove all completed tasks?');">
                        <?= csrf() ?>
                        <input type="hidden" name="action" value="clear_completed">
                        <button type="submit" class="btn-light">Clear completed</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

</div>

</body>
</html>
