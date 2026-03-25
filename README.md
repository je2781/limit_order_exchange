---
title: Limit Order Exchange
description: A trading platform
author: Joshua Eze
created:  2026 Mar 25
updated: 2026 Mar 25
---

Exchange Engine
=========

## Development
I started with the project structure, and moved the routes into the (pages) folder. Authentication followed, with storing mock data in the login page. I later onto the wallet page, with all its components, while doing its responsiveness. Finally worked on the modals, with responsive as well.

For backend I started with integrating TypeORM with the entities, before creating services/controllers/modules for each entity. Tested the routes on postman successfully, and added authentication, and data validation.

## How to run the app (frontend)

Run (npm run dev) from the main directory to compile for development. To test run (npm run test). 

## How to run the app (backend)

Run (docker compose up mysql nestjs) from the main directory to compile for development. To test run (npm run test:e2e). Note have docker desktop installed and open before compiling for the backend. __And make sure only test-sql service container is running for tests, and only mysql service container for development/production.__ 






