---
title: Limit Order Exchange
description: A trading platform
author: Joshua Eze
created:  2026 Mar 25
updated: 2026 Mar 27
---

Exchange Engine
=========

## Development
I started with the project structure, docker setup, created migrations, routes, controllers, relationships, and business functions. I then setup pusher web socket, an authenticated broadcast channel, created a queued job to broadcast "OrderMatched" event for every sell or buy.

For frontend I started with the wallet, and split the viewport into left and right with the left side displaying user asset, wallet, socket transactions. The right side consumed a  lot of dev time, taking into accoutn validation, tabulizing the order list. I finally created a modal to show the limit order form for placing orders.

## How to run the app

Run (docker compose up) from the main directory to compile for development. Check credentials in database/seeders/UserSeeder.php for seller and buyer login. Open the seller/buyer accounts on separate browsers to see the real-time pusher updates when a full match is made 







