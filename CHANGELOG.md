# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

# [2.30.0](https://github.com/ngramx/ngramx/compare/v2.29.3...v2.30.0) (2026-07-21)


### Bug Fixes

* satisfy PHPStan for secrets shorthand list types ([8987967](https://github.com/ngramx/ngramx/commit/8987967626d2f423e3cd4a503ab59c7b4954ae3c))
* validate worktree secrets before starting Docker ([89bc75f](https://github.com/ngramx/ngramx/commit/89bc75fd243640291fc15735bb48e5dd9f1c2bfb))


### Features

* accept shorthand secrets lists and rename env provider to shell ([23730e8](https://github.com/ngramx/ngramx/commit/23730e8f38a59dc8e6e470cd0b63952bf3523378))

## [2.29.3](https://github.com/ngramx/ngramx/compare/v2.29.2...v2.29.3) (2026-07-16)


### Bug Fixes

* unify worktree ticket-slug handling and probe the primary service for liveness ([c3a4f9f](https://github.com/ngramx/ngramx/commit/c3a4f9fa323186588012779775c7486722d051d7))

## [2.29.2](https://github.com/ngramx/ngramx/compare/v2.29.1...v2.29.2) (2026-07-16)


### Bug Fixes

* recreate worktree containers instead of restarting to survive stale WSL2 bind mounts ([ab42814](https://github.com/ngramx/ngramx/commit/ab42814fd0837fa0d1853bf3100b7f1da79300f2))

## [2.29.1](https://github.com/ngramx/ngramx/compare/v2.29.0...v2.29.1) (2026-07-16)


### Bug Fixes

* retry Cursor launch with live WSL IPC sockets when opening worktrees ([d526385](https://github.com/ngramx/ngramx/commit/d52638560070e4e5a96d0f222082cd020cf23e68))

# [2.29.0](https://github.com/ngramx/ngramx/compare/v2.28.0...v2.29.0) (2026-07-16)


### Features

* allow ngramx worktree without a ticket on the current feature branch ([5c54ef2](https://github.com/ngramx/ngramx/commit/5c54ef2e55b61d6337f7282a60d4f3086ac27ab1))

# [2.28.0](https://github.com/ngramx/ngramx/compare/v2.27.0...v2.28.0) (2026-07-16)


### Features

* add .env secrets provider and support multiple secret providers ([f56b885](https://github.com/ngramx/ngramx/commit/f56b8856f7ee43097b00cd7e1f778a2a372bb8a7))

# [2.27.0](https://github.com/ngramx/ngramx/compare/v2.26.0...v2.27.0) (2026-07-16)


### Features

* add --cleanup to worktree command ([e2f6274](https://github.com/ngramx/ngramx/commit/e2f627414ee005e88da69db59ca92255de13b16b))

# [2.26.0](https://github.com/ngramx/ngramx/compare/v2.25.1...v2.26.0) (2026-07-16)


### Features

* add -c shorthand for worktree and review --cursor ([ebc6765](https://github.com/ngramx/ngramx/commit/ebc6765761e8dbb655e526e9b90883a364b866a7))

## [2.25.1](https://github.com/ngramx/ngramx/compare/v2.25.0...v2.25.1) (2026-07-15)


### Bug Fixes

* seed worktree TLS certs covering the worktree hostname so HTTPS works ([8150619](https://github.com/ngramx/ngramx/commit/8150619854642a86e157b8b97eea3f443cf4e910))

# [2.25.0](https://github.com/ngramx/ngramx/compare/v2.24.5...v2.25.0) (2026-07-15)


### Bug Fixes

* auto-retry failed parallel sub-commands instead of failing the run (COR-269) ([26ad61b](https://github.com/ngramx/ngramx/commit/26ad61b0d3f4f850c1b43870cec2f885a75d4613))
* ignore checkout hooks and verify registration when creating worktrees (COR-275) ([f8463b3](https://github.com/ngramx/ngramx/commit/f8463b3815c604a33e2544a2540e5c9049fb8179))


### Features

* add ngramx worktree command for standalone per-ticket environments (COR-268) ([58e21df](https://github.com/ngramx/ngramx/commit/58e21df211cf034d94034f194d5edad4f125732c))
* detect and resolve individual port conflicts during ngramx up (COR-267) ([7715848](https://github.com/ngramx/ngramx/commit/771584807b56fbfa69e76c6f4957c210652565c0))
* follow per-port remaps when localising review URLs and completion deep-links ([c07ae10](https://github.com/ngramx/ngramx/commit/c07ae10fda7ecfe0d9be0c3c24c2c534ead27ab4))
* overlap worktree dependency priming with environment startup (COR-257) ([bc94132](https://github.com/ngramx/ngramx/commit/bc94132da176f24811477aeabf5c6b6db39828bd))

## [2.24.5](https://github.com/ngramx/ngramx/compare/v2.24.4...v2.24.5) (2026-07-05)


### Bug Fixes

* **secure:** create nss store and trust the ca on windows under wsl ([00ffa49](https://github.com/ngramx/ngramx/commit/00ffa494b4e9a6f8e3f6dea7f5987ff0729875de))

## [2.24.4](https://github.com/ngramx/ngramx/compare/v2.24.3...v2.24.4) (2026-07-05)


### Bug Fixes

* **secure:** install certutil and don't abort when trust needs sudo ([00c93b9](https://github.com/ngramx/ngramx/commit/00c93b97ed49021766b38fdc364272f6df54715d))

## [2.24.3](https://github.com/ngramx/ngramx/compare/v2.24.2...v2.24.3) (2026-06-27)


### Bug Fixes

* auto-start the environment in `ngramx review` instead of refusing ([725bfaf](https://github.com/ngramx/ngramx/commit/725bfafca90bcd63b7fa9404b0f86587ff6539ca))

## [2.24.2](https://github.com/ngramx/ngramx/compare/v2.24.1...v2.24.2) (2026-06-27)


### Bug Fixes

* choose worktree review URL by probing the app, and stop port drift ([cdcfa10](https://github.com/ngramx/ngramx/commit/cdcfa1065136e8ecd97f1e1a134c25b40ebb6553))

## [2.24.1](https://github.com/ngramx/ngramx/compare/v2.24.0...v2.24.1) (2026-06-27)


### Bug Fixes

* write docker-compose.override.yml next to the base compose file ([5278a82](https://github.com/ngramx/ngramx/commit/5278a82f15e5e4f29bfc58b502be7ca3f76d1f58))

# [2.24.0](https://github.com/ngramx/ngramx/compare/v2.23.13...v2.24.0) (2026-06-12)


### Features

* sequential command lists, configurable verify timeout, and worktree-aware URL rewriting ([e739660](https://github.com/ngramx/ngramx/commit/e73966033cdd7d144034fbb5bf7ed2b3b50c93da))

## [2.23.13](https://github.com/ngramx/ngramx/compare/v2.23.12...v2.23.13) (2026-06-01)


### Bug Fixes

* **github-actions:** drive Linear In Progress from pull_request, watch broad CI list for In Review ([066b663](https://github.com/ngramx/ngramx/commit/066b663d1f524fc1059569d1c31ddc45c3695a85))

## [2.23.12](https://github.com/ngramx/ngramx/compare/v2.23.11...v2.23.12) (2026-05-30)


### Bug Fixes

* prime worktree vendor dirs in parallel to speed up review ([8f3d20a](https://github.com/ngramx/ngramx/commit/8f3d20a9da658ee673a7b17779b6e6f2cccc565d))

## [2.23.11](https://github.com/ngramx/ngramx/compare/v2.23.10...v2.23.11) (2026-05-30)


### Bug Fixes

* replace completion.md with structured completion.json and rich review output ([10452e6](https://github.com/ngramx/ngramx/commit/10452e67c36afef7e9065d694dd548184bd0b75c))

## [2.23.10](https://github.com/ngramx/ngramx/compare/v2.23.9...v2.23.10) (2026-05-30)


### Bug Fixes

* enforce Linear In Progress status on every agent prompt ([acafc43](https://github.com/ngramx/ngramx/commit/acafc439ff2cf31ff7522bb507823ac219be5542))

## [2.23.9](https://github.com/ngramx/ngramx/compare/v2.23.8...v2.23.9) (2026-05-30)


### Bug Fixes

* **github-actions:** trigger linear-status-sync on workflow_run ([176b574](https://github.com/ngramx/ngramx/commit/176b5743fc894f063b21c773b95e5ba8257712f0))

## [2.23.8](https://github.com/ngramx/ngramx/compare/v2.23.7...v2.23.8) (2026-05-30)


### Bug Fixes

* **github-actions:** resolve Linear states by name, not per-team UUIDs ([3f85617](https://github.com/ngramx/ngramx/commit/3f85617929ec0c4b48f1788a392728780c8cbfb5))

## [2.23.7](https://github.com/ngramx/ngramx/compare/v2.23.6...v2.23.7) (2026-05-30)


### Bug Fixes

* **github-actions:** surface required org secrets in init output ([f973093](https://github.com/ngramx/ngramx/commit/f9730936425421776036f430c90501aa05e939fa))

## [2.23.6](https://github.com/ngramx/ngramx/compare/v2.23.5...v2.23.6) (2026-05-30)


### Bug Fixes

* **review:** allow cleanup of all worktrees without a ticket argument ([038132a](https://github.com/ngramx/ngramx/commit/038132ace878164267cd8b5c07b8d44c87165a01))
* **worktree:** keep config inside the worktree and retry the docker daemon probe ([54149cd](https://github.com/ngramx/ngramx/commit/54149cdb203217aa7db1e7c6d16763320ddc5166))

## [2.23.5](https://github.com/ngramx/ngramx/compare/v2.23.4...v2.23.5) (2026-05-30)


### Bug Fixes

* **github-actions:** distribute linear-status-sync as a shared caller ([efe7057](https://github.com/ngramx/ngramx/commit/efe7057bcd0fa620dea4988dc94b42f187e9f9c1))

## [2.23.4](https://github.com/ngramx/ngramx/compare/v2.23.3...v2.23.4) (2026-05-30)


### Bug Fixes

* **worktree:** chown worktrees to the developer uid so Laravel can write storage ([cd27025](https://github.com/ngramx/ngramx/commit/cd270253052f61b2ff710fa598611b4a00960011))

## [2.23.3](https://github.com/ngramx/ngramx/compare/v2.23.2...v2.23.3) (2026-05-30)


### Bug Fixes

* **agents:** add Linear status sync rules and dogfood Cursor + Claude ([512caed](https://github.com/ngramx/ngramx/commit/512caedf9da039fc3e864c92b49fd772c4f3b70c))

## [2.23.2](https://github.com/ngramx/ngramx/compare/v2.23.1...v2.23.2) (2026-05-30)


### Bug Fixes

* **output:** make warnings and errors inherit surrounding indentation ([2fd8862](https://github.com/ngramx/ngramx/commit/2fd88626bd7de4d8c900b95c97a7a319158160be))

## [2.23.1](https://github.com/ngramx/ngramx/compare/v2.23.0...v2.23.1) (2026-05-30)


### Bug Fixes

* **review:** reconcile worktree file ownership after reset ([360c119](https://github.com/ngramx/ngramx/commit/360c119e2fd42e3740d19f908e381fce7165e605))

# [2.23.0](https://github.com/ngramx/ngramx/compare/v2.22.1...v2.23.0) (2026-05-30)


### Bug Fixes

* **review:** make worktree git resolve inside containers + merge-safe override ([90076f3](https://github.com/ngramx/ngramx/commit/90076f37f738160aa296ecbbf5024c065d9e85e2))


### Features

* **wait:** probe real service readiness and fail fast on crash loops ([5bf9e74](https://github.com/ngramx/ngramx/commit/5bf9e74483dba1be8c92c307e912375d868ac85b))

## [2.22.1](https://github.com/ngramx/ngramx/compare/v2.22.0...v2.22.1) (2026-05-30)


### Bug Fixes

* **review:** hide generated .cursorignore from the parent's git status ([ebc6670](https://github.com/ngramx/ngramx/commit/ebc6670c3896575713d8e2d85b81fa340ced8d19))

# [2.22.0](https://github.com/ngramx/ngramx/compare/v2.21.1...v2.22.0) (2026-05-30)


### Bug Fixes

* **ci:** unblock release and restore version baseline ([65bf1ea](https://github.com/ngramx/ngramx/commit/65bf1eabd8eec72952c29bb2d0be91eeae81779f))
* **completion:** ship completion templates in phar and auto-install ([b9272c7](https://github.com/ngramx/ngramx/commit/b9272c75ef93cce818916e76e3baf6a58c2c53d1))
* **ports:** preserve env-var interpolation in compose port mappings ([806d064](https://github.com/ngramx/ngramx/commit/806d064ead17250f36116d1e3fe882d5e5e80604))
* **review:** surface git output when branch checkout fails ([9519a4b](https://github.com/ngramx/ngramx/commit/9519a4bfccef7c124c0b44a805e6c8ce524ca016))


### Features

* Added --worktree to code review command and moved a lot of AGENTS.md to skills and made sure cursorules are also written because instructions were being ignored ([4dd8ef1](https://github.com/ngramx/ngramx/commit/4dd8ef135da8c391b0e161bca8e4bcfcb07f9240))

# 1.0.0 (2026-05-30)


### Bug Fixes

* add blank line before config warnings in up and rebuild ([ceb055f](https://github.com/ngramx/ngramx/commit/ceb055f3ebad853387f6a5acefbaca4f02d60043))
* **agents:** reorder templates so ticket workflow appears last in AGENTS.md ([bbe0a53](https://github.com/ngramx/ngramx/commit/bbe0a5334ec3d5da4375e4f8c3848fd3f33fdb9f))
* **agents:** tidy branch-name rule and strip trailing whitespace in ticket-workflow template ([3deabac](https://github.com/ngramx/ngramx/commit/3deabac9c32e8914bd745a604d3d84198d3064ab))
* change default timeout on user commands ([8ef7db7](https://github.com/ngramx/ngramx/commit/8ef7db7f104b0cbbfdfaf63f152ed481810ace2d))
* check if we can bind to a port not just if something is listening when checking for port conflicts ([4ba9a27](https://github.com/ngramx/ngramx/commit/4ba9a274b82117b55c3b1400050745ef1c84af2a))
* **ci:** add package-lock.json for reproducible builds ([b12557f](https://github.com/ngramx/ngramx/commit/b12557f9a34d77fed0bfba5f847201dc9d0a3cdd))
* cleanup stale containers ([f060f86](https://github.com/ngramx/ngramx/commit/f060f86b04ddaa79e5a4c0bf16aa406ff0bfc6c8))
* completion file lookup matches partial ticket numbers and drops @ prefix ([930886e](https://github.com/ngramx/ngramx/commit/930886e60be33d71e5543baefcf55f647cc504f6))
* container cleanup ([40e4984](https://github.com/ngramx/ngramx/commit/40e49844730a25953ccbb39da9b9d2eab33fad0c))
* container namespacing ([5b045e0](https://github.com/ngramx/ngramx/commit/5b045e037cb81f1477d9d69a80b763442da00491))
* container namespacing wasn't working correctly ([e6b00b1](https://github.com/ngramx/ngramx/commit/e6b00b1cd8792e9d90d7135c753bf7b06e0572a7))
* correct PS1 escape sequences for Docker env var passthrough ([ec16ecb](https://github.com/ngramx/ngramx/commit/ec16ecb04b9811a086318a5d4d33eef1cb415880))
* **down:** guard lock read when JSON is corrupt or unreadable ([d671c29](https://github.com/ngramx/ngramx/commit/d671c298a35b42907b9ac6be223c53c65ba05e6c))
* **etc-hosts-hint:** skip raw IP hostnames (gethostbyname ambiguity) ([735e176](https://github.com/ngramx/ngramx/commit/735e1765b61a3b329ccc851aeeb7a498e71bb8e7))
* fix phpstan errors ([6ac7724](https://github.com/ngramx/ngramx/commit/6ac77243e1acfb66d0d2f8d15a7af11fccff95e6))
* fix phpstan tests ([591a14f](https://github.com/ngramx/ngramx/commit/591a14f50f09ff3deb0c41ecc45e50868b9eaa53))
* fix unit tests for shellf command ([1303be1](https://github.com/ngramx/ngramx/commit/1303be10f07498283862adec20cc1869416270b5))
* handle potential null return from preg_replace in ComposeOverrideGenerator ([9c14bd6](https://github.com/ngramx/ngramx/commit/9c14bd6bc1da75b832cb051d01eaa0757fea601a))
* increase timeout when rebuilding and allow user to provide timout length ([6f18c82](https://github.com/ngramx/ngramx/commit/6f18c82df5d1c060c4f056f2668f4a1bba2e2e30))
* **init-github-actions:** default --php-version to 8.3 (was 8.2) ([399b598](https://github.com/ngramx/ngramx/commit/399b59879febf40d8acb09e990263c56e757f8f9))
* make sure .gitkeep is added to .cortex/ folders ([157010f](https://github.com/ngramx/ngramx/commit/157010f5f1a97ed0923371de89917c4c63facad6))
* move config warnings to appear after config loading in up/rebuild ([4a4230a](https://github.com/ngramx/ngramx/commit/4a4230af34c4597e780483bfc73657eea34e8444))
* port offset ([c84f736](https://github.com/ngramx/ngramx/commit/c84f73636fda4395ec77eaf59857bcf1cf6477d6))
* prune stale remotes and fast-forward existing local branches on review checkout ([2a9ad12](https://github.com/ngramx/ngramx/commit/2a9ad120b8d4ad372db5a75bd528cddabe2a37d5))
* remove unused mock setup that caused PHPStan error ([1d60c14](https://github.com/ngramx/ngramx/commit/1d60c149f54ab70649409bcd77697e14fe8bb3ce))
* remove unused mock setup that caused PHPStan error ([472b188](https://github.com/ngramx/ngramx/commit/472b1885fbbf0aecfc9ec829b9237a08adf05af7))
* satisfy PHPStan in DockerCompose and SetupOrchestrator tests ([84bc5f9](https://github.com/ngramx/ngramx/commit/84bc5f99c5733a7d761268f3d5ce078ec4edec24))
* satisfy PHPStan level 8 in ReviewCommandTest ([b6b89b8](https://github.com/ngramx/ngramx/commit/b6b89b8ac63e248211cb53dd5b1b1012af66a9c0))
* tests on 8.3 ([715c117](https://github.com/ngramx/ngramx/commit/715c11759b032924e1ad6aba6f310209f3346fbf))
* tighten service status spacing and use purple for all non-error statuses ([07b1b15](https://github.com/ngramx/ngramx/commit/07b1b157ee808ee9ffc9cea8b878b785c6e960e6))
* **up:** probe and "Access at" URLs follow --port-offset / --avoid-conflicts ([fa5759f](https://github.com/ngramx/ngramx/commit/fa5759fc7a544cd37099a76106b3077eb2732f05))
* use !reset tag instead of !override for empty ports in compose override ([c1a3f6f](https://github.com/ngramx/ngramx/commit/c1a3f6f5302069c91670316c4e51a520a9d792e2))
* use /dev/tty for interactive shell to preserve terminal for readline ([4dcf221](https://github.com/ngramx/ngramx/commit/4dcf22130a8210df4c3a23665690cccdbbf2f1fb))
* use direct bash invocation with Docker env var for TTY support ([2df8b2f](https://github.com/ngramx/ngramx/commit/2df8b2fae82b7610ae76c0e5954f3e3e56be0a79))
* use direct bash invocation with Docker env var for TTY support ([06ef303](https://github.com/ngramx/ngramx/commit/06ef303528235617ac5ed88da8fe40056c15a09d))
* use docker to scan ports becausse we were chec king ports inside the container than the cortex command was running, but we're using docker socket to create the containers on the host ([2467a44](https://github.com/ngramx/ngramx/commit/2467a44a429b9487c8cf272955665ae1431eee28))
* use proc_open with direct FD inheritance for interactive shell ([38d2c4f](https://github.com/ngramx/ngramx/commit/38d2c4f533980af80a85ace8f6e6b404050dd024))


### Features

* add --no-host-mapping flag to cortex up command ([b7613e0](https://github.com/ngramx/ngramx/commit/b7613e0e02342c58b517a75b64c1bbfb40bf6971))
* add --quick option to review command to use clear instead of fresh ([b38e4b5](https://github.com/ngramx/ngramx/commit/b38e4b579fc1b9b2751544a8db4c6b2b30236e12))
* add auto-merge workflow and Linear ticket conventions for agent-driven PR routing ([0a07e43](https://github.com/ngramx/ngramx/commit/0a07e4379f6bb1f7b83ba73d169e5cc9074d60ad))
* Add cortex shell command ([02eadb9](https://github.com/ngramx/ngramx/commit/02eadb93e5e97b896879ac15dcc2bcb44e6c935d))
* add Herd service management, cortex logs command, and shell TTY fix ([afa0779](https://github.com/ngramx/ngramx/commit/afa0779c16a023d47d18aa2cbb6f91e71b9af548))
* Add init command for project setup ([a0ce800](https://github.com/ngramx/ngramx/commit/a0ce8007dab73b48387ec8ff9848732fbb7dc3bd))
* add recommended lifecycle commands (clear, fresh, rebuild) ([71a240e](https://github.com/ngramx/ngramx/commit/71a240e4d5f7b76db350bc21548727eda4f129d9))
* add secrets config ([209ea0d](https://github.com/ngramx/ngramx/commit/209ea0d84101eaf270b23d057a000e5a02ff83da))
* Add self-update command for Cortex CLI ([ada23fe](https://github.com/ngramx/ngramx/commit/ada23febf4cf1215b638570169523060acdf330b))
* added --avoid-conflicts and other options for namspacing containers and avoiding port conflicts ([ee0a894](https://github.com/ngramx/ngramx/commit/ee0a894d2ecdda95deb2faff2319d60c64c6a254))
* Added --worktree to code review command and moved a lot of AGENTS.md to skills and made sure cursorules are also written because instructions were being ignored ([4dd8ef1](https://github.com/ngramx/ngramx/commit/4dd8ef135da8c391b0e161bca8e4bcfcb07f9240))
* Added app_url option to specify how to access the app after cortex up ([af01d60](https://github.com/ngramx/ngramx/commit/af01d6052241acf1fe1f98c81adefb03e2add224))
* Added app_url option to specify how to access the app after cotext up ([cd51c30](https://github.com/ngramx/ngramx/commit/cd51c3089619d6f8687fb976623284cdd6d8427e))
* Added cortex show-url to show the url for the applicaton running in the development environment ([16235ac](https://github.com/ngramx/ngramx/commit/16235acd9238da51376ccbaa3d5c26cb78165e1f))
* added global coding instructions to CLAUDE.md on cortex init ([e62ed05](https://github.com/ngramx/ngramx/commit/e62ed0526df8e5e2c590e46c7acfaf3121a01c3b))
* added parallel processing for user defined commands ([97c99ec](https://github.com/ngramx/ngramx/commit/97c99ec720d2dccdbbfe41887280a261b039a11e))
* **agents:** drop parent-ticket / sub-issue workflow from agent instructions ([28dbb8c](https://github.com/ngramx/ngramx/commit/28dbb8c55948d93342dbb3a981b8e4c318d14bbb))
* alpha changes by Chris ([d40e5a5](https://github.com/ngramx/ngramx/commit/d40e5a589df1ad208044437a04d2ebcf334bf547))
* **auto-merge:** gate on size:small + risk:low labels classified by agents ([2b5ecdf](https://github.com/ngramx/ngramx/commit/2b5ecdf9aca83af8a7ed77bd8a73e5974bc679c1))
* **commands:** add new 'n8n normalise' command ([5f3ba39](https://github.com/ngramx/ngramx/commit/5f3ba399a0922f0042efd795d67c2a508510e800))
* **commands:** add new 'n8n normalise' command ([c28dd61](https://github.com/ngramx/ngramx/commit/c28dd61b3c55fde81e056db70b951f3679b176d4))
* **commands:** add new 'review' command ([403aa81](https://github.com/ngramx/ngramx/commit/403aa818b9e94ef6103af4271769ffbef9035661))
* complete cortex CLI v1.0 ([c9adffd](https://github.com/ngramx/ngramx/commit/c9adffd46db6e42f295e417a08721bcf970a7815))
* complete cortex CLI v1.0.0 ([de3dc5b](https://github.com/ngramx/ngramx/commit/de3dc5be7ed4b8f3a2dc1adb57ffad5541a446af))
* cortex init now creates a cortex.md claude rule file ([da5aba6](https://github.com/ngramx/ngramx/commit/da5aba6b461d6d116ae257d910029a86677043b7))
* deploy Cortex Coder on a new release ([481bc95](https://github.com/ngramx/ngramx/commit/481bc950d13694bbb0907aa93d7b2269c7c24e90))
* detect crash-looping services during cortex up ([fce5e90](https://github.com/ngramx/ngramx/commit/fce5e90c3ade3bb5df5d60269508c891df19c58d))
* enumerate AGENTS.md template files with scandir so it works inside a Phar ([6b272a4](https://github.com/ngramx/ngramx/commit/6b272a44f12ef1f7f5c1b2c3407f3b901a673b73))
* fully automated releases with semantic-release ([d9704f3](https://github.com/ngramx/ngramx/commit/d9704f3da61ce1df103218cc35c9ade71521b058))
* Implement cortex shell subcommand ([3527cdd](https://github.com/ngramx/ngramx/commit/3527cdd9414cc42ae789f91abb626758046e8647))
* improve Docker startup UX with daemon detection, first-run messaging, and live container status ([69e2dbb](https://github.com/ngramx/ngramx/commit/69e2dbb40018c1b47c550268efef18089d6ce4a3))
* **init-github-actions:** use GitHub native auto-merge in caller template ([3bc31fb](https://github.com/ngramx/ngramx/commit/3bc31fb8fcd532cc3b0f835c4d1eefbd9d5e2dd0))
* **init:** save Claude files to ~/.claude for user-wide configuration ([8263ea1](https://github.com/ngramx/ngramx/commit/8263ea198db4589bb094f278122fa0d9f625a2c5))
* instruct agents to pre-create the parent branch and target it from sub-issue PRs ([64b877f](https://github.com/ngramx/ngramx/commit/64b877fee09c2beb55ec1e3af0093638bf112c7b))
* log output on up and rebuild, adjust timeouts, update docs for review command ([27b3c78](https://github.com/ngramx/ngramx/commit/27b3c78486282403da5ba13877d5347ee3ddae7b))
* n8n import command ([e7962ae](https://github.com/ngramx/ngramx/commit/e7962aee7415d591159d5a9598c4f302ff9e06c4))
* review command uses cortex fresh and displays completion URLs ([1be72db](https://github.com/ngramx/ngramx/commit/1be72db1d0349e3238d6bb22d57ca0e093c64e8f))
* shorten recommended command descriptions in config warnings ([88df8d1](https://github.com/ngramx/ngramx/commit/88df8d119b1fefa074b66688caf60186c482a1e6))
* show internal Docker URL when using --no-host-mapping with namespace ([adf4b11](https://github.com/ngramx/ngramx/commit/adf4b11db81c9d6214a61cd629b5abd6608a40f5))
* simplify README installation docs ([aba28d5](https://github.com/ngramx/ngramx/commit/aba28d5956bfa2a532b4b295793375d72613b401))
* **up:** add --rebuild option to force Docker image rebuilds ([0cb71b3](https://github.com/ngramx/ngramx/commit/0cb71b35bbfb910b3ebab88afa90c50885b0a31e))
* update AGENTS.md to include guidance for creating linear tickets ([181d4e3](https://github.com/ngramx/ngramx/commit/181d4e3a53aaf1ee9fdf304d43dd675e016baba4))
* **up:** detect and auto-recover network-detached containers ([cc04bd6](https://github.com/ngramx/ngramx/commit/cc04bd6144f766326eaf5e22b13e7880e31c6d09))
* **up:** inspect TLS cert and offer to upgrade self-signed to mkcert ([70179ca](https://github.com/ngramx/ngramx/commit/70179ca9703609189b58d08ee0f3290b2a4f55a5))
* **up:** probe docker.app_url after services start, fail loud on 5xx ([ddf831a](https://github.com/ngramx/ngramx/commit/ddf831a31a5b3a9145f84f3d0cf53f6074cc78bd))
* **up:** suggest /etc/hosts line when app_url hostname does not resolve ([fa0241c](https://github.com/ngramx/ngramx/commit/fa0241cf2abc9e2661f89ce757504e30185b954e))
* **visibility:** surface previously-silent boot and compose failures ([b2ef04c](https://github.com/ngramx/ngramx/commit/b2ef04c38dbba5d11e03e7f2b832b0587c59f2fd))


### BREAKING CHANGES

* **init:** Claude files are now saved to ~/.claude/ instead of
.claude/ in the project directory. Existing project-level files will
not be migrated automatically.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>

## [2.21.1](https://github.com/ngramx/ngramx/compare/v2.21.0...v2.21.1) (2026-05-30)


### Bug Fixes

* **agents:** reorder templates so ticket workflow appears last in AGENTS.md ([bbe0a53](https://github.com/ngramx/ngramx/commit/bbe0a5334ec3d5da4375e4f8c3848fd3f33fdb9f))

# [2.21.0](https://github.com/ngramx/ngramx/compare/v2.20.2...v2.21.0) (2026-05-15)


### Bug Fixes

* **up:** probe and "Access at" URLs follow --port-offset / --avoid-conflicts ([fa5759f](https://github.com/ngramx/ngramx/commit/fa5759fc7a544cd37099a76106b3077eb2732f05))


### Features

* **up:** detect and auto-recover network-detached containers ([cc04bd6](https://github.com/ngramx/ngramx/commit/cc04bd6144f766326eaf5e22b13e7880e31c6d09))
* **up:** inspect TLS cert and offer to upgrade self-signed to mkcert ([70179ca](https://github.com/ngramx/ngramx/commit/70179ca9703609189b58d08ee0f3290b2a4f55a5))
* **up:** probe docker.app_url after services start, fail loud on 5xx ([ddf831a](https://github.com/ngramx/ngramx/commit/ddf831a31a5b3a9145f84f3d0cf53f6074cc78bd))
* **visibility:** surface previously-silent boot and compose failures ([b2ef04c](https://github.com/ngramx/ngramx/commit/b2ef04c38dbba5d11e03e7f2b832b0587c59f2fd))

## [2.20.2](https://github.com/ngramx/ngramx/compare/v2.20.1...v2.20.2) (2026-05-10)


### Bug Fixes

* **init-github-actions:** default --php-version to 8.3 (was 8.2) ([399b598](https://github.com/ngramx/ngramx/commit/399b59879febf40d8acb09e990263c56e757f8f9))

## [2.20.1](https://github.com/ngramx/ngramx/compare/v2.20.0...v2.20.1) (2026-05-10)


### Bug Fixes

* **agents:** tidy branch-name rule and strip trailing whitespace in ticket-workflow template ([3deabac](https://github.com/ngramx/ngramx/commit/3deabac9c32e8914bd745a604d3d84198d3064ab))

# [2.20.0](https://github.com/ngramx/ngramx/compare/v2.19.0...v2.20.0) (2026-05-10)


### Features

* **auto-merge:** gate on size:small + risk:low labels classified by agents ([2b5ecdf](https://github.com/ngramx/ngramx/commit/2b5ecdf9aca83af8a7ed77bd8a73e5974bc679c1))

# [2.19.0](https://github.com/ngramx/ngramx/compare/v2.18.0...v2.19.0) (2026-05-10)


### Features

* **agents:** drop parent-ticket / sub-issue workflow from agent instructions ([28dbb8c](https://github.com/ngramx/ngramx/commit/28dbb8c55948d93342dbb3a981b8e4c318d14bbb))

# [2.18.0](https://github.com/ngramx/ngramx/compare/v2.17.0...v2.18.0) (2026-05-10)


### Features

* **init-github-actions:** use GitHub native auto-merge in caller template ([3bc31fb](https://github.com/ngramx/ngramx/commit/3bc31fb8fcd532cc3b0f835c4d1eefbd9d5e2dd0))

# [2.17.0](https://github.com/ngramx/ngramx/compare/v2.16.0...v2.17.0) (2026-05-10)


### Features

* instruct agents to pre-create the parent branch and target it from sub-issue PRs ([64b877f](https://github.com/ngramx/ngramx/commit/64b877fee09c2beb55ec1e3af0093638bf112c7b))

# [2.16.0](https://github.com/ngramx/ngramx/compare/v2.15.0...v2.16.0) (2026-05-10)


### Features

* enumerate AGENTS.md template files with scandir so it works inside a Phar ([6b272a4](https://github.com/ngramx/ngramx/commit/6b272a44f12ef1f7f5c1b2c3407f3b901a673b73))

# [2.15.0](https://github.com/ngramx/ngramx/compare/v2.14.0...v2.15.0) (2026-05-10)


### Features

* add auto-merge workflow and Linear ticket conventions for agent-driven PR routing ([0a07e43](https://github.com/ngramx/ngramx/commit/0a07e4379f6bb1f7b83ba73d169e5cc9074d60ad))

# [2.14.0](https://github.com/ngramx/ngramx/compare/v2.13.0...v2.14.0) (2026-05-10)


### Features

* update AGENTS.md to include guidance for creating linear tickets ([181d4e3](https://github.com/ngramx/ngramx/commit/181d4e3a53aaf1ee9fdf304d43dd675e016baba4))

# [2.13.0](https://github.com/ngramx/ngramx/compare/v2.12.1...v2.13.0) (2026-04-30)


### Bug Fixes

* **etc-hosts-hint:** skip raw IP hostnames (gethostbyname ambiguity) ([735e176](https://github.com/ngramx/ngramx/commit/735e1765b61a3b329ccc851aeeb7a498e71bb8e7))


### Features

* **up:** suggest /etc/hosts line when app_url hostname does not resolve ([fa0241c](https://github.com/ngramx/ngramx/commit/fa0241cf2abc9e2661f89ce757504e30185b954e))

## [2.12.1](https://github.com/ngramx/ngramx/compare/v2.12.0...v2.12.1) (2026-04-30)


### Bug Fixes

* **down:** guard lock read when JSON is corrupt or unreadable ([d671c29](https://github.com/ngramx/ngramx/commit/d671c298a35b42907b9ac6be223c53c65ba05e6c))

# [2.12.0](https://github.com/ngramx/ngramx/compare/v2.11.1...v2.12.0) (2026-04-22)


### Features

* detect crash-looping services during ngramx up ([fce5e90](https://github.com/ngramx/ngramx/commit/fce5e90c3ade3bb5df5d60269508c891df19c58d))

## [2.11.1](https://github.com/ngramx/ngramx/compare/v2.11.0...v2.11.1) (2026-04-19)


### Bug Fixes

* prune stale remotes and fast-forward existing local branches on review checkout ([2a9ad12](https://github.com/ngramx/ngramx/commit/2a9ad120b8d4ad372db5a75bd528cddabe2a37d5))

# [2.11.0](https://github.com/ngramx/ngramx/compare/v2.10.0...v2.11.0) (2026-04-18)


### Features

* added parallel processing for user defined commands ([97c99ec](https://github.com/ngramx/ngramx/commit/97c99ec720d2dccdbbfe41887280a261b039a11e))

# [2.10.0](https://github.com/ngramx/ngramx/compare/v2.9.1...v2.10.0) (2026-04-18)


### Features

* log output on up and rebuild, adjust timeouts, update docs for review command ([27b3c78](https://github.com/ngramx/ngramx/commit/27b3c78486282403da5ba13877d5347ee3ddae7b))

## [2.9.1](https://github.com/ngramx/ngramx/compare/v2.9.0...v2.9.1) (2026-04-16)


### Bug Fixes

* completion file lookup matches partial ticket numbers and drops @ prefix ([930886e](https://github.com/ngramx/ngramx/commit/930886e60be33d71e5543baefcf55f647cc504f6))

# [2.9.0](https://github.com/ngramx/ngramx/compare/v2.8.0...v2.9.0) (2026-04-16)


### Features

* add --quick option to review command to use clear instead of fresh ([b38e4b5](https://github.com/ngramx/ngramx/commit/b38e4b579fc1b9b2751544a8db4c6b2b30236e12))

# [2.8.0](https://github.com/ngramx/ngramx/compare/v2.7.0...v2.8.0) (2026-04-16)


### Bug Fixes

* satisfy PHPStan level 8 in ReviewCommandTest ([b6b89b8](https://github.com/ngramx/ngramx/commit/b6b89b8ac63e248211cb53dd5b1b1012af66a9c0))


### Features

* review command uses ngramx fresh and displays completion URLs ([1be72db](https://github.com/ngramx/ngramx/commit/1be72db1d0349e3238d6bb22d57ca0e093c64e8f))

# [2.7.0](https://github.com/ngramx/ngramx/compare/v2.6.0...v2.7.0) (2026-04-15)


### Features

* simplify README installation docs ([aba28d5](https://github.com/ngramx/ngramx/commit/aba28d5956bfa2a532b4b295793375d72613b401))

# [2.6.0](https://github.com/ngramx/ngramx/compare/v2.5.0...v2.6.0) (2026-04-15)


### Bug Fixes

* add blank line before config warnings in up and rebuild ([ceb055f](https://github.com/ngramx/ngramx/commit/ceb055f3ebad853387f6a5acefbaca4f02d60043))
* move config warnings to appear after config loading in up/rebuild ([4a4230a](https://github.com/ngramx/ngramx/commit/4a4230af34c4597e780483bfc73657eea34e8444))
* satisfy PHPStan in DockerCompose and SetupOrchestrator tests ([84bc5f9](https://github.com/ngramx/ngramx/commit/84bc5f99c5733a7d761268f3d5ce078ec4edec24))
* tighten service status spacing and use purple for all non-error statuses ([07b1b15](https://github.com/ngramx/ngramx/commit/07b1b157ee808ee9ffc9cea8b878b785c6e960e6))


### Features

* improve Docker startup UX with daemon detection, first-run messaging, and live container status ([69e2dbb](https://github.com/ngramx/ngramx/commit/69e2dbb40018c1b47c550268efef18089d6ce4a3))
* shorten recommended command descriptions in config warnings ([88df8d1](https://github.com/ngramx/ngramx/commit/88df8d119b1fefa074b66688caf60186c482a1e6))

# [2.5.0](https://github.com/ngramx/ngramx/compare/v2.4.1...v2.5.0) (2026-04-15)


### Features

* add recommended lifecycle commands (clear, fresh, rebuild) ([71a240e](https://github.com/ngramx/ngramx/commit/71a240e4d5f7b76db350bc21548727eda4f129d9))

## [2.4.1](https://github.com/ngramx/ngramx/compare/v2.4.0...v2.4.1) (2026-03-31)


### Bug Fixes

* increase timeout when rebuilding and allow user to provide timout length ([6f18c82](https://github.com/ngramx/ngramx/commit/6f18c82df5d1c060c4f056f2668f4a1bba2e2e30))

# [2.4.0](https://github.com/ngramx/ngramx/compare/v2.3.0...v2.4.0) (2026-03-31)


### Features

* **up:** add --rebuild option to force Docker image rebuilds ([0cb71b3](https://github.com/ngramx/ngramx/commit/0cb71b35bbfb910b3ebab88afa90c50885b0a31e))

# [2.3.0](https://github.com/ngramx/ngramx/compare/v2.2.0...v2.3.0) (2026-03-25)


### Bug Fixes

* correct PS1 escape sequences for Docker env var passthrough ([ec16ecb](https://github.com/ngramx/ngramx/commit/ec16ecb04b9811a086318a5d4d33eef1cb415880))
* remove unused mock setup that caused PHPStan error ([1d60c14](https://github.com/ngramx/ngramx/commit/1d60c149f54ab70649409bcd77697e14fe8bb3ce))
* remove unused mock setup that caused PHPStan error ([472b188](https://github.com/ngramx/ngramx/commit/472b1885fbbf0aecfc9ec829b9237a08adf05af7))
* use /dev/tty for interactive shell to preserve terminal for readline ([4dcf221](https://github.com/ngramx/ngramx/commit/4dcf22130a8210df4c3a23665690cccdbbf2f1fb))
* use direct bash invocation with Docker env var for TTY support ([2df8b2f](https://github.com/ngramx/ngramx/commit/2df8b2fae82b7610ae76c0e5954f3e3e56be0a79))
* use direct bash invocation with Docker env var for TTY support ([06ef303](https://github.com/ngramx/ngramx/commit/06ef303528235617ac5ed88da8fe40056c15a09d))
* use proc_open with direct FD inheritance for interactive shell ([38d2c4f](https://github.com/ngramx/ngramx/commit/38d2c4f533980af80a85ace8f6e6b404050dd024))


### Features

* add Herd service management, ngramx logs command, and shell TTY fix ([afa0779](https://github.com/ngramx/ngramx/commit/afa0779c16a023d47d18aa2cbb6f91e71b9af548))
* alpha changes by Chris ([d40e5a5](https://github.com/ngramx/ngramx/commit/d40e5a589df1ad208044437a04d2ebcf334bf547))

# [2.3.0-alpha.5](https://github.com/ngramx/ngramx/compare/v2.3.0-alpha.4...v2.3.0-alpha.5) (2026-03-15)


### Bug Fixes

* use /dev/tty for interactive shell to preserve terminal for readline ([4dcf221](https://github.com/ngramx/ngramx/commit/4dcf22130a8210df4c3a23665690cccdbbf2f1fb))

# [2.3.0-alpha.4](https://github.com/ngramx/ngramx/compare/v2.3.0-alpha.3...v2.3.0-alpha.4) (2026-03-15)


### Bug Fixes

* remove unused mock setup that caused PHPStan error ([1d60c14](https://github.com/ngramx/ngramx/commit/1d60c149f54ab70649409bcd77697e14fe8bb3ce))
* use direct bash invocation with Docker env var for TTY support ([06ef303](https://github.com/ngramx/ngramx/commit/06ef303528235617ac5ed88da8fe40056c15a09d))
* use proc_open with direct FD inheritance for interactive shell ([38d2c4f](https://github.com/ngramx/ngramx/commit/38d2c4f533980af80a85ace8f6e6b404050dd024))

# [2.3.0-alpha.3](https://github.com/ngramx/ngramx/compare/v2.3.0-alpha.2...v2.3.0-alpha.3) (2026-03-15)


### Bug Fixes

* remove unused mock setup that caused PHPStan error ([472b188](https://github.com/ngramx/ngramx/commit/472b1885fbbf0aecfc9ec829b9237a08adf05af7))
* use direct bash invocation with Docker env var for TTY support ([2df8b2f](https://github.com/ngramx/ngramx/commit/2df8b2fae82b7610ae76c0e5954f3e3e56be0a79))

# [2.3.0-alpha.2](https://github.com/ngramx/ngramx/compare/v2.3.0-alpha.1...v2.3.0-alpha.2) (2026-03-15)


### Bug Fixes

* correct PS1 escape sequences for Docker env var passthrough ([ec16ecb](https://github.com/ngramx/ngramx/commit/ec16ecb04b9811a086318a5d4d33eef1cb415880))

# [2.3.0-alpha.1](https://github.com/ngramx/ngramx/compare/v2.2.0...v2.3.0-alpha.1) (2026-03-15)


### Features

* add Herd service management, ngramx logs command, and shell TTY fix ([afa0779](https://github.com/ngramx/ngramx/commit/afa0779c16a023d47d18aa2cbb6f91e71b9af548))

# [2.2.0](https://github.com/ngramx/ngramx/compare/v2.1.0...v2.2.0) (2026-03-04)


### Features

* add secrets config ([209ea0d](https://github.com/ngramx/ngramx/commit/209ea0d84101eaf270b23d057a000e5a02ff83da))

# [2.1.0](https://github.com/ngramx/ngramx/compare/v2.0.0...v2.1.0) (2026-03-03)


### Features

* **commands:** add new 'n8n normalise' command ([5f3ba39](https://github.com/ngramx/ngramx/commit/5f3ba399a0922f0042efd795d67c2a508510e800))
* **commands:** add new 'n8n normalise' command ([c28dd61](https://github.com/ngramx/ngramx/commit/c28dd61b3c55fde81e056db70b951f3679b176d4))

# [2.0.0](https://github.com/ngramx/ngramx/compare/v1.13.0...v2.0.0) (2026-01-28)


### Features

* **init:** save Claude files to ~/.claude for user-wide configuration ([8263ea1](https://github.com/ngramx/ngramx/commit/8263ea198db4589bb094f278122fa0d9f625a2c5))


### BREAKING CHANGES

* **init:** Claude files are now saved to ~/.claude/ instead of
.claude/ in the project directory. Existing project-level files will
not be migrated automatically.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>

# [1.13.0](https://github.com/ngramx/ngramx/compare/v1.12.1...v1.13.0) (2026-01-28)


### Features

* show internal Docker URL when using --no-host-mapping with namespace ([adf4b11](https://github.com/ngramx/ngramx/commit/adf4b11db81c9d6214a61cd629b5abd6608a40f5))

## [1.12.1](https://github.com/ngramx/ngramx/compare/v1.12.0...v1.12.1) (2026-01-27)


### Bug Fixes

* use !reset tag instead of !override for empty ports in compose override ([c1a3f6f](https://github.com/ngramx/ngramx/commit/c1a3f6f5302069c91670316c4e51a520a9d792e2))

# [1.12.0](https://github.com/ngramx/ngramx/compare/v1.11.0...v1.12.0) (2026-01-27)


### Bug Fixes

* handle potential null return from preg_replace in ComposeOverrideGenerator ([9c14bd6](https://github.com/ngramx/ngramx/commit/9c14bd6bc1da75b832cb051d01eaa0757fea601a))


### Features

* add --no-host-mapping flag to ngramx up command ([b7613e0](https://github.com/ngramx/ngramx/commit/b7613e0e02342c58b517a75b64c1bbfb40bf6971))

# [1.11.0](https://github.com/ngramx/ngramx/compare/v1.10.0...v1.11.0) (2026-01-13)


### Features

* n8n import command ([e7962ae](https://github.com/ngramx/ngramx/commit/e7962aee7415d591159d5a9598c4f302ff9e06c4))

# [1.10.0](https://github.com/ngramx/ngramx/compare/v1.9.0...v1.10.0) (2026-01-08)


### Bug Fixes

* tests on 8.3 ([715c117](https://github.com/ngramx/ngramx/commit/715c11759b032924e1ad6aba6f310209f3346fbf))


### Features

* Added ngramx show-url to show the url for the applicaton running in the development environment ([16235ac](https://github.com/ngramx/ngramx/commit/16235acd9238da51376ccbaa3d5c26cb78165e1f))

# [1.9.0](https://github.com/ngramx/ngramx/compare/v1.8.0...v1.9.0) (2026-01-07)


### Features

* added global coding instructions to CLAUDE.md on ngramx init ([e62ed05](https://github.com/ngramx/ngramx/commit/e62ed0526df8e5e2c590e46c7acfaf3121a01c3b))

# [1.8.0](https://github.com/ngramx/ngramx/compare/v1.7.0...v1.8.0) (2026-01-07)


### Features

* ngramx init now creates a ngramx.md claude rule file ([da5aba6](https://github.com/ngramx/ngramx/commit/da5aba6b461d6d116ae257d910029a86677043b7))

# [1.7.0](https://github.com/ngramx/ngramx/compare/v1.6.2...v1.7.0) (2025-12-08)


### Features

* **commands:** add new 'review' command ([403aa81](https://github.com/ngramx/ngramx/commit/403aa818b9e94ef6103af4271769ffbef9035661))

## [1.6.2](https://github.com/ngramx/ngramx/compare/v1.6.1...v1.6.2) (2025-11-18)


### Bug Fixes

* change default timeout on user commands ([8ef7db7](https://github.com/ngramx/ngramx/commit/8ef7db7f104b0cbbfdfaf63f152ed481810ace2d))

## [1.6.1](https://github.com/ngramx/ngramx/compare/v1.6.0...v1.6.1) (2025-11-08)


### Bug Fixes

* fix phpstan errors ([6ac7724](https://github.com/ngramx/ngramx/commit/6ac77243e1acfb66d0d2f8d15a7af11fccff95e6))
* use docker to scan ports becausse we were chec king ports inside the container than the ngramx command was running, but we're using docker socket to create the containers on the host ([2467a44](https://github.com/ngramx/ngramx/commit/2467a44a429b9487c8cf272955665ae1431eee28))

# [1.6.0](https://github.com/ngramx/ngramx/compare/v1.5.6...v1.6.0) (2025-11-08)


### Features

* deploy Cortex Coder on a new release ([481bc95](https://github.com/ngramx/ngramx/commit/481bc950d13694bbb0907aa93d7b2269c7c24e90))

## [1.5.6](https://github.com/ngramx/ngramx/compare/v1.5.5...v1.5.6) (2025-11-08)


### Bug Fixes

* check if we can bind to a port not just if something is listening when checking for port conflicts ([4ba9a27](https://github.com/ngramx/ngramx/commit/4ba9a274b82117b55c3b1400050745ef1c84af2a))

## [1.5.5](https://github.com/ngramx/ngramx/compare/v1.5.4...v1.5.5) (2025-11-08)


### Bug Fixes

* container cleanup ([40e4984](https://github.com/ngramx/ngramx/commit/40e49844730a25953ccbb39da9b9d2eab33fad0c))

## [1.5.4](https://github.com/ngramx/ngramx/compare/v1.5.3...v1.5.4) (2025-11-08)


### Bug Fixes

* cleanup stale containers ([f060f86](https://github.com/ngramx/ngramx/commit/f060f86b04ddaa79e5a4c0bf16aa406ff0bfc6c8))

## [1.5.3](https://github.com/ngramx/ngramx/compare/v1.5.2...v1.5.3) (2025-11-08)


### Bug Fixes

* port offset ([c84f736](https://github.com/ngramx/ngramx/commit/c84f73636fda4395ec77eaf59857bcf1cf6477d6))

## [1.5.2](https://github.com/ngramx/ngramx/compare/v1.5.1...v1.5.2) (2025-11-08)


### Bug Fixes

* container namespacing ([5b045e0](https://github.com/ngramx/ngramx/commit/5b045e037cb81f1477d9d69a80b763442da00491))

## [1.5.1](https://github.com/ngramx/ngramx/compare/v1.5.0...v1.5.1) (2025-11-08)


### Bug Fixes

* container namespacing wasn't working correctly ([e6b00b1](https://github.com/ngramx/ngramx/commit/e6b00b1cd8792e9d90d7135c753bf7b06e0572a7))

# [1.5.0](https://github.com/ngramx/ngramx/compare/v1.4.0...v1.5.0) (2025-11-08)


### Bug Fixes

* fix phpstan tests ([591a14f](https://github.com/ngramx/ngramx/commit/591a14f50f09ff3deb0c41ecc45e50868b9eaa53))


### Features

* added --avoid-conflicts and other options for namspacing containers and avoiding port conflicts ([ee0a894](https://github.com/ngramx/ngramx/commit/ee0a894d2ecdda95deb2faff2319d60c64c6a254))

# [1.4.0](https://github.com/ngramx/ngramx/compare/v1.3.0...v1.4.0) (2025-11-08)


### Features

* Added app_url option to specify how to access the app after ngramx up ([af01d60](https://github.com/ngramx/ngramx/commit/af01d6052241acf1fe1f98c81adefb03e2add224))
* Added app_url option to specify how to access the app after cotext up ([cd51c30](https://github.com/ngramx/ngramx/commit/cd51c3089619d6f8687fb976623284cdd6d8427e))

# [1.3.0](https://github.com/ngramx/ngramx/compare/v1.2.2...v1.3.0) (2025-11-06)


### Bug Fixes

* fix unit tests for shellf command ([1303be1](https://github.com/ngramx/ngramx/commit/1303be10f07498283862adec20cc1869416270b5))


### Features

* Add ngramx shell command ([02eadb9](https://github.com/ngramx/ngramx/commit/02eadb93e5e97b896879ac15dcc2bcb44e6c935d))
* Implement ngramx shell subcommand ([3527cdd](https://github.com/ngramx/ngramx/commit/3527cdd9414cc42ae789f91abb626758046e8647))

## [1.2.2](https://github.com/ngramx/ngramx/compare/v1.2.1...v1.2.2) (2025-11-06)


### Bug Fixes

* make sure .gitkeep is added to .ngramx/ folders ([157010f](https://github.com/ngramx/ngramx/commit/157010f5f1a97ed0923371de89917c4c63facad6))

## [1.2.1](https://github.com/ngramx/ngramx/compare/v1.2.0...v1.2.1) (2025-11-06)

# [1.2.0](https://github.com/ngramx/ngramx/compare/v1.1.1...v1.2.0) (2025-11-06)


### Features

* Add init command for project setup ([a0ce800](https://github.com/ngramx/ngramx/commit/a0ce8007dab73b48387ec8ff9848732fbb7dc3bd))

## [1.1.1](https://github.com/ngramx/ngramx/compare/v1.1.0...v1.1.1) (2025-11-06)

# [1.1.0](https://github.com/ngramx/ngramx/compare/v1.0.6...v1.1.0) (2025-11-06)


### Bug Fixes

* **ci:** add package-lock.json for reproducible builds ([b12557f](https://github.com/ngramx/ngramx/commit/b12557f9a34d77fed0bfba5f847201dc9d0a3cdd))


### Features

* fully automated releases with semantic-release ([d9704f3](https://github.com/ngramx/ngramx/commit/d9704f3da61ce1df103218cc35c9ade71521b058))
