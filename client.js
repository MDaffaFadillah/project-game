const SERVER_URL = 'wss://project-game-production-ea26.up.railway.app';

let socket;
let myUsername   = "Player";
let startTime    = 0;
let isGameActive = false;
let currentRound = 1;
let maxRounds    = 5;
let reactionHistory = [];
let roundResults    = [];

const lobbyScreen    = document.getElementById('lobby-screen');
const gameScreen     = document.getElementById('game-screen');
const gameArea       = document.getElementById('game-area');
const gameMessage    = document.getElementById('game-message');
const usernameInput  = document.getElementById('username-input');
const joinBtn        = document.getElementById('join-btn');
const playerListUI   = document.getElementById('players');
const statusText     = document.getElementById('status-text');
const roundIndicator = document.getElementById('round-indicator');

const statAvg         = document.getElementById('stat-avg');
const statConsistency = document.getElementById('stat-consistency');
const statBest        = document.getElementById('stat-best');

// ─── Koneksi WebSocket ───────────────────────────────────────────────────────

function connectToServer() {
    socket = new WebSocket(SERVER_URL);

    socket.onopen = () => {
        console.log("Terhubung ke server!");
        statusText.textContent = "Terhubung! Menunggu pemain lain...";
        socket.send(JSON.stringify({
            type: 'JOIN',
            username: myUsername
        }));
    };

    socket.onmessage = (event) => {
        handleServerMessage(event.data);
    };

    socket.onerror = (error) => {
        console.error("WebSocket Error: ", error);
        statusText.textContent = "Gagal konek ke server. Mode Offline tersedia.";
        joinBtn.textContent = "Join Room";
        joinBtn.disabled    = false;
    };

    socket.onclose = () => {
        console.log("Koneksi terputus");
        statusText.textContent = "Koneksi terputus. Silakan refresh.";
    };
}

// ─── Router Pesan dari Server ────────────────────────────────────────────────

function handleServerMessage(data) {
    const message = JSON.parse(data);

    switch (message.type) {

        case 'PLAYER_LIST':
            updatePlayerList(message.players);
            break;

        case 'START_GAME':
            showGameScreen();
            break;

        case 'WAIT':
            setWaitState();
            break;

        case 'GO':
            setGoState();
            startTime = performance.now();
            break;

        case 'RESULT':
            showResult(message.winner, message.time);
            break;

        case 'TOO_EARLY':
            showTooEarly(message.culprit);
            break;

        case 'ROUND_UPDATE':
            currentRound = message.round;
            updateRoundIndicator();
            break;

        case 'GAME_OVER':
            showFinalStats(message.stats);
            break;

        case 'ERROR':
            alert(`Server: ${message.message}`);
            joinBtn.textContent = "Join Room";
            joinBtn.disabled    = false;
            break;

        case 'SYSTEM':
            statusText.textContent = message.message;
            break;

        default:
            console.warn("Pesan tidak dikenal:", message.type);
    }
}

// ─── UI State ────────────────────────────────────────────────────────────────

function showGameScreen() {
    lobbyScreen.classList.add('hidden');
    gameScreen.classList.remove('hidden');
    setWaitState();
    currentRound    = 1;
    reactionHistory = [];
    roundResults    = [];
    updateRoundIndicator();
    updateStats();
}

function updatePlayerList(players) {
    playerListUI.innerHTML = '';
    players.forEach(p => {
        const li    = document.createElement('li');
        const name  = p.name  ?? p;
        const score = p.score ?? null;
        li.textContent = score !== null ? `${name}  —  ${score} pts` : name;
        if (p.ready) li.classList.add('ready');
        playerListUI.appendChild(li);
    });
}

function setWaitState() {
    isGameActive            = false;
    gameArea.className      = 'state-wait';
    gameMessage.textContent = "WAIT...";
}

function setGoState() {
    isGameActive            = true;
    gameArea.className      = 'state-go';
    gameMessage.textContent = "CLICK!";
}

// FIX: updateStats() hanya dipanggil saat menang (reactionHistory terisi)
function showResult(winner, time) {
    isGameActive       = false;
    gameArea.className = 'state-result';

    if (winner === myUsername) {
        gameMessage.textContent = `MENANG!\n${time} ms`;
        reactionHistory.push(parseFloat(time));
        updateStats(); // update hanya saat punya data reaksi
    } else {
        gameMessage.textContent = `${winner}\nMENANG`;
    }

    roundResults.push({ winner, time });
}

function showTooEarly(culprit) {
    isGameActive       = false;
    gameArea.className = 'state-early';

    if (culprit && culprit !== myUsername) {
        gameMessage.textContent = `${culprit}\nTERLALU CEPAT!`;
    } else {
        gameMessage.textContent = `TERLALU CEPAT!\n-50 Poin`;
    }
}

function updateRoundIndicator() {
    roundIndicator.textContent = `RONDE ${currentRound} / ${maxRounds}`;
}

function updateStats() {
    if (reactionHistory.length === 0) {
        statAvg.textContent         = '---';
        statConsistency.textContent = '---';
        statBest.textContent        = '---';
        return;
    }

    const avg         = reactionHistory.reduce((a, b) => a + b, 0) / reactionHistory.length;
    const fastest     = Math.min(...reactionHistory);
    const consistency = Math.max(...reactionHistory) - fastest;

    statAvg.textContent         = avg.toFixed(0);
    statConsistency.textContent = consistency.toFixed(0);
    statBest.textContent        = fastest.toFixed(0);
}

// FIX: tampilkan stats dari data server, update panel bawah, simpan ke localStorage
function showFinalStats(stats) {
    console.log('Final Stats:', stats);
    gameArea.className = 'state-result';

    if (Array.isArray(stats) && stats.length > 0) {
        const top = stats[0];

        // Cari data milik pemain sendiri untuk panel stats bawah
        const me = stats.find(p => p.username === myUsername) || top;

        gameMessage.textContent =
            `JUARA: ${top.username}\n` +
            `Skor: ${top.score}\n` +
            `Avg: ${top.avgTime ?? '---'}ms  Best: ${top.bestTime ?? '---'}ms`;

        // Update stat panel bawah dari data server (lebih akurat dari lokal)
        if (me.avgTime)     statAvg.textContent         = parseFloat(me.avgTime).toFixed(0);
        if (me.bestTime)    statBest.textContent        = parseFloat(me.bestTime).toFixed(0);
        if (me.consistency) statConsistency.textContent = parseFloat(me.consistency).toFixed(0);
    } else {
        gameMessage.textContent = "GAME OVER";
    }

    // Simpan ke localStorage untuk dashboard
    _saveGameSession(stats, roundResults);
}

// ─── Simpan sesi ke localStorage (dibaca oleh dashboard.html) ────────────────
function _saveGameSession(stats, roundLog) {
    try {
        const STORAGE_KEY = 'reactionDuel_sessions';
        const sessions = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
        sessions.unshift({
            id:        'S' + Date.now(),
            timestamp: new Date().toISOString(),
            players:   stats,
            roundLog:  roundLog || [],
        });
        if (sessions.length > 50) sessions.pop();
        localStorage.setItem(STORAGE_KEY, JSON.stringify(sessions));
        console.log('[SESSION] Tersimpan ke localStorage!');
    } catch(e) {
        console.warn('[SESSION] Gagal simpan:', e);
    }
}

// ─── Input Handler ───────────────────────────────────────────────────────────

joinBtn.addEventListener('click', () => {
    const name = usernameInput.value.trim();
    if (name) {
        myUsername          = name;
        joinBtn.textContent = "Menghubungkan...";
        joinBtn.disabled    = true;
        connectToServer();
    } else {
        usernameInput.style.borderColor = '#ff2d75';
        setTimeout(() => {
            usernameInput.style.borderColor = 'rgba(0, 245, 255, 0.3)';
        }, 1000);
    }
});

usernameInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') joinBtn.click();
});

gameArea.addEventListener('mousedown', handleClick);
gameArea.addEventListener('touchstart', handleClick, { passive: false });

function handleClick(e) {
    e.preventDefault();

    // Klik saat fase WAIT → TOO_EARLY
    if (!isGameActive && gameArea.classList.contains('state-wait')) {
        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify({ type: 'TOO_EARLY', username: myUsername }));
        } else {
            showTooEarly(myUsername);
        }
        return;
    }

    // Klik saat fase GO → kirim waktu reaksi
    if (isGameActive) {
        const reactionTime = performance.now() - startTime;
        isGameActive       = false;

        console.log(`Waktu Reaksi: ${reactionTime.toFixed(2)} ms`);

        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify({
                type:     'REACTION_TIME',
                username: myUsername,
                time:     reactionTime
            }));
        } else {
            // ── Mode Offline ──────────────────────────────────────────────────
            reactionHistory.push(reactionTime);
            showResult(myUsername, reactionTime.toFixed(0));

            setTimeout(() => {
                if (currentRound < maxRounds) {
                    currentRound++;
                    updateRoundIndicator();
                    startSimulationRound();
                } else {
                    showFinalStats([{
                        username:    myUsername,
                        score:       reactionHistory.length * 100,
                        avgTime:     (reactionHistory.reduce((a,b) => a+b, 0) / reactionHistory.length).toFixed(0),
                        bestTime:    Math.min(...reactionHistory).toFixed(0),
                        consistency: (Math.max(...reactionHistory) - Math.min(...reactionHistory)).toFixed(0),
                        penalties:   0,
                        roundsWon:   reactionHistory.length,
                    }]);
                }
            }, 2500);
        }
    }
}

// ─── Mode Solo (Offline Simulation) ─────────────────────────────────────────

const testBtn = document.createElement('button');
testBtn.innerText     = "Mode Solo";
testBtn.style.cssText = `
    position: fixed; top: 15px; right: 15px; z-index: 999;
    padding: 12px 20px;
    background: rgba(0, 245, 255, 0.2);
    border: 1px solid var(--accent-cyan); border-radius: 8px;
    color: #00f5ff; font-family: 'Orbitron', monospace;
    font-size: 0.85rem; cursor: pointer; transition: all 0.3s;
`;
document.body.appendChild(testBtn);

testBtn.addEventListener('click', () => {
    myUsername = usernameInput.value.trim() || "Player";
    showGameScreen();
    updatePlayerList([
        { name: myUsername + " (You)", score: 0, ready: true },
        { name: "Bot",                 score: 0, ready: true },
    ]);
    setTimeout(startSimulationRound, 1000);
});

function startSimulationRound() {
    setWaitState();
    const delay = Math.random() * 3000 + 2000;
    setTimeout(() => {
        setGoState();
        startTime = performance.now();
    }, delay);
}
