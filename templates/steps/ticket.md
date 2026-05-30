## Shared Step: Ticket

### Structure

```
.ngramx/
└── tickets/
    └── CORE-123/
        ├── README.md      # Human-readable overview
        ├── ticket.json    # Machine-readable Linear issue data - optional because this may not be a linear issue
        ├── plan.md        # Implementation plan, blank to begin with
        ├── specs.md       # Links to related feature specs, blank to begin with
        └── assets/        # Screenshots, mockups, diagrams - save anything non-text based the user gives you in here
```

### File Descriptions

`README.md` - The starting point for understanding this ticket. Contains:

* Ticket title and description (if it's not a linear ticket then make these up from the user's brief)
* Links to relevant meetings where this was discussed (ignore this for now)
* Related and dependent tickets (ignore this for now)

`ticket.json` - Raw Linear issue data in JSON format for AI agent consumption. Ignore if we're not working on linear issue.

`plan.md` - The implementation plan including (DON'T fill this out yet - the task instructions will tell you when to do this):

* Architecture decisions
* Pseudocode and approach
* Files to be created/modified
* Class structures and contracts
* Technical notes

*Note: This file is updated in place. Git history tracks all iterations.*

`specs.md` - References to feature specifications (DON'T fill this out yet - the task instructions will tell you when to do this):

* Which `.feature` files this ticket creates
* Which `.feature` files this ticket modifies
* Links to specific scenarios

*Note: Not all tickets have specs. Infrastructure work often doesn't need Cucumber specs.*

`assets/` - Visual materials:

* UI mockups
* Diagrams and flowcharts
* Screenshots
* Reference images

You should save any assets the user gives you in here.

### Creating a New Ticket Folder

When starting work on a new ticket:

1. Create folder: `.ngramx/tickets/[ticket-id]/`
2. Create `README.md` with ticket context
3. Create `ticket.json` with Linear issue data (ignore if not working on linear issue)
4. Create a `plan.md`, blank for now
5. Create a `specs.md`, blank for now
6. Add any reference materials to `assets/`