# .cortex Folder

This folder contains all contextual documentation and specifications for the project. It serves as the knowledge base for both human developers and AI coding agents.

## Core Principle

**Context and documentation lives here. Code lives in the main project.**

Everything in `.cortex/` is about understanding *what* we're building and *why*. The actual implementation code lives in the main source tree.

## Folder Structure

```
.cortex/
├── tickets/          # Per-ticket context and planning
├── specs/            # Cucumber/Gherkin feature specifications
├── meetings/         # Meeting notes and discussions
└── README.md         # This file
```

## Tickets Folder

Each Linear ticket gets its own subfolder. This is where all planning and context for that specific piece of work lives.

### Structure

```
tickets/
└── CORE-123/
    ├── README.md      # Human-readable overview
    ├── ticket.json    # Machine-readable Linear data
    ├── plan.md        # Implementation plan
    ├── specs.md       # Links to related feature specs
    └── assets/        # Screenshots, mockups, diagrams
```

### File Descriptions

`README.md` - The starting point for understanding this ticket. Contains:

* Ticket title and description
* Links to relevant meetings where this was discussed
* Related and dependent tickets
* Client, assignee, and status
* Links to specs and assets

`ticket.json` - Raw Linear ticket data in JSON format for AI agent consumption. Updated when ticket is pulled from Linear.

`plan.md` - The implementation plan including:

* Architecture decisions
* Pseudocode and approach
* Files to be created/modified
* Class structures and contracts
* Technical notes

*Note: This file is updated in place. Git history tracks all iterations.*

`specs.md` - References to feature specifications:

* Which `.feature` files this ticket creates
* Which `.feature` files this ticket modifies
* Links to specific scenarios

*Note: Not all tickets have specs. Infrastructure work often doesn't need Cucumber specs.*

`assets/` - Visual materials:

* UI mockups
* Diagrams and flowcharts
* Screenshots
* Reference images

### Creating a New Ticket Folder

When starting work on a new ticket:

1. Create folder: `tickets/CORE-XXX/`
2. Create `README.md` with ticket context
3. Create `ticket.json` with Linear data
4. Add any reference materials to `assets/`

The agent will create `specs.md` and `plan.md` as part of the workflow.

## Specs Folder

Cucumber/Gherkin feature specifications organized by product feature (not by ticket).

### Structure

```
specs/
├── invoice-creation.feature
├── invoice-editing.feature
├── user-authentication.feature
└── shared/
    ├── authentication-steps.feature
    └── common-ui-behaviors.feature
```

### Organization Principles

**Feature-Centric, Not Ticket-Centric**

* Feature files represent product capabilities
* Multiple tickets can contribute to the same feature over time
* Features evolve as the product grows

**Example:**

* `CORE-123` creates `invoice-creation.feature` with basic scenarios
* `CORE-200` adds recurring invoice scenarios to the same file
* `CORE-305` adds invoice template scenarios to the same file

### Naming Conventions

* Use descriptive feature names: `invoice-creation.feature`
* Reflect user-facing capabilities
* Keep names stable (don't rename when tickets change)
* Use lowercase with hyphens

### Shared Subfolder

The `shared/` folder contains reusable step definitions that appear across multiple features:

* Common authentication flows
* Standard UI behaviors
* API response patterns
* Validation rules

### Integration with Tests

Your test framework (Behat/Cucumber) configuration should:

* Read feature files from: `.cortex/specs/**/*.feature`
* Execute step definitions from: `tests/features/**/*Context.php`

The specs here define *what* to test. The code in `tests/` defines *how* to test it.

## Meetings Folder

Meeting notes organized chronologically by year-month.

### Structure

```
meetings/
├── 2025-10/
│   ├── 15-daily-standup.md
│   ├── 20-future-group-planning.md
│   └── 25-sprint-planning.md
└── 2025-09/
    └── ...
```

### Naming Convention

Format: `DD-brief-description.md`

Examples:

* `15-daily-standup.md`
* `20-future-group-planning.md`
* `22-security-review.md`


## Workflow: How the Agent Uses This Structure

When working on a ticket, the AI agent follows this process:

### 1\. Setup Phase

* Read ticket from Linear
* Create `tickets/CORE-XXX/` folder
* Create `README.md` and `ticket.json`
* Gather any assets provided

### 2\. Specification Phase

* Write `specs.md` documenting which features will be created/modified
* Write/update `.feature` files in `specs/`
* **⏸️ Human approval required**

### 3\. Planning Phase

* Write `plan.md` with implementation approach
* Include architecture decisions and pseudocode
* **⏸️ Human approval required**

### 4\. Test Implementation Phase

* Write actual test code in `tests/features/` (main project)
* Implement step definitions for specs
* **⏸️ Human approval required**

### 5\. Implementation Phase

* Write feature code in main project
* Run tests and iterate
* Commit when tests pass

## Best Practices

### For Humans

**When creating tickets:**

* Always create a ticket folder before starting work
* Link to relevant meetings in the README
* Add mockups and diagrams to `assets/` early

**When holding meetings:**

* Take notes in the `meetings/` folder
* Update `index.json` with tickets discussed
* Reference meeting notes in ticket READMEs

**When reviewing specs:**

* Check that specs match product requirements
* Verify scenarios cover edge cases
* Ensure specs are readable by non-technical stakeholders

**When reviewing plans:**

* Verify approach aligns with architecture
* Check for potential issues or gaps
* Confirm file changes make sense

### For AI Agents

**Context loading:**

* Always read the ticket README first
* Load referenced meeting notes for context
* Check `specs.md` to understand feature scope
* Review related tickets mentioned in README

**File updates:**

* Update `plan.md` in place (don't create versions)
* Keep `specs.md` updated as features evolve
* Refresh `ticket.json` when ticket status changes

**Asset handling:**

* Save user-provided images to `tickets/CORE-XXX/assets/`
* Use descriptive filenames
* Reference assets in README

## Maintenance

### Regular Tasks

**Weekly:**

* Ensure active tickets have up-to-date context
* Archive completed ticket folders if needed

**Monthly:**

* Update meeting index
* Clean up old assets if necessary

**Per Ticket:**

* Create ticket folder when work begins
* Update README as context evolves
* Keep specs.md in sync with feature files

### Archiving

Completed tickets should remain in the `tickets/` folder for historical reference. They provide valuable context for understanding why decisions were made.

If you need to archive old tickets:

1. Create `tickets/archived/` folder
2. Move old ticket folders there
3. Update any cross-references

## Integration with Main Project

The `.cortex/` folder complements your main project structure:

```
project-root/
├── .cortex/              # This folder - all context
├── cortex.yml            # Environment configuration
├── src/                  # Application code
├── tests/
│   ├── features/         # Test implementations
│   └── unit/             # Unit tests
└── composer.json
```

## Tools and Commands

You can build CLI tools to work with this structure:

```
# List active tickets
cortex tickets --status=active

# Show ticket summary
cortex ticket CORE-123

# List all specs
cortex specs --list

# Find meetings about a ticket
cortex meetings --ticket=CORE-123
```

*(These are examples of potential commands, not currently implemented)*

## Questions?

If you're unsure about:

* Where something should go → Use the core principle: context here, code in main project
* How to name something → Look at existing examples in the folder
* Whether to create a new file → When in doubt, create it. Context is valuable.

## Version

Structure version: 1.0
Last updated: 2025-11-06
