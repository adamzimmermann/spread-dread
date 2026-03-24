import './styles/app.css';

// March Madness Bracket - AJAX interactions

// --- Region tabs ---

var scrollingToRegion = null;

function scrollToRegion(region) {
    var el = document.getElementById('region-' + region);
    if (!el) return;

    // Suppress scroll listener during programmatic scroll
    scrollingToRegion = region;

    // Account for sticky elements (score card + region tabs)
    var offset = 0;
    var scoreCard = document.getElementById('score-card');
    var regionTabs = document.getElementById('region-tabs');
    if (scoreCard) offset += scoreCard.offsetHeight;
    if (regionTabs) offset += regionTabs.offsetHeight;

    var top = el.getBoundingClientRect().top + window.pageYOffset - offset - 8;
    window.scrollTo({ top: top, behavior: 'smooth' });

    // Highlight active tab
    setActiveRegionTab(region);
}

function setActiveRegionTab(region) {
    document.querySelectorAll('.region-tab').forEach(function(tab) {
        if (tab.dataset.region === region) {
            tab.classList.add('region-tab-active');
        } else {
            tab.classList.remove('region-tab-active');
        }
    });
}

// Position region tabs below sticky score card
(function() {
    var regionTabs = document.getElementById('region-tabs');
    var scoreCard = document.getElementById('score-card');
    if (regionTabs && scoreCard) {
        regionTabs.style.top = scoreCard.offsetHeight + 'px';
    }
})();

// Highlight region tab on scroll (only for manual scrolling)
(function() {
    var ticking = false;
    var scrollTimer = null;
    window.addEventListener('scroll', function() {
        // While a programmatic scroll is in progress, keep resetting
        // the unlock timer so we don't interfere until scrolling stops
        if (scrollingToRegion) {
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(function() {
                scrollingToRegion = null;
            }, 100);
            return;
        }

        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function() {
            ticking = false;
            var tabs = document.querySelectorAll('.region-tab');
            if (!tabs.length) return;

            var offset = 0;
            var scoreCard = document.getElementById('score-card');
            var regionTabs = document.getElementById('region-tabs');
            if (scoreCard) offset += scoreCard.offsetHeight;
            if (regionTabs) offset += regionTabs.offsetHeight;

            var regions = ['East', 'West', 'South', 'Midwest'];
            var activeRegion = null;
            for (var i = regions.length - 1; i >= 0; i--) {
                var el = document.getElementById('region-' + regions[i]);
                if (el && el.getBoundingClientRect().top <= offset + 20) {
                    activeRegion = regions[i];
                    break;
                }
            }

            setActiveRegionTab(activeRegion);
        });
    });
})();

function updateScoreCard(scores) {
    if (!scores) return;
    document.getElementById('score-p1').textContent = scores.player1;
    document.getElementById('score-p2').textContent = scores.player2;
}

function updatePickProgress(progress) {
    if (!progress) return;
    var myText = document.getElementById('pick-progress-my-text');
    var myBar = document.getElementById('pick-progress-my-bar');
    var oppText = document.getElementById('pick-progress-opponent-text');
    var oppBar = document.getElementById('pick-progress-opponent-bar');
    if (myText) myText.textContent = progress.myDone + '/' + progress.myTotal;
    if (myBar) {
        var myPct = progress.myTotal > 0 ? (progress.myDone / progress.myTotal * 100) : 0;
        myBar.style.width = myPct + '%';
        myBar.className = myBar.className.replace(/bg-\w+-\d+/g, '');
        myBar.classList.add('h-full', 'rounded-full', 'transition-all');
        myBar.classList.add(progress.myDone === progress.myTotal ? 'bg-green-500' : 'bg-blue-500');
    }
    if (oppText) oppText.textContent = progress.opponentDone + '/' + progress.opponentTotal;
    if (oppBar) {
        var oppPct = progress.opponentTotal > 0 ? (progress.opponentDone / progress.opponentTotal * 100) : 0;
        oppBar.style.width = oppPct + '%';
        oppBar.className = oppBar.className.replace(/bg-\w+-\d+/g, '');
        oppBar.classList.add('h-full', 'rounded-full', 'transition-all');
        oppBar.classList.add(progress.opponentDone === progress.opponentTotal ? 'bg-green-500' : 'bg-red-400');
    }
}

function updateGameCard(gameId, html) {
    var card = document.getElementById('game-card-' + gameId);
    if (card) card.innerHTML = html;
}

function updateGameCards(cards) {
    if (!cards) return;
    Object.keys(cards).forEach(function(gameId) {
        updateGameCard(gameId, cards[gameId]);
    });
}

function assignPick(gameId, teamId) {
    var card = document.getElementById('game-card-' + gameId);
    card.classList.add('game-card-updating');

    var formData = new FormData();
    formData.append('team_id', teamId);

    fetch('/api/games/' + gameId + '/pick', {
        method: 'POST',
        body: formData,
    })
    .then(function(response) {
        if (!response.ok) throw new Error('Failed to save pick');
        return response.json();
    })
    .then(function(data) {
        card.innerHTML = data.html;
        updateScoreCard(data.scores);
        updatePickProgress(data.pickProgress);
    })
    .catch(function(err) {
        alert('Error saving pick: ' + err.message);
    })
    .finally(function() {
        card.classList.remove('game-card-updating');
    });
}

function setSpread(gameId) {
    var spreadVal = document.getElementById('spread-val-' + gameId).value;
    var spreadTeamId = document.getElementById('spread-team-' + gameId).value;

    if (!spreadVal) {
        alert('Enter a spread value');
        return;
    }

    var card = document.getElementById('game-card-' + gameId);
    card.classList.add('game-card-updating');

    var formData = new FormData();
    formData.append('spread', spreadVal);
    formData.append('spread_team_id', spreadTeamId);

    fetch('/api/games/' + gameId + '/spread', {
        method: 'POST',
        body: formData,
    })
    .then(function(response) {
        if (!response.ok) throw new Error('Failed to save spread');
        return response.json();
    })
    .then(function(data) {
        updateGameCard(gameId, data.html);
        updateScoreCard(data.scores);
    })
    .catch(function(err) {
        alert('Error: ' + err.message);
    })
    .finally(function() {
        card.classList.remove('game-card-updating');
    });
}


function pullSpreads(bracketId, round) {
    var btn = document.getElementById('btn-spreads');

    if (btn.dataset.hasSpreads === '1' && btn.dataset.hasPicks === '1') {
        if (!confirm('Spreads have already been pulled and picks have been made for this round. Pulling again may change spreads and affect pick results. Continue?')) {
            return;
        }
    }

    btn.classList.add('loading');
    btn.textContent = 'Pulling...';

    var formData = new FormData();
    formData.append('round', round);

    fetch('/api/brackets/' + bracketId + '/pull-spreads', {
        method: 'POST',
        body: formData,
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.error) {
            alert(data.error);
        } else {
            updateGameCards(data.cards);
            updateScoreCard(data.scores);
        }
    })
    .catch(function(err) {
        alert('Error: ' + err.message);
    })
    .finally(function() {
        btn.classList.remove('loading');
        btn.textContent = 'Pull Spreads';
    });
}

// --- Pull Teams from API (ESPN) ---

function pullTeams(bracketId) {
    var btn = document.getElementById('btn-pull-teams');
    btn.classList.add('loading');
    btn.textContent = 'Pulling...';

    var errorEl = document.getElementById('api-error');
    errorEl.classList.add('hidden');

    fetch('/api/brackets/' + bracketId + '/pull-teams', {
        method: 'POST',
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.error) {
            errorEl.textContent = data.error;
            errorEl.classList.remove('hidden');
            return;
        }

        var teams = data.teams || [];
        if (teams.length === 0) {
            errorEl.textContent = 'No tournament teams found. Enter teams manually.';
            errorEl.classList.remove('hidden');
            return;
        }

        // Auto-fill the form inputs with ESPN data
        var filled = 0;
        teams.forEach(function(team) {
            var teamInput = document.getElementById('team_' + team.region + '_' + team.seed);
            var apiInput = document.getElementById('apiname_' + team.region + '_' + team.seed);

            if (teamInput) {
                teamInput.value = team.name;
                filled++;
            }
            if (apiInput) {
                apiInput.value = team.name;
            }
        });

        // Store ESPN event IDs as hidden fields for R64 game matching
        var matchups = data.matchups || [];
        var eventIdContainer = document.getElementById('eventid-fields');
        if (eventIdContainer) {
            eventIdContainer.innerHTML = '';
            matchups.forEach(function(m) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'eventid_' + m.region + '_' + m.seed1 + '_' + m.seed2;
                input.value = m.event_id;
                eventIdContainer.appendChild(input);
            });
        }

        btn.textContent = filled + ' teams loaded!';
        setTimeout(function() { btn.textContent = 'Pull Teams from API'; }, 2000);
    })
    .catch(function(err) {
        errorEl.textContent = 'Error: ' + err.message;
        errorEl.classList.remove('hidden');
    })
    .finally(function() {
        btn.classList.remove('loading');
    });
}

function updateScores(bracketId, round) {
    var btn = document.getElementById('btn-scores');
    btn.classList.add('loading');
    btn.textContent = 'Updating...';

    var formData = new FormData();
    formData.append('round', round);

    fetch('/api/brackets/' + bracketId + '/update-scores', {
        method: 'POST',
        body: formData,
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.error) {
            alert(data.error);
        } else {
            updateGameCards(data.cards);
            updateScoreCard(data.scores);
        }
    })
    .catch(function(err) {
        alert('Error: ' + err.message);
    })
    .finally(function() {
        btn.classList.remove('loading');
        btn.textContent = 'Update Scores';
    });
}

// Expose functions globally for onclick handlers in templates
window.scrollToRegion = scrollToRegion;
window.updateScoreCard = updateScoreCard;
window.updatePickProgress = updatePickProgress;
window.updateGameCard = updateGameCard;
window.updateGameCards = updateGameCards;
window.assignPick = assignPick;
window.setSpread = setSpread;
window.pullSpreads = pullSpreads;
window.pullTeams = pullTeams;
window.updateScores = updateScores;
