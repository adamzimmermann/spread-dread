// March Madness Bracket - AJAX interactions

function assignPick(gameId, player, teamId) {
    const card = document.getElementById('game-card-' + gameId);
    card.classList.add('game-card-updating');

    const formData = new FormData();
    formData.append('player', player);
    formData.append('team_id', teamId);

    fetch('/api/games/' + gameId + '/pick', {
        method: 'POST',
        body: formData,
    })
    .then(function(response) {
        if (!response.ok) throw new Error('Failed to save pick');
        return response.text();
    })
    .then(function(html) {
        card.innerHTML = html;
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

    var formData = new FormData();
    formData.append('spread', spreadVal);
    formData.append('spread_team_id', spreadTeamId);

    fetch('/api/games/' + gameId + '/spread', {
        method: 'POST',
        body: formData,
    })
    .then(function(response) {
        if (!response.ok) throw new Error('Failed to save spread');
        location.reload();
    })
    .catch(function(err) {
        alert('Error: ' + err.message);
    });
}

function setScore(gameId) {
    var t1 = document.getElementById('score-t1-' + gameId).value;
    var t2 = document.getElementById('score-t2-' + gameId).value;

    if (t1 === '' || t2 === '') {
        alert('Enter both scores');
        return;
    }

    var formData = new FormData();
    formData.append('team1_score', t1);
    formData.append('team2_score', t2);

    fetch('/api/games/' + gameId + '/score', {
        method: 'POST',
        body: formData,
    })
    .then(function(response) {
        if (!response.ok) throw new Error('Failed to save score');
        location.reload();
    })
    .catch(function(err) {
        alert('Error: ' + err.message);
    });
}

function pullSpreads(bracketId, round) {
    var btn = document.getElementById('btn-spreads');
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
            alert('Matched ' + data.matched + ' of ' + data.total + ' games');
            location.reload();
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
            // Update score display
            if (data.scores) {
                document.getElementById('score-p1').textContent = data.scores.player1;
                document.getElementById('score-p2').textContent = data.scores.player2;
            }
            alert('Updated ' + data.result.updated + ' games');
            location.reload();
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
