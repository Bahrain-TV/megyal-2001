<?php

/*
 * JSON API for scores and round state.
 * Used by external tools (OBS browser sources, custom overlays, bots).
 *
 * GET /api.php              -> full state
 * GET /api.php?top=5        -> top N players only
 * GET /api.php?user=name    -> single user lookup
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$scoresFile = __DIR__ . '/../storage/scores.json';
$roundFile  = __DIR__ . '/../storage/round.json';

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

$scores = readJSON($scoresFile);
arsort($scores);

$round = readJSON($roundFile);

// Single user lookup
if (isset($_GET['user'])) {
    $user = trim($_GET['user']);
    $userScore = $scores[$user] ?? null;

    if ($userScore === null) {
        http_response_code(404);
        echo json_encode(['error' => 'user_not_found', 'user' => $user]);
        exit;
    }

    // Determine rank
    $rank = 1;
    foreach ($scores as $name => $s) {
        if ($name === $user) break;
        $rank++;
    }

    echo json_encode([
        'user'  => $user,
        'score' => $userScore,
        'rank'  => $rank,
        'total_players' => count($scores),
    ]);
    exit;
}

// Top N filter
$top = isset($_GET['top']) ? max(1, (int)$_GET['top']) : null;
if ($top !== null) {
    $scores = array_slice($scores, 0, $top, true);
}

// Build leaderboard array
$leaderboard = [];
$rank = 0;
foreach ($scores as $user => $score) {
    $rank++;
    $leaderboard[] = [
        'rank'  => $rank,
        'user'  => $user,
        'score' => $score,
    ];
}

echo json_encode([
    'round' => [
        'ended'    => !empty($round['ended']),
        'ended_at' => $round['ended_at'] ?? null,
    ],
    'total_players' => count(readJSON($scoresFile)),
    'leaderboard'   => $leaderboard,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
