## Shared Step: Code

Implement the feature code following the approved plan.

### Purpose

Write the production code that implements the feature:

* Follow the detailed plan from the Planning step
* Make the tests from the Tests step pass
* Deliver working, tested functionality

### Prerequisites

Before this step:
* Specs approved
* Approach selected
* Detailed plan approved
* Tests written and reviewed

### What To Do

1. **Review the Plan**
   * Re-read `.ngramx/tickets/[ticket-id]/plan.md`
   * Understand the class structure and contracts
   * Note any pseudocode for complex logic

2. **Implement in Order**
   * Start with foundational classes (models, interfaces)
   * Build up to services and business logic
   * Add controllers/entry points last
   * Follow the dependency order from the plan

3. **Run Tests Frequently**
   * Run tests after implementing each component
   * Fix failures before moving on
   * Use tests to guide implementation

4. **Follow Project Conventions**
   * Match existing code style
   * Use established patterns from the codebase
   * Follow naming conventions from the plan

### Implementation Workflow

```
For each class in the plan:
    1. Create the file
    2. Implement the interface/contract
    3. Write the method bodies
    4. Run related tests
    5. Refactor if needed
    6. Commit when tests pass
```

### Code Quality Checklist

Before considering implementation complete:

- [ ] All tests pass
- [ ] No linting errors
- [ ] Code follows project style guide
- [ ] Complex logic matches pseudocode from plan
- [ ] Dependencies properly injected
- [ ] Error handling in place
- [ ] No debug code left behind

### Handling Deviations

If during implementation you discover:

**The plan needs adjustment:**
* Small adjustments: Note them and continue
* Significant changes: Pause and discuss with user

**Tests need updating:**
* Bug in test: Fix and note
* Missing scenario: Add test first, then implement

**New requirements emerge:**
* Out of scope: Note for future ticket
* Critical: Discuss with user before proceeding

### Commit Strategy

Make atomic commits as you progress:

```bash
# After completing each logical unit
git add -A
git commit -m "feat(CORE-123): Implement InvoiceService"

# Keep commits focused and descriptive
git commit -m "feat(CORE-123): Add invoice validation logic"
git commit -m "feat(CORE-123): Wire up invoice endpoints"
```

### Update Plan with Notes

As you implement, update `plan.md` with any deviations or learnings:

```markdown
## Implementation Notes

### Deviations from Plan
- Changed `calculateTotal()` to accept optional discount parameter
- Added `InvoiceValidator` class (not in original plan) for cleaner separation

### Technical Debt
- TODO: Optimize query in `InvoiceRepository::findByDateRange()`

### Learnings
- Existing `NotificationService` already handles queuing, reused that
```

### Final Verification

Before completing this step:

1. **Run full test suite** - All tests should pass
2. **Manual smoke test** - Verify the feature works as expected
3. **Review the diff** - Check all changes make sense
4. **Clean up** - Remove any temporary code or comments

### Approval Checkpoint

**STOP HERE and wait for user approval.**

Present the completed implementation:

* Summary of what was built
* Any deviations from the plan
* Test results
* Demo the feature if applicable

The user may:
* Approve and mark the ticket complete
* Request changes or refinements
* Identify issues to address

Do not consider the ticket complete until the user gives final approval.

