<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Live Leaderboard Overlay</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/TextPlugin.min.js"></script>
<style>
* { margin:0; padding:0; box-sizing:border-box; }

body {
    background: #000;
    color: #e6e6e6;
    font-family: 'Segoe UI', system-ui, sans-serif;
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    overflow: hidden;
}

/* Animated gradient background */
.bg {
    position: fixed;
    inset: 0;
    background: linear-gradient(135deg, #0a0a1a 0%, #0d1117 40%, #0a192f 100%);
    z-index: 0;
}
.bg::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 50% 0%, rgba(88,166,255,0.08) 0%, transparent 60%);
}

#container {
    position: relative;
    z-index: 1;
    text-align: center;
    width: 90%;
    max-width: 700px;
}

#title {
    font-size: clamp(28px, 5vw, 52px);
    font-weight: 800;
    background: linear-gradient(135deg, #58a6ff, #3fb950, #f0c040);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 10px;
    opacity: 0;
}

#subtitle {
    font-size: clamp(14px, 2vw, 18px);
    color: #8b949e;
    margin-bottom: 40px;
    opacity: 0;
}

.entry {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 24px;
    margin: 8px 0;
    border-radius: 12px;
    background: rgba(22, 27, 34, 0.85);
    border: 1px solid #30363d;
    opacity: 0;
    transform: translateY(20px);
}

.entry.top-1 {
    border-color: #f0c040;
    background: linear-gradient(135deg, rgba(240,192,64,0.12), rgba(22,27,34,0.9));
    box-shadow: 0 0 20px rgba(240,192,64,0.1);
}
.entry.top-2 {
    border-color: #8b949e;
    background: linear-gradient(135deg, rgba(139,148,158,0.1), rgba(22,27,34,0.9));
}
.entry.top-3 {
    border-color: #da7e37;
    background: linear-gradient(135deg, rgba(218,126,55,0.1), rgba(22,27,34,0.9));
}

.entry-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.rank-badge {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 16px;
    background: #21262d;
    color: #8b949e;
    border: 2px solid #30363d;
    flex-shrink: 0;
}
.top-1 .rank-badge { background: #f0c040; color: #1a1a2e; border-color: #f0c040; }
.top-2 .rank-badge { background: #8b949e; color: #0d1117; border-color: #8b949e; }
.top-3 .rank-badge { background: #da7e37; color: #0d1117; border-color: #da7e37; }

.username {
    font-weight: 700;
    font-size: clamp(16px, 2.5vw, 22px);
    color: #e6e6e6;
}

.score {
    font-weight: 800;
    font-size: clamp(20px, 3vw, 28px);
    color: #58a6ff;
}
.top-1 .score { color: #f0c040; }

.pts-label {
    font-size: 13px;
    color: #8b949e;
    margin-left: 4px;
    font-weight: 400;
}

#waiting {
    color: #8b949e;
    font-size: 20px;
    opacity: 0;
}

.medal { font-size: 20px; }
</style>
</head>
<body>

<div class="bg"></div>

<div id="container">
    <div id="title">Leaderboard</div>
    <div id="subtitle">Final Results</div>
    <div id="board"></div>
    <div id="waiting">Waiting for round to end...</div>
</div>

<script>
gsap.registerPlugin(TextPlugin);

let lastState = null;
let animating = false;

const medals = ['', '', ''];

async function fetchJSON(url) {
    const res = await fetch(url + '?t=' + Date.now());
    if (!res.ok) return null;
    return res.json();
}

async function loadBoard() {
    if (animating) return;

    const round = await fetchJSON('../storage/round.json');
    if (!round) return;

    const board = document.getElementById('board');
    const waiting = document.getElementById('waiting');
    const title = document.getElementById('title');
    const subtitle = document.getElementById('subtitle');

    if (!round.ended) {
        if (lastState !== 'waiting') {
            board.innerHTML = '';
            gsap.to(waiting, { opacity: 1, duration: 0.5 });
            gsap.to(title, { opacity: 1, duration: 0.5 });
            gsap.to(subtitle, { opacity: 0, duration: 0.3 });
            lastState = 'waiting';
        }
        return;
    }

    const scores = await fetchJSON('../storage/scores.json');
    if (!scores) return;

    const sorted = Object.entries(scores).sort((a, b) => b[1] - a[1]);

    if (JSON.stringify(sorted) === JSON.stringify(lastState)) return;

    animating = true;
    lastState = sorted;

    gsap.to(waiting, { opacity: 0, duration: 0.3 });
    board.innerHTML = '';

    // Animate title
    gsap.fromTo(title, { opacity: 0, y: -20 }, { opacity: 1, y: 0, duration: 0.6, ease: 'power3.out' });
    gsap.fromTo(subtitle, { opacity: 0 }, { opacity: 1, duration: 0.6, delay: 0.3 });

    sorted.forEach((entry, i) => {
        const div = document.createElement('div');
        div.className = 'entry' + (i < 3 ? ' top-' + (i + 1) : '');

        const medal = medals[i] || '';
        const rankText = medal || (i + 1);

        div.innerHTML = `
            <div class="entry-left">
                <div class="rank-badge">${rankText}</div>
                <div class="username">${escapeHTML(entry[0])}</div>
            </div>
            <div>
                <span class="score">${entry[1]}</span>
                <span class="pts-label">pts</span>
            </div>
        `;

        board.appendChild(div);

        gsap.fromTo(div,
            { opacity: 0, y: 30, scale: 0.95 },
            {
                opacity: 1, y: 0, scale: 1,
                duration: 0.6,
                delay: 0.5 + i * 0.15,
                ease: 'back.out(1.2)',
                onComplete: () => { if (i === sorted.length - 1) animating = false; }
            }
        );
    });

    if (sorted.length === 0) {
        board.innerHTML = '<div style="color:#8b949e; padding:40px;">No scores recorded.</div>';
        animating = false;
    }
}

function escapeHTML(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Initial load + poll
loadBoard();
setInterval(loadBoard, 2000);
</script>

</body>
</html>
