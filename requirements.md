# Spread Dread


## Background

My buddy and I compete on the NCAA Tournament every year.

### Current Process

1. print out a bracket
1. look up point spreads for each game before each round
1. write the point spread next to each game
1. take turns picking which side of the point spread we want and writing our names next to them
1. review the bracket after each game to see who won based upon the final score and the point spread.
1. calculate the current score of the competition


## Plan

I would like to build a web app to automate this process. Being mobile first in the design approach is important.

The app would have the following functionality:

- automatically build a data/visual representation of the NCAA men's backetball tournament bracket at the press of a button. The Tuesday/Wednesday play-in games can be ignored.
- data points that allow the software to know the current date and the dates of each round of games to allow a data/visual indication of the currently active round of the tournament so it's clear where new point spread data will go and what we should be looking at.
- ability to create multiple brackets, with a prompt that asks for a name and which year the data should be pulled from, it should default to the current year.
- a button that allows point spreads for the current round to be pulled from an online source such as CBS sports.
- a general shared login that allows us to login and see all the games in a list format that also gives context of the larger bracket. each game should display the two teams playing, the stored point spread, who has picked what team (and the ability to assign a team to player 1 or player 2)
- a score at the top of the page that is continually updated when the update score button is pressed


## Development

- I would like to host this on my Dreamhost account.
- Ideally this would use DDEV for a local development environment.
- I'm most familiar with PHP, but open to other back-end languages.
