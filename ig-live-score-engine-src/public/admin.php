<?php

/*
 * Admin Leaderboard Panel
 * -----------------------
 * Provides score overview, round control (end / reset), and per-user
 * score adjustment.  All file writes use flock() for safe concurrency.
 */

$scoresFile = __DIR__ . '/../storage/scores.json';
$roundFile  = __DIR__ . '/../storage/round.json';

// ---------- CSRF token ----------
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

function verifyCSRF(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

// ---------- File helpers with locking ----------
function readJSON(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $fp = fopen($path, 'r');
    flock($fp, LOCK_SH);
    $data = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

function writeJSON(string $path, array $data): void
{
    $fp = fopen($path, 'c');
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();

    if (isset($_POST['end'])) {
        writeJSON($roundFile, ['ended' => true, 'ended_at' => date('c')]);
    }

    if (isset($_POST['reset'])) {
        writeJSON($scoresFile, []);
        writeJSON($roundFile, ['ended' => false]);
    }

    if (isset($_POST['adjust_user'], $_POST['adjust_delta'])) {
        $user  = trim($_POST['adjust_user']);
        $delta = (int) $_POST['adjust_delta'];
        if ($user !== '' && $delta !== 0) {
            $scores = readJSON($scoresFile);
            $scores[$user] = max(0, ($scores[$user] ?? 0) + $delta);
            if ($scores[$user] === 0) {
                unset($scores[$user]);
            }
            writeJSON($scoresFile, $scores);
        }
    }

    if (isset($_POST['remove_user'])) {
        $user = trim($_POST['remove_user']);
        if ($user !== '') {
            $scores = readJSON($scoresFile);
            unset($scores[$user]);
            writeJSON($scoresFile, $scores);
        }
    }

    header('Location: admin.php');
    exit;
}

// ---------- Read current state ----------
$scores = readJSON($scoresFile);
arsort($scores);

$round = readJSON($roundFile);
$isEnded = !empty($round['ended']);
$totalPlayers = count($scores);
$totalPoints  = array_sum($scores);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin - Live Score Engine</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { background:#0d1117; color:#c9d1d9; font-family:'Segoe UI',system-ui,sans-serif; padding:24px; }

h1 { color:#58a6ff; font-size:28px; margin-bottom:6px; }
.subtitle { color:#8b949e; margin-bottom:24px; }

.stats {
    display:flex; gap:16px; margin-bottom:24px; flex-wrap:wrap;
}
.stat-card {
    background:#161b22; border:1px solid #30363d; border-radius:8px;
    padding:16px 24px; min-width:140px;
}
.stat-card .value { font-size:32px; font-weight:700; color:#58a6ff; }
.stat-card .label { font-size:13px; color:#8b949e; margin-top:4px; }

.status-badge {
    display:inline-block; padding:4px 12px; border-radius:20px;
    font-weight:600; font-size:13px;
}
.status-running { background:#0d4429; color:#3fb950; border:1px solid #238636; }
.status-ended   { background:#490c0c; color:#f85149; border:1px solid #da3633; }

table { width:100%; border-collapse:collapse; margin-bottom:24px; }
th { text-align:left; padding:10px 12px; color:#8b949e; border-bottom:2px solid #30363d; font-size:13px; text-transform:uppercase; }
td { padding:10px 12px; border-bottom:1px solid #21262d; }
tr:hover td { background:#161b22; }
.rank { color:#8b949e; font-weight:600; width:40px; }
.user { font-weight:600; color:#c9d1d9; }
.score-val { color:#58a6ff; font-weight:700; font-size:18px; }

.actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:24px; }
button, .btn {
    padding:10px 20px; border:1px solid #30363d; border-radius:6px;
    font-size:14px; font-weight:600; cursor:pointer; background:#21262d; color:#c9d1d9;
    transition: background 0.15s;
}
button:hover, .btn:hover { background:#30363d; }
.btn-danger { background:#490c0c; color:#f85149; border-color:#da3633; }
.btn-danger:hover { background:#6e1010; }
.btn-warn { background:#4a3000; color:#d29922; border-color:#9e6a03; }
.btn-warn:hover { background:#5c3d00; }

.inline-form { display:inline; }
.small-btn {
    padding:4px 10px; font-size:12px; border-radius:4px;
    cursor:pointer; border:1px solid #30363d; background:#21262d; color:#c9d1d9;
}
.small-btn:hover { background:#30363d; }
.small-btn.remove { color:#f85149; }

.empty { color:#8b949e; text-align:center; padding:40px; }
</style>
</head>
<body>

<h1>Live Score Engine - Admin</h1>
<p class="subtitle">
    <span class="status-badge <?= $isEnded ? 'status-ended' : 'status-running' ?>">
        <?= $isEnded ? 'ROUND ENDED' : 'ROUND ACTIVE' ?>
    </span>
    <?php if (!empty($round['ended_at'])): ?>
        <span style="margin-left:8px; color:#8b949e; font-size:13px;">
            ended <?= htmlspecialchars($round['ended_at']) ?>
        </span>
    <?php endif; ?>
</p>

<div class="stats">
    <div class="stat-card">
        <div class="value"><?= $totalPlayers ?></div>
        <div class="label">Players</div>
    </div>
    <div class="stat-card">
        <div class="value"><?= $totalPoints ?></div>
        <div class="label">Total Points</div>
    </div>
    <div class="stat-card">
        <div class="value"><?= $totalPlayers > 0 ? round($totalPoints / $totalPlayers, 1) : 0 ?></div>
        <div class="label">Avg Score</div>
    </div>
</div>

<div class="actions">
    <form method="post" class="inline-form">
        <input type="hidden" name="_token" value="<?= $csrf ?>">
        <button name="end" class="btn-warn" onclick="return confirm('End this round?')">End Round</button>
    </form>
    <form method="post" class="inline-form">
        <input type="hidden" name="_token" value="<?= $csrf ?>">
        <button name="reset" class="btn-danger" onclick="return confirm('Reset all scores? This cannot be undone.')">Reset Scores</button>
    </form>
</div>

<?php if (empty($scores)): ?>
    <div class="empty">No scores yet. Waiting for players...</div>
<?php else: ?>
<table>
<thead>
    <tr><th>#</th><th>User</th><th>Score</th><th>Actions</th></tr>
</thead>
<tbody>
<?php $rank = 0; foreach ($scores as $user => $score): $rank++; ?>
<tr>
    <td class="rank"><?= $rank ?></td>
    <td class="user"><?= htmlspecialchars($user) ?></td>
    <td class="score-val"><?= (int)$score ?></td>
    <td>
        <form method="post" class="inline-form">
            <input type="hidden" name="_token" value="<?= $csrf ?>">
            <input type="hidden" name="adjust_user" value="<?= htmlspecialchars($user) ?>">
            <input type="hidden" name="adjust_delta" value="1">
            <button class="small-btn" title="Add 1 point">+1</button>
        </form>
        <form method="post" class="inline-form">
            <input type="hidden" name="_token" value="<?= $csrf ?>">
            <input type="hidden" name="adjust_user" value="<?= htmlspecialchars($user) ?>">
            <input type="hidden" name="adjust_delta" value="-1">
            <button class="small-btn" title="Remove 1 point">-1</button>
        </form>
        <form method="post" class="inline-form">
            <input type="hidden" name="_token" value="<?= $csrf ?>">
            <input type="hidden" name="remove_user" value="<?= htmlspecialchars($user) ?>">
            <button class="small-btn remove" title="Remove player" onclick="return confirm('Remove <?= htmlspecialchars($user) ?>?')">x</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<script>
// Auto-refresh every 3 seconds
setTimeout(() => location.reload(), 3000);
</script>

</body>
</html>
