## Shared Step: Specs

Write Cucumber-style feature specifications based on the user's requirements.

### Purpose

Transform the user's brief/prompt into formal Gherkin feature specifications that define the expected behavior. These specs serve as:

* A contract between stakeholder and developer
* Living documentation of the feature
* The basis for test implementation

### What To Do

1. **Analyze the Requirements**
   * Review the ticket README and any assets
   * Identify the user-facing behaviors being requested
   * Note edge cases and error scenarios

2. **Write Feature Files**
   * Create `.feature` files in `.ngramx/specs/`
   * Use Gherkin syntax (Given/When/Then)
   * Organize by product feature, not by ticket
   * Include scenarios for happy path, edge cases, and error handling

3. **Update specs.md**
   * Update `.ngramx/tickets/[ticket-id]/specs.md` with:
     * List of feature files created or modified
     * Links to specific scenarios
     * Brief description of what each covers

### File Locations

```
.ngramx/
├── specs/
│   ├── [feature-name].feature      # New or updated feature files
│   └── shared/                     # Reusable step definitions
└── tickets/
    └── [ticket-id]/
        └── specs.md                # Links to the feature files
```

### Gherkin Guidelines

**Feature File Structure:**

```gherkin
Feature: [Feature Name]
  As a [role]
  I want [capability]
  So that [benefit]

  Background:
    Given [common preconditions]

  Scenario: [Happy path description]
    Given [context]
    When [action]
    Then [expected outcome]

  Scenario: [Edge case description]
    Given [context]
    When [action]
    Then [expected outcome]
```

**Best Practices:**

* Write from the user's perspective
* Keep scenarios independent and atomic
* Use declarative language (what, not how)
* Avoid implementation details in specs
* Reuse step definitions where possible

### Example specs.md Content

```markdown
# Specs for CORE-123

## Feature Files

### Created
- `invoice-creation.feature` - Core invoice creation flow
  - Scenario: Create invoice with valid data
  - Scenario: Create invoice with line items
  - Scenario: Reject invoice with missing required fields

### Modified
- `user-notifications.feature` - Added invoice notification scenarios
  - Scenario: Send notification when invoice created
```

### Approval Checkpoint

**STOP HERE and wait for user approval.**

Present the specs to the user and ask them to review:

* Do the scenarios cover all requirements?
* Are there missing edge cases?
* Is the language clear and accurate?
* Any scenarios that should be added or removed?

Do not proceed to the next step until the user approves the specs.

